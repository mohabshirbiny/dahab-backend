<?php

use App\Enums\CustomerStatus;
use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Enums\SuspendedReason;
use App\Jobs\NotifyCustomerJob;
use App\Models\AgreementAcceptance;
use App\Models\AuditLog;
use App\Models\OrderCollection;
use App\Models\Staff;
use App\Notifications\ProxyNamedNotification;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 US3, FR-018–FR-021, research R14; Part 2 §7: the buyer names someone
// else to collect; the counter checks their ID; one SMS without the code.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Disputes::readyToCollect($this);
});

function proxyCollectionOf($order): OrderCollection
{
    return DatabaseActor::elevate('maintenance', fn () => OrderCollection::query()->where('order_id', $order->order_id)->sole());
}

/** The buyer's collection code, read past row security. */
function proxyCodeOf($order): string
{
    return proxyCollectionOf($order)->code_encrypted;
}

it('names a proxy with the authorisation, texts them once without the code, and tells the buyer', function () {
    Bus::fake([NotifyCustomerJob::class]);
    Disputes::nameProxy($this, $this->order)->assertOk()
        ->assertJsonPath('data.proxy.name', 'Ahmed Samir Hassan')
        ->assertJsonPath('data.proxy.phone_masked', '+201••••••567');

    $c = proxyCollectionOf($this->order);
    $acceptance = DatabaseActor::elevate('maintenance', fn () => AgreementAcceptance::query()->findOrFail($c->proxy_acceptance_id));
    expect($c->is_proxy)->toBeTrue()
        ->and($c->proxy_phone)->toBe('+201001234567')
        ->and($c->proxy_id_storage_ref)->not->toBeNull()
        ->and($c->proxy_named_at)->not->toBeNull()
        ->and($acceptance->context)->toBe('collection_proxy')
        ->and($acceptance->customer_id)->toBe($this->order->buyer_id)
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'order.proxy_named')->sole()->actor_customer_id))->toBe($this->order->buyer_id);

    $code = proxyCodeOf($this->order);
    Notification::assertSentOnDemand(ProxyNamedNotification::class, function ($n, $channels, AnonymousNotifiable $to) use ($code) {
        return $to->routes['sms'] === '+201001234567' && ! str_contains($n->body(), $code) && (str_contains($n->body(), 'Bring your ID') || str_contains($n->body(), 'هات بطاقتك'));
    });
    Notification::assertSentOnDemandTimes(ProxyNamedNotification::class, 1);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $this->order->buyer_id && $j->notification->event === OrderEvent::PROXY_NAMED);

    // The seller never sees any of it.
    $seller = Orders::show($this, Orders::seller($this->order), $this->order)->assertOk()->assertJsonPath('data.proxy', null);
    expect(json_encode($seller->json()))->not->toContain('Ahmed')->not->toContain('201001234567');
});

