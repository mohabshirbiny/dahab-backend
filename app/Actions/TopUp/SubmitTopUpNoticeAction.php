<?php

namespace App\Actions\TopUp;

use App\Enums\TopUpOrigin;
use App\Enums\TopUpStatus;
use App\Enums\UploadPurpose;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\TopUp;
use App\Services\UploadTokenStore;
use App\Support\TopUpReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "I've sent the transfer" (spec 009 US1, FR-007–FR-011): records a pending
 * transfer notice. No money moves — staff credit what actually arrives
 * (MatchTopUpAction). Not audited (Part 2 §8); the row itself is the record.
 */
final class SubmitTopUpNoticeAction
{
    public function __construct(private readonly UploadTokenStore $tokens) {}

    /** @param  string  $amount  a positive decimal with at most 2 places (validated by the request) */
    public function handle(Customer $customer, string $amount, int $receivingAccountId, ?string $receiptToken): TopUp
    {
        return DB::transaction(function () use ($customer, $amount, $receivingAccountId, $receiptToken) {
            // The account may have been deactivated since the request was validated.
            $account = ReceivingAccount::query()->whereKey($receivingAccountId)->sharedLock()->first();
            if ($account === null || ! $account->is_active) {
                throw ValidationException::withMessages(['receiving_account_id' => ['This account is no longer available. Pick one from the refreshed list.']]);
            }

            $receipt = null;
            if ($receiptToken !== null) {
                $receipt = $this->tokens->resolveEntry($receiptToken, $customer->customer_id, UploadPurpose::TOPUP_RECEIPT)
                    ?? throw DomainApiException::uploadTokenInvalid();
            }

            $topUp = TopUp::query()->create([
                'customer_id' => $customer->customer_id,
                'origin' => TopUpOrigin::NOTICE,
                'method' => $account->method,
                'reference' => TopUpReference::for($customer),
                'claimed_amount' => bcadd($amount, '0', 2),
                'notice_account_id' => $account->receiving_account_id,
                // The fee shown when the customer sent: later fee changes never move this notice's estimate.
                'notice_fee_percent' => $account->provider_fee_percent,
                'receipt_ref' => $receipt['storage_ref'] ?? null,
                'receipt_mime' => $receipt === null ? null : ($receipt['mime'] ?? 'application/octet-stream'),
                'status' => TopUpStatus::PENDING,
            ]);

            if ($receiptToken !== null) {
                $this->tokens->forget($receiptToken);
            }

            return $topUp->refresh();
        });
    }
}
