<?php

use App\Actions\Referral\GrantReferralRewardAction;
use App\Enums\SettingKey;
use App\Jobs\GrantReferralRewardJob;
use App\Models\CommissionCredit;
use App\Models\Customer;
use App\Models\Referral;
use App\Notifications\ReferralRewardNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T055 + T058 (research R22–R23; contract referral-api): the Invite screen, the friend's
// bound percentage credit, the inviter's reward only after the friend's settled sale.

const ME = '/api/v1/customer/me';

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    Promo::open(referral: true, sellerCodes: false);
    $this->inviter = Customer::factory()->verified()->create();
    $this->friend = Customer::factory()->verified()->create();
});

function inviterCode($t): string
{
    return Listings::as($t, $t->inviter)->getJson(ME.'/referral')->assertOk()->json('data.code');
}

function joinWith($t, string $code, ?Customer $friend = null, array $headers = [])
{
    $friend ??= $t->friend;
    $listing = Orders::ring($friend);

    return [Listings::as($t, $friend)->withHeaders($headers)->putJson(ME."/listings/{$listing->listing_id}/promo", ['code' => $code], Promo::key()), $listing];
}

it('shows nothing and creates nothing while the programme is off', function () {
    Promo::open(referral: false, sellerCodes: false);

    Listings::as($this, $this->inviter)->getJson(ME.'/referral')->assertOk()
        ->assertJsonPath('data.enabled', false)->assertJsonPath('data.code', null)->assertJsonPath('data.invites_sent', 0);
    Listings::as($this, $this->inviter)->postJson(ME.'/referral/shares', [], Promo::key())
        ->assertStatus(409)->assertJsonPath('code', 'referral_not_open');

    expect(DB::table('referral_code')->count())->toBe(0);
});

it('creates a stable eight-character code on the first read and counts shares up to the limit', function () {
    $code = inviterCode($this);
    expect($code)->toMatch('/^[A-HJ-NP-Z2-9]{8}$/')->and(inviterCode($this))->toBe($code);

    Promo::set(SettingKey::REFERRAL_MAX_INVITATIONS_PER_ACCOUNT, 2);
    foreach ([1, 2, 2] as $expected) {
        Listings::as($this, $this->inviter)->postJson(ME.'/referral/shares', [], Promo::key())
            ->assertOk()->assertJsonPath('data.invites_sent', $expected);
    }

    Listings::as($this, $this->inviter)->getJson(ME.'/referral')->assertOk()
        ->assertJsonPath('data.invites_sent', 2)->assertJsonPath('data.joined', 0)
        ->assertJsonPath('data.terms.friend_percent_off', '50.0000')->assertJsonPath('data.terms.inviter_reward_egp', '200.0000');
});

it('gives the friend a percentage credit bound to the listing when the code is applied', function () {
    $code = inviterCode($this);
    [$res, $listing] = joinWith($this, strtolower($code));

    $res->assertOk()->assertJsonPath('data.promo.source', 'referral')->assertJsonPath('data.promo.commission.percent', '50.0000');

    $referral = Referral::query()->where('friend_id', $this->friend->customer_id)->sole();
    $credit = CommissionCredit::query()->where('referral_id', $referral->referral_id)->sole();
    expect($referral->inviter_id)->toBe($this->inviter->customer_id)->and($referral->state->value)->toBe('joined')
        ->and($referral->friend_credit_id)->toBe($credit->credit_id)->and($referral->listing_id)->toBe($listing->listing_id)
        ->and($credit->source->value)->toBe('referral_friend')->and($credit->kind->value)->toBe('percent')
        ->and($credit->uses_left)->toBe(1)->and($credit->listing_id)->toBe($listing->listing_id)
        ->and($credit->expires_at->isFuture())->toBeTrue();

    Listings::as($this, $this->inviter)->getJson(ME.'/referral')->assertJsonPath('data.joined', 1)->assertJsonPath('data.sold', 0)
        ->assertJsonPath('data.earned_egp', '0.0000');
});

it('refuses the inviter\'s own code, a second referral, a seller with a paid sale, a full code and a used device', function () {
    $code = inviterCode($this);

    // Own code.
    [$own] = joinWith($this, $code, $this->inviter);
    $own->assertStatus(422)->assertJsonPath('details.reason', 'own_referral');

    // First join succeeds; the same friend again is refused.
    [$ok] = joinWith($this, $code, $this->friend, ['X-Device-Id' => 'dev-A']);
    $ok->assertOk();
    [$again] = joinWith($this, $code, $this->friend);
    $again->assertStatus(422)->assertJsonPath('details.reason', 'referral_joined');

    // The same device cannot be used for a second friend (1 per device by default).
    $other = Customer::factory()->verified()->create();
    [$device] = joinWith($this, $code, $other, ['X-Device-Id' => 'dev-A']);
    $device->assertStatus(422)->assertJsonPath('details.reason', 'device_limit');

    // The code is full at max_uses_per_code.
    Promo::set(SettingKey::REFERRAL_MAX_USES_PER_CODE, 1);
    [$full] = joinWith($this, $code, $other, ['X-Device-Id' => 'dev-B']);
    $full->assertStatus(422)->assertJsonPath('details.reason', 'referral_full');

    // A seller who already sold is not a new friend.
    Promo::set(SettingKey::REFERRAL_MAX_USES_PER_CODE, 10);
    $order = Orders::inspected($this, Orders::accepted($this));
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    [$veteran] = joinWith($this, $code, Orders::seller($order), ['X-Device-Id' => 'dev-C']);
    $veteran->assertStatus(422)->assertJsonPath('details.reason', 'first_sale_only');
});