it('replaces the proxy with a new acceptance and removes it', function () {
    Disputes::nameProxy($this, $this->order)->assertOk();
    Disputes::nameProxy($this, $this->order, ['name' => 'Mona Ali', 'phone' => '+20 100 999 8888'])->assertOk()
        ->assertJsonPath('data.proxy.name', 'Mona Ali');
    expect(proxyCollectionOf($this->order)->proxy_phone)->toBe('+201009998888')
        ->and(DatabaseActor::elevate('maintenance', fn () => AgreementAcceptance::query()->where('context', 'collection_proxy')->count()))->toBe(2);

    Listings::as($this, Orders::buyer($this->order))->postJson(Orders::CUSTOMER_URL."/{$this->order->order_id}/proxy/remove", [], Listings::key())
        ->assertOk()->assertJsonPath('data.proxy', null);
    expect(proxyCollectionOf($this->order)->is_proxy)->toBeFalse();
    Listings::as($this, Orders::buyer($this->order))->postJson(Orders::CUSTOMER_URL."/{$this->order->order_id}/proxy/remove", [], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');
});

it('validates the request', function () {
    Disputes::nameProxy($this, $this->order, ['authorisation_accepted' => false])->assertStatus(422)->assertJsonValidationErrors('authorisation_accepted');
    Disputes::nameProxy($this, $this->order, ['authorisation_id' => 999999])->assertStatus(422)->assertJsonPath('code', 'declaration_required');
    Disputes::nameProxy($this, $this->order, ['name' => 'A'])->assertStatus(422)->assertJsonValidationErrors('name');
    Disputes::nameProxy($this, $this->order, ['phone' => '0100123'])->assertStatus(422)->assertJsonValidationErrors('phone');
    Disputes::nameProxy($this, $this->order, ['id_upload_token' => Disputes::photoToken($this, Orders::buyer($this->order))])
        ->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');

    expect(proxyCollectionOf($this->order)->is_proxy)->toBeFalse();
});

it('refuses before payment, after collection, for the seller and for a suspended buyer', function () {
    $other = Disputes::awaitingBalance($this);
    Disputes::nameProxy($this, $other)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');

    Listings::as($this, Orders::seller($this->order))->postJson(Orders::CUSTOMER_URL."/{$this->order->order_id}/proxy",
        ['name' => 'X Y', 'phone' => '+201001234567', 'id_upload_token' => 'x', 'authorisation_id' => Disputes::proxyAuthorisationId(), 'authorisation_accepted' => true],
        Listings::key())->assertNotFound();

    $buyer = Orders::buyer($this->order);
    $token = Listings::uploadToken($this, $buyer, 'proxy_id');
    DatabaseActor::elevate('maintenance', function () use ($buyer) {
        $buyer->suspend(SuspendedReason::OTHER, 'Testing a suspended buyer.', Staff::query()->findOrFail(SystemActor::id()));
        $buyer->save();
    });
    expect($buyer->refresh()->status)->toBe(CustomerStatus::SUSPENDED);
    Disputes::nameProxy($this, $this->order, ['id_upload_token' => $token])->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('hands over to the proxy only with the code and the ID check, without counting a missing check', function () {
    Disputes::nameProxy($this, $this->order)->assertOk();
    $code = proxyCodeOf($this->order);
    $igi = Orders::staff($this, SeedRole::IGI_BRANCH);
    $url = Orders::STAFF_URL."/{$this->order->order_id}/handover";

    $this->postJson($url, ['code' => $code, 'collector' => 'proxy'], Listings::key())
        ->assertStatus(422)->assertJsonPath('code', 'proxy_details_missing');
    $this->postJson($url, ['code' => $code, 'collector' => 'proxy', 'proxy_id_checked' => false], Listings::key())
        ->assertStatus(422)->assertJsonPath('code', 'proxy_details_missing');
    expect(proxyCollectionOf($this->order)->failed_attempts)->toBe(0);

    // The ID photo, for the check.
    $res = $this->get(Orders::STAFF_URL."/{$this->order->order_id}/proxy-id")->assertOk();
    expect($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'order.proxy_id_viewed')->count()))->toBe(1);

    $this->postJson($url, ['code' => $code, 'collector' => 'proxy', 'proxy_id_checked' => true], Listings::key())->assertOk();
    $c = proxyCollectionOf($this->order);
    expect($this->order->refresh()->state->value)->toBe('completed')
        ->and($c->collected_by_proxy)->toBeTrue()
        ->and($c->proxy_id_checked_by)->toBe($igi->staff_id)
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'order.handed_over')->sole()->after_json['collector']))->toBe('proxy');

    // The proxy cannot be changed once collected.
    expect(fn () => DatabaseActor::elevate('maintenance', fn () => DB::table('collection')->where('order_id', $this->order->order_id)->update(['proxy_name' => 'Someone Else'])))
        ->toThrow(QueryException::class);
});

it('still lets the buyer collect in person while a proxy is named, and keeps the spec 012 body working', function () {
    Disputes::nameProxy($this, $this->order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH);

    $this->postJson(Orders::STAFF_URL."/{$this->order->order_id}/handover", ['code' => proxyCodeOf($this->order)], Listings::key())->assertOk();
    expect(proxyCollectionOf($this->order)->collected_by_proxy)->toBeFalse();
});

it('refuses collector = proxy when none is named, and the ID photo when none exists', function () {
    Orders::staff($this, SeedRole::IGI_BRANCH);
    $this->postJson(Orders::STAFF_URL."/{$this->order->order_id}/handover",
        ['code' => proxyCodeOf($this->order), 'collector' => 'proxy', 'proxy_id_checked' => true], Listings::key())
        ->assertStatus(422)->assertJsonPath('code', 'proxy_details_missing');
    $this->get(Orders::STAFF_URL."/{$this->order->order_id}/proxy-id")->assertNotFound();

    Orders::staff($this, SeedRole::VERIFICATION);
    $this->get(Orders::STAFF_URL."/{$this->order->order_id}/proxy-id")->assertForbidden();
});
