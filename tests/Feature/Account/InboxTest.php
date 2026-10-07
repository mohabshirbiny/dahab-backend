<?php

use App\Enums\AccountEvent;
use App\Enums\InboxLinkKind;
use App\Enums\ListingDecision;
use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Listing;
use App\Notifications\AccountNotification;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\OrderNotification;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Tests\Support\Account;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 017 US4, FR-030–FR-035: the inbox — a third channel next to SMS and
// email; list, unread count, read; never codes or confirmation links.

/** Write one inbox item through the channel, as a real send would. */
function acc017Deliver(Customer $customer, Notification $n): void
{
    $n->id ??= (string) Str::uuid();
    app(InboxChannel::class)->send($customer, $n);
}

/** The SQLSTATE a write raises, rolled back (null when it succeeds). */
function acc017State(Closure $work): ?string
{
    return DatabaseActor::elevate('system', function () use ($work) {
        DB::beginTransaction();
        try {
            $work();

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? null;
        } finally {
            DB::rollBack();
        }
    });
}

function acc017Items(Customer $customer)
{
    return DatabaseActor::elevate('system', fn () => CustomerNotification::query()->where('customer_id', $customer->customer_id)->orderBy('created_at')->get());
}

it('opts every customer notification in, except codes, confirmation links and the proxy notice', function () {
    $never = [
        'CustomerLoginOtpNotification', 'CustomerRegistrationOtpNotification', 'CustomerRegistrationEmailOtpNotification',
        'WithdrawalConfirmationNotification', 'ProxyNamedNotification', 'PhoneChangeCodeNotification', 'EmailChangeLinkNotification',
    ];
    foreach (glob(app_path('Notifications/*Notification.php')) as $file) {
        $class = 'App\\Notifications\\'.basename($file, '.php');
        $implements = in_array(InboxNotification::class, class_implements($class), true);

        expect($implements)->toBe(! in_array(basename($file, '.php'), $never, true), $class);
    }
});

it('puts the inbox first in the channels of a customer, and nowhere for an on-demand route', function () {
    $customer = Account::customer(['email' => 'me@example.com']);
    $n = new OrderNotification(OrderEvent::PAID, 'DH-2026-000001', 'Gold ring, 21K', 'خاتم دهب، 21');

    expect($n->via($customer))->toBe(['inbox', 'mail', 'sms'])
        ->and($n->via(new AnonymousNotifiable))->toBe(['sms']);
});

it('keeps the collection code out of the inbox and links the order', function () {
    $customer = Account::customer();
    $n = (new OrderNotification(OrderEvent::COLLECTION_CODE, 'DH-2026-000001', 'Gold ring, 21K', 'خاتم دهب، 21',
        deadline: now()->addWeek()->toIso8601String(), branch: 'Nasr City', branchAr: 'مدينة نصر', code: '482913'))
        ->linkTo(InboxLinkKind::ORDER, '0199aaaa-0000-7000-8000-000000000001');

    acc017Deliver($customer, $n);
    $item = acc017Items($customer)->sole();

    expect($item->type)->toBe('order.'.OrderEvent::COLLECTION_CODE->value)
        ->and($item->link_kind->value)->toBe('order')
        ->and($item->link_id)->toBe('0199aaaa-0000-7000-8000-000000000001')
        ->and($item->body_en.$item->body_ar)->not->toContain('482913')
        ->and($item->body_en)->toContain('••••••')
        ->and($item->params)->toBe(['order_ref' => 'DH-2026-000001']);
});

