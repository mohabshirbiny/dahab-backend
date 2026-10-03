<?php

use App\Models\AuditLog;
use App\Models\PayoutAccount;
use App\Models\WithdrawalPause;
use App\Notifications\PayoutNotification;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Listings;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 US7, FR-015: withdrawals:sweep tells each customer once that their
// pause has ended (system actor, one pause per transaction, safe to rerun).

beforeEach(function () {
    Notification::fake();
    $this->customer = Withdrawals::funded('5000');
    Withdrawals::verifiedAccount($this, $this->customer);
    $b = Withdrawals::verifiedAccount($this, $this->customer, ['account_number_or_iban' => '1234567890']);
    Listings::as($this, $this->customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$b->payout_account_id}/use", [], Listings::key())->assertOk();
    $this->b = $b;
});

function opened($customer): int
{
    return Notification::sent($customer, PayoutNotification::class)->filter(fn ($n) => $n->event->value === 'withdrawals_open')->count();
}

it('does nothing before the pause ends', function () {
    Withdrawals::sweep();
    expect(WithdrawalPause::query()->sole()->ended_notified_at)->toBeNull()->and(opened($this->customer))->toBe(0);
});

it('tells the customer once when the pause has ended, as the system actor, and withdrawals open again', function () {
    $this->travel(49)->hours();

    Withdrawals::sweep();
    Withdrawals::sweep();

    $pause = WithdrawalPause::query()->sole();
    expect($pause->ended_notified_at)->not->toBeNull()
        ->and(opened($this->customer))->toBe(1)
        ->and(AuditLog::query()->where('action', 'withdrawal.pause_ended')->sole()->actor_staff_id)->toBe(SystemActor::id());

    Withdrawals::requestConfirmation($this, $this->customer, '100', $this->b->payout_account_id)->assertCreated();
});

it('stamps a superseded pause without a message', function () {
    $this->travel(47)->hours();
    $first = PayoutAccount::query()->where('customer_id', $this->customer->customer_id)->where('is_in_use', false)->first();
    Listings::as($this, $this->customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$first->payout_account_id}/use", [], Listings::key())->assertOk();
    $this->travel(2)->hours();

    Withdrawals::sweep();

    expect(WithdrawalPause::query()->whereNotNull('ended_notified_at')->count())->toBe(1)
        ->and(opened($this->customer))->toBe(0);
});
