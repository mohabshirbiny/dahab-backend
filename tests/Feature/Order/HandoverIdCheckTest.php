<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\OrderCollection;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// docs/features/handover-id-check.md: the counter sees the buyer's own verified ID when the buyer
// collects in person, and the proxy's ID is uploaded like the sign-up one (kind, front, back).

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Disputes::readyToCollect($this);
});

function handoverRow($test, string $orderId): array
{
    return collect($test->getJson(Orders::WORK_LIST_URL)->assertOk()->json('data'))->firstWhere('order_id', $orderId);
}

function handoverAudits(string $action): int
{
    return DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', $action)->count());
}

it("shows the counter the buyer's verified ID, both sides, logged and audited", function () {
    $document = IdentityDocument::factory()->for(Orders::buyer($this->order))->egyptianId()->verified()->withImage('buyer-id-bytes')->create();
    Orders::staff($this, SeedRole::IGI_BRANCH, $this->order->branch_id);

    expect(handoverRow($this, $this->order->order_id)['buyer_id'])->toBe(['doc_kind' => 'egyptian_id', 'has_back' => true]);

    $url = Orders::STAFF_URL."/{$this->order->order_id}/buyer-id";
    $front = $this->get($url)->assertOk();
    expect($front->getContent())->toBe('buyer-id-bytes')
        ->and($front->headers->get('Cache-Control'))->toContain('no-store');
    $this->get($url.'?side=back')->assertOk();
    $this->get($url.'?side=sideways')->assertStatus(422);

    expect(handoverAudits('order.buyer_id_viewed'))->toBe(2)
        ->and(handoverAudits('identity.document.viewed'))->toBe(2)
        ->and(DatabaseActor::elevate('maintenance', fn () => DocumentViewLog::query()->where('document_id', $document->document_id)->count()))->toBe(2);
});

it("offers no buyer ID without a verified document, for a passport's back, or outside the handover", function () {
    $buyer = Orders::buyer($this->order);
    IdentityDocument::factory()->for($buyer)->pending()->withImage()->create();
    Orders::staff($this, SeedRole::IGI_BRANCH, $this->order->branch_id);
    $url = Orders::STAFF_URL."/{$this->order->order_id}/buyer-id";

    expect(handoverRow($this, $this->order->order_id)['buyer_id'])->toBeNull();
    $this->get($url)->assertNotFound();

    IdentityDocument::factory()->for($buyer)->passport()->verified()->withImage()->create();
    expect(handoverRow($this, $this->order->order_id)['buyer_id'])->toBe(['doc_kind' => 'passport', 'has_back' => false]);
    $this->get($url)->assertOk();
    $this->get($url.'?side=back')->assertNotFound();

    // Another order's buyer, before payment: nothing to check yet.
    $early = Disputes::awaitingBalance($this);
    IdentityDocument::factory()->for(Orders::buyer($early))->verified()->withImage()->create();
    $this->get(Orders::STAFF_URL."/{$early->order_id}/buyer-id")->assertNotFound();
});

it('gives the order detail the buyer ID and the can flag', function () {
    IdentityDocument::factory()->for(Orders::buyer($this->order))->verified()->withImage()->create();
    Orders::staff($this, SeedRole::OPERATIONS);

    $this->getJson(Orders::STAFF_URL."/{$this->order->order_id}")->assertOk()
        ->assertJsonPath('data.collection.buyer_id', ['doc_kind' => 'egyptian_id', 'has_back' => true])
        ->assertJsonPath('data.can.view_buyer_id', true);
});

it("takes the proxy's ID like sign-up: an Egyptian ID needs its back, a passport does not", function () {
    Disputes::nameProxy($this, $this->order, ['id_back_upload_token' => null])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    Disputes::nameProxy($this, $this->order, ['id_kind' => 'licence'])->assertStatus(422);

    Disputes::nameProxy($this, $this->order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH, $this->order->branch_id);
    expect(handoverRow($this, $this->order->order_id)['proxy'])->toMatchArray(['id_kind' => 'egyptian_id', 'has_back' => true]);
    $url = Orders::STAFF_URL."/{$this->order->order_id}/proxy-id";
    $this->get($url)->assertOk();
    $this->get($url.'?side=back')->assertOk();

    Disputes::nameProxy($this, $this->order, ['id_kind' => 'passport', 'id_back_upload_token' => null])->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH, $this->order->branch_id);
    expect(handoverRow($this, $this->order->order_id)['proxy'])->toMatchArray(['id_kind' => 'passport', 'has_back' => false]);
    $this->get($url.'?side=back')->assertNotFound();
});

it('clears both sides on removal, and keeps an Egyptian ID with its back in the database', function () {
    $collection = fn () => DatabaseActor::elevate('maintenance', fn () => OrderCollection::query()->where('order_id', $this->order->order_id)->sole());
    Disputes::nameProxy($this, $this->order)->assertOk();
    expect($collection()->proxy_id_back_storage_ref)->not->toBeNull();

    Listings::as($this, Orders::buyer($this->order))
        ->postJson(Orders::CUSTOMER_URL."/{$this->order->order_id}/proxy/remove", [], Listings::key())->assertOk();
    expect($collection()->proxy_id_kind)->toBeNull()
        ->and($collection()->proxy_id_back_storage_ref)->toBeNull();

    // Last: the failed statement aborts the test transaction.
    Disputes::nameProxy($this, $this->order)->assertOk();
    expect(fn () => DatabaseActor::elevate('maintenance', fn () => DB::table('collection')->where('order_id', $this->order->order_id)
        ->update(['proxy_id_back_storage_ref' => null])))->toThrow(QueryException::class);
});
