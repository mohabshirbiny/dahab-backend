<?php

namespace App\Actions\TopUp\Concerns;

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\CustomerStatus;
use App\Enums\LedgerEventKind;
use App\Models\Account;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Models\Staff;
use App\Models\TopUp;
use App\Notifications\TopUpCreditedNotification;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a match and a hand credit share (spec 009 research R8): the
 * suspended-customer rule, the one ledger entry, and the after-commit
 * message. Used only inside the caller's transaction.
 */
trait CreditsWallet
{
    /**
     * Lock the customer and apply the suspended-customer rule (FR-016,
     * FR-018; second-analysis M1): crediting a suspended customer is
     * staff-side reconciliation of money that already arrived, so the
     * provider's transaction reference is required. Returns the status at
     * credit time, for the audit row.
     */
    private function statusForCredit(string $customerId, ?string $arrivalReference): CustomerStatus
    {
        $status = Customer::query()->whereKey($customerId)->sharedLock()->firstOrFail()->status;
        $status = $status instanceof CustomerStatus ? $status : CustomerStatus::from((string) $status);

        if ($status === CustomerStatus::SUSPENDED && blank($arrivalReference)) {
            throw ValidationException::withMessages([
                'arrival_reference' => ['This customer is suspended: enter the transaction reference from Dahab\'s bank or wallet statement that shows the money arrived.'],
            ]);
        }

        return $status;
    }

    /** One `topup` entry: bank −amount, customer available +amount (spec 008 R15), attributed to the staff member. */
    private function postCredit(PostLedgerEntryAction $post, Staff $actor, string $customerId, string $amount, string $memo): LedgerTransaction
    {
        $amount = bcadd($amount, '0', 4);

        return $post->handle(new LedgerEntry(
            LedgerEventKind::TOPUP,
            [
                new LedgerLine(Account::internal(AccountKind::BANK), '-'.$amount),
                new LedgerLine(Account::forCustomerKind($customerId, AccountKind::CUST_AVAILABLE), $amount),
            ],
            actorStaffId: $actor->staff_id,
            memo: $memo,
        ));
    }

    /** Sent only if (and when) the outermost transaction commits (FR-026). */
    private function notifyCreditedAfterCommit(TopUp $topUp): void
    {
        DB::afterCommit(function () use ($topUp) {
            $topUp->customer()->first()?->notify(new TopUpCreditedNotification((string) $topUp->credited_amount, $topUp->number()));
        });
    }
}
