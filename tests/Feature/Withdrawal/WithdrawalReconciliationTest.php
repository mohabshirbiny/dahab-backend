<?php

use App\Enums\SeedRole;
use App\Enums\WithdrawalState;
use App\Models\Customer;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Listings;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 T060, FR-016, SC-003: drive every path, then check every
// withdrawal against its ledger — one hold equal to the amount; nothing else
// while open; exactly one release (held → bank) or one return (held →
// available) once final; each customer's held equals held on orders plus
// pending withdrawals; the whole ledger sums to zero.

it('reconciles every withdrawal with its ledger', function () {
    Notification::fake();

    $requested = Withdrawals::requested($this, '1000', '5000');
    $underReview = Withdrawals::underReview($this);
    $released = Withdrawals::underReview($this);
    Withdrawals::release($this, $released)->assertOk();
    $rejected = Withdrawals::requested($this, '700', '3000');
    Withdrawals::staff($this, SeedRole::FINANCE);
    Withdrawals::act($this, $rejected, 'reject', ['reason' => 'other', 'note' => 'reconciliation'])->assertOk();
    $heldRejected = Withdrawals::underReview($this);
    Withdrawals::act($this, $heldRejected, 'hold', ['reason' => 'other', 'message' => 'wait', 'note' => 'wait'])->assertOk();
    Withdrawals::act($this, $heldRejected, 'reject', ['reason' => 'other', 'note' => 'held then rejected'])->assertOk();
    $cancelled = Withdrawals::requested($this, '300', '1000');
    Listings::as($this, Withdrawals::customer($cancelled))->postJson(Withdrawals::CUSTOMER."/withdrawals/{$cancelled->withdrawal_id}/cancel", [], Listings::key())->assertOk();
    $byChange = Withdrawals::requested($this, '400', '1000');
    $b = Withdrawals::verifiedAccount($this, Withdrawals::customer($byChange), ['account_number_or_iban' => '1234567890']);
    Listings::as($this, Withdrawals::customer($byChange))->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$b->payout_account_id}/use", [], Listings::key())->assertOk();

    $states = Withdrawal::query()->pluck('state')->map(fn ($s) => $s->value)->countBy()->all();
    expect($states)->toEqual(['requested' => 1, 'under_review' => 1, 'released' => 1, 'rejected' => 2, 'cancelled' => 2]);

    foreach (Withdrawal::query()->get() as $w) {
        $amount = bcadd((string) $w->amount, '0', 4);
        $entries = DB::table('ledger_transaction')->where('withdrawal_id', $w->withdrawal_id)->orderBy('created_at')->get();

        expect(Withdrawals::lines($w->hold_txn_id))->toBe([['cust_available', '-'.$amount], ['cust_held', $amount]]);
        expect($entries->every(fn ($t) => $t->event_kind === 'withdrawal'))->toBeTrue();

        match ($w->state) {
            WithdrawalState::REQUESTED, WithdrawalState::UNDER_REVIEW => expect($entries)->toHaveCount(1)
                ->and($w->release_txn_id)->toBeNull()->and($w->return_txn_id)->toBeNull(),
            WithdrawalState::RELEASED => expect($entries)->toHaveCount(2)->and($w->return_txn_id)->toBeNull()
                ->and(Withdrawals::lines($w->release_txn_id))->toBe([['cust_held', '-'.$amount], ['bank', $amount]]),
            WithdrawalState::REJECTED, WithdrawalState::CANCELLED => expect($entries)->toHaveCount(2)->and($w->release_txn_id)->toBeNull()
                ->and(Withdrawals::lines($w->return_txn_id))->toBe([['cust_held', '-'.$amount], ['cust_available', $amount]]),
            default => throw new LogicException("Unexpected state {$w->state->value}"),
        };
    }

    Withdrawals::staff($this, SeedRole::FINANCE);
    foreach (Customer::query()->whereIn('customer_id', Withdrawal::query()->select('customer_id'))->get() as $customer) {
        $wallet = $this->getJson(Withdrawals::STAFF."/customers/{$customer->customer_id}/wallet")->assertOk()->json('data');
        expect(bcadd($wallet['held_on_orders'], $wallet['pending_withdrawals'], 4))->toBe($wallet['held'])
            ->and($wallet['held_on_orders'])->toBe('0.0000');
    }

    expect(bccomp((string) DB::table('ledger_posting')->sum('amount'), '0', 4))->toBe(0);
    $this->getJson(Withdrawals::STAFF.'/wallets/overview')->assertOk()
        ->assertJsonPath('data.system_total', '0.0000')
        ->assertJsonPath('data.pending_withdrawals', bcadd('1000', '42000', 4));
});
