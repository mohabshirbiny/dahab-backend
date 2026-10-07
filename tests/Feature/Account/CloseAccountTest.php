<?php

use App\Enums\AccountEvent;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\ListingState;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use App\Models\Listing;
use App\Notifications\AccountNotification;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Database\Factories\ListingFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Account;
use Tests\Support\BuyRequests;
use Tests\Support\Ledger;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 017 US7, FR-050, FR-051: close my account — refused while anything is
// in progress or any money is left; otherwise closed for good, nothing deleted.

beforeEach(function () {
    Notification::fake();
    Orders::workedPrices();
});

function acc017Close($test, string $token, array $body = ['reason' => 'finished'])
{
    return Account::post($test, $token, '/account/close', $body);
}

/** @return list<string> the blocker codes of a refused close */
function acc017Blockers($test, string $token): array
{
    return collect(acc017Close($test, $token)->assertConflict()->assertJsonPath('code', 'account_has_open_items')->json('details.blockers'))
        ->pluck('code')->all();
}

it('closes an account with nothing open: pieces off the market, every session gone, sign-in refused', function () {
    $customer = Account::customer(['email' => 'me@example.com']);
    $draft = Listing::factory()->create(['seller_id' => $customer->customer_id]);
    $live = Orders::ring($customer);
    $here = Account::signIn($this, $customer, 'phone-1');
    $there = Account::signIn($this, $customer, 'laptop-1', 'web');

    Account::as($this, $here['access'], 'phone-1')->getJson(Account::ME.'/account/close-check')->assertOk()
        ->assertJsonPath('data.can_close', true)->assertJsonPath('data.blockers', []);
    acc017Close($this, $here['access'], ['reason' => 'other', 'note' => 'Moving abroad'])->assertOk()->assertJsonStructure(['data' => ['closed_at']]);

    $fresh = DatabaseActor::elevate('system', fn () => Customer::query()->findOrFail($customer->customer_id));
    expect($fresh->status)->toBe(CustomerStatus::CLOSED)
        ->and($fresh->closed_reason->value)->toBe('other')
        ->and($fresh->closed_note)->toBe('Moving abroad')
        ->and($fresh->phone)->toBe($customer->phone)
        ->and(DatabaseActor::elevate('system', fn () => Listing::query()->whereIn('listing_id', [$draft->listing_id, $live->listing_id])->pluck('state')->unique()->all()))
        ->toBe([ListingState::WITHDRAWN])
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $customer->customer_id)->count())->toBe(0)
        ->and(DatabaseActor::elevate('system', fn () => CustomerTrustedDevice::query()->where('customer_id', $customer->customer_id)->count()))->toBe(0);

    Account::as($this, $there['access'], 'laptop-1', 'web')->getJson(Account::ME.'/sessions')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withoutToken()->withHeaders(['X-Device-Id' => 'phone-1', 'X-Device-Platform' => 'ios'])
        ->postJson('/api/v1/customer/auth/login', ['phone' => $customer->phone, 'password' => Account::PASSWORD])
        ->assertForbidden()->assertJsonPath('code', 'account_closed');

    Notification::assertSentTo($fresh, AccountNotification::class, fn ($n, $channels) => $n->event === AccountEvent::ACCOUNT_CLOSED && $channels === ['mail', 'sms']);
    expect(AuditLog::query()->where('action', AuditEvent::CUSTOMER_ACCOUNT_CLOSED->value)->sole()->actor_customer_id)->toBe($customer->customer_id);
});

it('refuses while money is in the wallet', function () {
    $customer = BuyRequests::funded('100');

    expect(acc017Blockers($this, Listings::token($customer)))->toBe(['wallet_balance']);
});

it('refuses while a buy request is waiting, and names the seller piece in a sale', function () {
    $listing = Orders::ring();
    $buyer = BuyRequests::funded('60000');
    BuyRequests::queued($this, $buyer, $listing);
    $seller = Customer::query()->findOrFail($listing->seller_id);

    expect(acc017Blockers($this, Listings::token($buyer)))->toContain('active_buy_request', 'wallet_balance')
        ->and(acc017Blockers($this, Listings::token($seller)))->toBe(['listing_in_sale']);
});

it('refuses while an order is open, for both sides', function () {
    $order = Orders::accepted($this);

    expect(acc017Blockers($this, Listings::token(Orders::seller($order))))->toContain('open_order', 'listing_in_sale')
        ->and(acc017Blockers($this, Listings::token(Orders::buyer($order))))->toContain('open_order', 'active_buy_request');
});

it('refuses while a withdrawal has not left', function () {
    $withdrawal = Withdrawals::requested($this, '42000', '42000');

    expect(acc017Blockers($this, Listings::token(Withdrawals::customer($withdrawal))))->toContain('pending_withdrawal', 'wallet_balance');
});

it('refuses while a piece of theirs waits at a branch', function () {
    $seller = Customer::factory()->verified()->create();
    ListingFactory::walk(Orders::ring($seller), [ListingState::RESERVED, ListingState::ACCEPTED, ListingState::AWAITING_SELLER_RETURN], 'buyer did not pay');

    expect(acc017Blockers($this, Listings::token($seller)))->toBe(['piece_at_branch']);
});

it('validates the reason and keeps a note only with another reason', function () {
    $token = Listings::token(Customer::factory()->create());

    acc017Close($this, $token, ['reason' => 'bored'])->assertUnprocessable();
    acc017Close($this, $token, ['reason' => 'finished', 'note' => 'x'])->assertUnprocessable();
    acc017Close($this, $token, ['reason' => 'other', 'note' => str_repeat('x', 501)])->assertUnprocessable();
});

it('closes a suspended account too', function () {
    $customer = Customer::factory()->withPassword(Account::PASSWORD)->suspended(byStaffId: SystemActor::id())->create();
    acc017Close($this, Listings::token($customer))->assertOk();

    expect(DatabaseActor::elevate('system', fn () => Customer::query()->findOrFail($customer->customer_id))->status)->toBe(CustomerStatus::CLOSED);
});

it('lets nothing new reach a closed customer (DH013)', function () {
    $customer = Customer::factory()->verified()->create();
    acc017Close($this, Listings::token($customer))->assertOk();

    expect(fn () => Ledger::topUp($customer, '10'))->toThrow(QueryException::class, 'account_closed');
});