it('takes half off the friend\'s commission and rewards the inviter only after the friend\'s sale', function () {
    $code = inviterCode($this);
    [$res, $listing] = joinWith($this, $code);
    $res->assertOk();

    $order = Orders::accepted($this, $listing);
    // Nothing is granted to the inviter before the sale.
    expect(CommissionCredit::query()->where('customer_id', $this->inviter->customer_id)->count())->toBe(0);

    Orders::inspected($this, $order);
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    $order->refresh();

    // The friend paid half the commission (600 -> 300), one use.
    expect($order->commission_amount)->toBe('300.0000')->and($order->commission_waiver_kind)->toBe('credit');

    // The inviter's reward: 200 EGP of commission credit, unbound, with the friend's sale as its cause.
    $referral = Referral::query()->where('friend_id', $this->friend->customer_id)->sole();
    $reward = CommissionCredit::query()->where('customer_id', $this->inviter->customer_id)->sole();
    expect($referral->state->value)->toBe('rewarded')->and($referral->reward_order_id)->toBe($order->order_id)
        ->and($referral->inviter_credit_id)->toBe($reward->credit_id)
        ->and($reward->source->value)->toBe('referral_inviter')->and($reward->kind->value)->toBe('amount')
        ->and($reward->amount_egp)->toBe('200.0000')->and($reward->listing_id)->toBeNull()
        ->and($reward->expires_at->isFuture())->toBeTrue();

    Notification::assertSentTo($this->inviter, ReferralRewardNotification::class);

    // It is never wallet money.
    expect(BuyRequests::balances($this->inviter)['available'])->toBe('0.0000');
    Listings::as($this, $this->inviter)->getJson(ME.'/referral')->assertOk()
        ->assertJsonPath('data.sold', 1)->assertJsonPath('data.earned_egp', '200.0000')->assertJsonPath('data.available_egp', '200.0000');
    Listings::as($this, $this->inviter)->getJson(ME.'/commission-credits')->assertOk()
        ->assertJsonPath('data.0.kind', 'amount')->assertJsonPath('data.0.amount_left_egp', '200.0000');
});

it('grants the reward once however many times the job and the healer run', function () {
    $code = inviterCode($this);
    [, $listing] = joinWith($this, $code);
    $order = Orders::inspected($this, Orders::accepted($this, $listing));
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    $this->artisan('referrals:reward-pending')->assertSuccessful();
    $this->artisan('referrals:reward-pending')->assertSuccessful();
    (new GrantReferralRewardJob($order->order_id))->handle(app(GrantReferralRewardAction::class));

    expect(CommissionCredit::query()->where('customer_id', $this->inviter->customer_id)->count())->toBe(1);
});

it('heals a lost job: the healer rewards a referral whose friend sold', function () {
    $code = inviterCode($this);
    [, $listing] = joinWith($this, $code);
    // The referral is rewarded only if the programme was on; simulate a lost job by turning it off for the payment.
    Promo::set(SettingKey::REFERRAL_ENABLED, false);
    $order = Orders::inspected($this, Orders::accepted($this, $listing));
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    expect(Referral::query()->sole()->state->value)->toBe('joined');

    Promo::set(SettingKey::REFERRAL_ENABLED, true);
    $this->artisan('referrals:reward-pending')->assertSuccessful();

    expect(Referral::query()->sole()->state->value)->toBe('rewarded');
});

it('does not reward a friend\'s sale below the minimum, and the sweep lets a stale join lapse', function () {
    Promo::set(SettingKey::REFERRAL_MIN_FRIEND_SALE_EGP, 1000000);
    $code = inviterCode($this);
    [, $listing] = joinWith($this, $code);
    $order = Orders::inspected($this, Orders::accepted($this, $listing));
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    expect(Referral::query()->sole()->state->value)->toBe('joined')
        ->and(CommissionCredit::query()->where('customer_id', $this->inviter->customer_id)->count())->toBe(0);

    // After referral.credit_expiry_days the join lapses and is never rewarded.
    $this->travel(91)->days();
    $this->artisan('commission-credits:expire')->assertSuccessful();
    expect(Referral::query()->sole()->state->value)->toBe('void')
        ->and(Referral::query()->sole()->void_reason)->toBe('expired');
});

it('uses the inviter\'s reward as a candidate on their own next sale, never summed with a code', function () {
    $code = inviterCode($this);
    [, $listing] = joinWith($this, $code);
    $order = Orders::inspected($this, Orders::accepted($this, $listing));
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    // The inviter sells a ring: 600 - 200 = 400.
    $own = Orders::inspected($this, Orders::accepted($this, Orders::ring($this->inviter)));
    Orders::pay($this, Orders::buyer($own), $own)->assertOk();
    $own->refresh();

    expect($own->commission_amount)->toBe('400.0000')->and($own->commission_waiver_kind)->toBe('credit');
    $reward = CommissionCredit::query()->where('customer_id', $this->inviter->customer_id)->sole();
    expect($reward->status->value)->toBe('exhausted')->and($reward->amount_left_egp)->toBe('0.0000');
});
