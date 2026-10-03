<?php

use App\Enums\SeedRole;
use App\Notifications\PayoutNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Listings;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 FR-018, FR-019, SC-007: what each side may see and be told.
// Customers never get staff notes, the reviewer, the bank transaction number
// or another customer's data; SMS never carry a full account number; a
// rolled-back action sends nothing.

beforeEach(function () {
    Notification::fake();
});

it('keeps staff notes, the reviewer and the bank record out of customer responses', function () {
    $w = Withdrawals::underReview($this);
    $finance = Withdrawals::staff($this, SeedRole::FINANCE);
    Withdrawals::act($this, $w, 'hold', ['reason' => 'other', 'message' => 'Please call us.', 'note' => 'SECRET-HOLD-NOTE'])->assertOk();
    Withdrawals::act($this, $w, 'unhold')->assertOk();
    Withdrawals::release($this, $w, 'FT-SECRET-99')->assertOk();
    $customer = Withdrawals::customer($w);

    $bodies = json_encode([
        Listings::as($this, $customer)->getJson(Withdrawals::CUSTOMER.'/withdrawals')->json(),
        Listings::as($this, $customer)->getJson(Withdrawals::CUSTOMER."/withdrawals/{$w->withdrawal_id}")->json(),
        Listings::as($this, $customer)->getJson(Withdrawals::CUSTOMER.'/payout-accounts')->json(),
    ]);

    expect($bodies)->not->toContain('SECRET-HOLD-NOTE')
        ->not->toContain('FT-SECRET-99')
        ->not->toContain($finance->staff_id)
        ->not->toContain(Withdrawals::iban());
});

it('never tells a customer another customer\'s withdrawals', function () {
    $mine = Withdrawals::requested($this, '1000', '5000');
    $other = Withdrawals::requested($this, '2000', '5000');

    $body = json_encode(Listings::as($this, Withdrawals::customer($mine))->getJson(Withdrawals::CUSTOMER.'/withdrawals')->json());
    expect($body)->toContain($mine->withdrawal_id)->not->toContain($other->withdrawal_id)->not->toContain($other->customer_id);
});

it('sends every message to the right customer, masked, in their language', function () {
    $w = Withdrawals::requested($this, '1000', '5000');
    $customer = Withdrawals::customer($w);
    $customer->forceFill(['preferred_lang' => 'ar'])->save();
    Withdrawals::staff($this, SeedRole::FINANCE);
    Withdrawals::act($this, $w, 'reject', ['reason' => 'customer_request', 'note' => 'asked by phone'])->assertOk();

    $all = Notification::sent($customer, PayoutNotification::class);
    $events = $all->map(fn ($n) => $n->event->value)->sort()->values()->all();
    expect($events)->toBe(['account_added', 'account_in_use', 'account_verified', 'withdrawal_rejected']);

    foreach ($all as $n) {
        $sms = $n->toSms($customer)->content;
        expect($sms)->not->toContain(Withdrawals::iban())->not->toContain('asked by phone');
        expect($n->via($customer))->toContain('sms');
    }
    expect($all->last()->toSms($customer->refresh())->content)->toContain('اترفض');
});

it('sends nothing when the action rolls back', function () {
    $customer = Withdrawals::funded('0');
    Withdrawals::add($this, $customer, ['declaration_id' => 999])->assertUnprocessable();

    Notification::assertNotSentTo($customer, PayoutNotification::class);
});