it('writes an item when a real feature sends its notice (a listing decision)', function () {
    Orders::workedPrices();
    $seller = Customer::factory()->verified()->create();
    $listing = Listing::factory()->withPhotos(2)->inReview()->create(['seller_id' => $seller->customer_id, 'karat_code' => 21, 'stated_weight_g' => '10.000', 'making_charge_per_g' => '300.00']);
    Listings::actAsStaff($this, SeedRole::OPERATIONS);

    $this->postJson(Listings::STAFF_URL."/{$listing->listing_id}/approve", [], Listings::key())->assertOk();

    $item = acc017Items($seller)->sole();
    expect($item->type)->toBe('listing.'.ListingDecision::APPROVED->value)
        ->and($item->link_kind->value)->toBe('listing')
        ->and($item->link_id)->toBe($listing->listing_id);
});

it('lists newest first with a cursor, counts the unread and marks read', function () {
    $customer = Account::customer(['preferred_lang' => 'ar']);
    foreach (range(1, 3) as $i) {
        $this->travel(1)->minutes();
        acc017Deliver($customer, new AccountNotification(AccountEvent::PASSWORD_CHANGED, 'ar'));
    }
    $token = Listings::token($customer);

    $first = Account::as($this, $token)->getJson(Account::ME.'/notifications?per_page=2')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 3)
        ->assertJsonPath('data.0.title', 'كلمة السر اتغيرت')->assertJsonPath('data.0.link.kind', 'account');
    $second = Account::as($this, $token)->getJson(Account::ME.'/notifications?per_page=2&cursor='.$first->json('meta.next_cursor'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
    expect(array_merge($first->json('data.*.id'), $second->json('data.*.id')))->toHaveCount(3)->each->toBeString();

    $id = $first->json('data.0.id');
    $read = Account::post($this, $token, "/notifications/{$id}/read")->assertOk()->json('data.read_at');
    Account::post($this, $token, "/notifications/{$id}/read")->assertOk()->assertJsonPath('data.read_at', $read);
    Account::as($this, $token)->getJson(Account::ME.'/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);
    Account::as($this, $token)->getJson(Account::ME.'/notifications?unread=1')->assertOk()->assertJsonCount(2, 'data');

    Account::post($this, $token, '/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 2);
    Account::as($this, $token)->getJson(Account::ME.'/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    Account::as($this, $token)->getJson(Account::ME.'/notifications?cursor=not-a-cursor')->assertUnprocessable();
});

it('shows a customer only their own items', function () {
    $mine = Account::customer();
    $theirs = Account::customer();
    acc017Deliver($theirs, new AccountNotification(AccountEvent::PASSWORD_CHANGED, 'en'));
    $id = acc017Items($theirs)->sole()->notification_id;
    $token = Listings::token($mine);

    Account::as($this, $token)->getJson(Account::ME.'/notifications')->assertOk()->assertJsonCount(0, 'data');
    Account::post($this, $token, "/notifications/{$id}/read")->assertNotFound();
    expect(acc017Items($theirs)->sole()->read_at)->toBeNull();
});

it('lets staff with customer.view read what a customer was sent', function () {
    $customer = Account::customer();
    acc017Deliver($customer, new AccountNotification(AccountEvent::PASSWORD_CHANGED, 'en'));

    Listings::actAsStaff($this, SeedRole::VERIFICATION);
    $this->getJson("/api/v1/dashboard/customers/{$customer->customer_id}/notifications")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'account.password_changed')
        ->assertJsonPath('data.0.title', 'Your password was changed');
});

it('never lets an item change but its read time, nor be deleted', function () {
    $customer = Account::customer();
    acc017Deliver($customer, new AccountNotification(AccountEvent::PASSWORD_CHANGED, 'en'));
    $id = acc017Items($customer)->sole()->notification_id;

    expect(acc017State(fn () => DB::table('customer_notification')->where('notification_id', $id)->update(['title_en' => 'x'])))->toBe('DH014')
        // No delete policy: even an elevated scope deletes nothing (the guard is the second wall).
        ->and(DatabaseActor::elevate('system', fn () => DB::table('customer_notification')->where('notification_id', $id)->delete()))->toBe(0)
        ->and(acc017Items($customer))->toHaveCount(1);
});
