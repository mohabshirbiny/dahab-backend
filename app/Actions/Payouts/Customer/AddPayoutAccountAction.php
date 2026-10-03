<?php

namespace App\Actions\Payouts\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\PayoutAccountChangeKind;
use App\Enums\PayoutEvent;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\PayoutAccount;
use App\Notifications\PayoutNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * "Send for review" (spec 013 US1, FR-001; Part 2 §8 `POST /me/payout-accounts`):
 * a new account under review, with the customer's acceptance of the current
 * payout-account declaration. Nothing is paused or cancelled here: an account
 * only redirects money once it becomes the one in use (Clarifications).
 * The customer is told on their current phone and email (terms §9.3).
 */
final class AddPayoutAccountAction
{
    use WorksWithdrawals;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param  string  $number  normalized: an Egyptian IBAN or 8–20 digits (validated by the request) */
    public function handle(Customer $customer, string $bank, string $holder, string $number, int $declarationId, ?RequestContext $ctx = null): PayoutAccount
    {
        return DB::transaction(function () use ($customer, $bank, $holder, $number, $declarationId, $ctx) {
            $declaration = LegalDocument::current(LegalDocument::PAYOUT_ACCOUNT_DECLARATION);
            if ($declaration === null || $declaration->legal_doc_id !== $declarationId) {
                throw DomainApiException::declarationRequired();
            }

            $this->lockAccounts($customer->customer_id);

            AgreementAcceptance::query()->create([
                'customer_id' => $customer->customer_id,
                'legal_doc_id' => $declaration->legal_doc_id,
                'context' => AgreementAcceptance::CONTEXT_PAYOUT_ACCOUNT,
                'ip_address' => filled($ctx?->ip) ? $ctx->ip : null,
                'device_fingerprint' => $ctx?->deviceFingerprintHash,
            ]);

            $account = PayoutAccount::query()->create([
                'customer_id' => $customer->customer_id,
                'bank_name' => $bank,
                'account_name' => $holder,
                'account_number_or_iban' => $number,
                'created_at' => now(),
            ]);

            $this->changeAccount($account->refresh(), PayoutAccountChangeKind::ADDED, $customer->customer_id, null);

            $this->audit->execute(AuditEvent::PAYOUT_ACCOUNT_ADDED, 'success',
                ['payout_account_id' => $account->payout_account_id, 'account' => $account->shortLabel(), 'declaration_version' => $declaration->version],
                'payout_account', $account->payout_account_id, $ctx, actorCustomerId: $customer->customer_id);

            $this->tellPayout($customer->customer_id, new PayoutNotification(PayoutEvent::ACCOUNT_ADDED, account: $account->shortLabel()));
            $this->flushPayoutOutbox();

            return $account;
        });
    }
}
