<?php

namespace App\Actions\Withdrawals\Public;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\WithdrawalConfirmation;
use App\Support\RequestContext;
use App\Support\Withdrawals\ConfirmationTokens;
use Illuminate\Support\Facades\DB;

/**
 * The email link's page (spec 013 FR-008, research R5). Reached without a
 * session (`db.elevate:bootstrap`, as the auth routes): the token is the only
 * key, and it finds one row by its HMAC. `read` changes nothing — a mail
 * scanner that opens the link confirms nothing; `confirm` needs the
 * customer's tap. Unknown, expired, replaced and used tokens look the same.
 */
final class ConfirmWithdrawalAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function read(string $token): WithdrawalConfirmation
    {
        $confirmation = WithdrawalConfirmation::query()->with('account')
            ->where('token_hash', ConfirmationTokens::hash($token))->first();

        if ($confirmation === null) {
            throw DomainApiException::confirmationInvalid();
        }

        return $confirmation;
    }

    public function confirm(string $token, ?RequestContext $ctx = null): WithdrawalConfirmation
    {
        return DB::transaction(function () use ($token, $ctx) {
            $confirmation = WithdrawalConfirmation::query()
                ->where('token_hash', ConfirmationTokens::hash($token))->lockForUpdate()->first();

            if ($confirmation === null) {
                throw DomainApiException::confirmationInvalid();
            }

            $state = $confirmation->state();
            if ($state === WithdrawalConfirmation::CONFIRMED) {
                return $confirmation->load('account');
            }
            if ($state !== WithdrawalConfirmation::SENT) {
                throw DomainApiException::confirmationInvalid();
            }

            $confirmation->forceFill(['confirmed_at' => now()])->save();

            $this->audit->execute(AuditEvent::WITHDRAWAL_EMAIL_CONFIRMED, 'success',
                ['confirmation_id' => $confirmation->confirmation_id, 'amount' => $confirmation->amount],
                'withdrawal_confirmation', $confirmation->confirmation_id, $ctx,
                actorCustomerId: $confirmation->customer_id);

            return $confirmation->load('account');
        });
    }
}
