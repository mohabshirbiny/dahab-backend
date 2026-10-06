<?php

namespace App\Actions\Account;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AccountEvent;
use App\Enums\AuditEvent;
use App\Enums\PauseTrigger;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\WithdrawalPause;
use App\Notifications\AccountNotification;
use App\Support\Account\ContactMask;
use App\Support\RequestContext;
use App\Support\Withdrawals\ConfirmationTokens;
use App\Support\Withdrawals\WithdrawalSafetyStop;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The email-change link (spec 017 FR-010, FR-011), opened from the customer
 * app's public page; the route runs elevated (`db.elevate:bootstrap`, as the
 * spec 013 link) and the actor is the customer the link belongs to.
 *
 * Confirming spends the link atomically (a link used twice changes once), then
 * under the payout-account locks and the customer row: the email changes and,
 * when there was one before, open withdrawal confirmations stop working,
 * withdrawals not yet released are cancelled and new ones pause. After commit
 * the old address is told.
 */
final class EmailChangeLinkAction
{
    public function __construct(
        private readonly WithdrawalSafetyStop $safetyStop,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{email_masked: string, expires_at: CarbonImmutable} */
    public function read(string $token): array
    {
        $row = DB::table('one_time_token')->where('token_hash', ConfirmationTokens::hash($token))
            ->where('purpose', 'email_change')->whereNull('consumed_at')->where('expires_at', '>', now())->first();
        if ($row === null) {
            throw DomainApiException::changeLinkInvalid();
        }
        $email = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR)['new_email'];

        return ['email_masked' => (string) ContactMask::email($email), 'expires_at' => CarbonImmutable::parse($row->expires_at)];
    }

    /** @return array{email_masked: string, pause: ?WithdrawalPause} */
    public function confirm(string $token, ?RequestContext $ctx = null): array
    {
        try {
            return DB::transaction(function () use ($token, $ctx) {
                $spent = DB::selectOne(<<<'SQL'
                    UPDATE one_time_token SET consumed_at = clock_timestamp()
                     WHERE token_hash = ? AND purpose = 'email_change' AND consumed_at IS NULL AND expires_at > ?
                    RETURNING actor_customer_id, payload
                    SQL, [ConfirmationTokens::hash($token), now()]);
                if ($spent === null) {
                    throw DomainApiException::changeLinkInvalid();
                }
                $newEmail = json_decode((string) $spent->payload, true, flags: JSON_THROW_ON_ERROR)['new_email'];
                $customerId = (string) $spent->actor_customer_id;

                $this->safetyStop->lockPayoutAccounts($customerId);
                $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
                if ($customer->closed_at !== null) {
                    throw DomainApiException::changeLinkInvalid();
                }
                if (RequestEmailChangeAction::emailTaken($newEmail, $customerId)) {
                    throw DomainApiException::contactTaken();
                }

                $oldEmail = $customer->email;
                $customer->forceFill(['email' => $newEmail, 'email_verified_at' => now()])->save();

                $pause = null;
                $cancelled = [];
                $linksStopped = 0;
                if ($oldEmail !== null) {
                    // Email is one of the two withdrawal checks: links sent to the old address stop working.
                    $linksStopped = DB::table('withdrawal_confirmation')->where('customer_id', $customerId)
                        ->whereNull('used_at')->whereNull('replaced_at')->update(['replaced_at' => now()]);
                    ['pause' => $pause, 'cancelled' => $cancelled] = $this->safetyStop->apply(
                        $customerId, PauseTrigger::EMAIL_CHANGE, 'email changed', actorCustomerId: $customerId);
                }

                $this->audit->execute(AuditEvent::CUSTOMER_EMAIL_CHANGED, 'success', [
                    'email' => ContactMask::email($newEmail),
                    'pause_until' => $pause?->pause_until?->toIso8601String(),
                    'cancelled_withdrawals' => $cancelled,
                    'confirmation_links_stopped' => $linksStopped,
                ], 'customer', $customerId, $ctx, actorCustomerId: $customerId,
                    before: ['email' => ContactMask::email($oldEmail)]);

                $notice = fn (?array $only) => new AccountNotification(AccountEvent::EMAIL_CHANGED, $customer->preferred_lang ?? 'ar',
                    detail: ContactMask::email($newEmail), pauseUntil: $pause?->pause_until?->toIso8601String(),
                    cancelledWithdrawals: count($cancelled), only: $only);
                DB::afterCommit(function () use ($oldEmail, $customer, $notice) {
                    if ($oldEmail !== null) {
                        Notification::route('mail', $oldEmail)->notify($notice(['mail']));
                    }
                    $customer->notify($notice(['inbox', 'sms']));
                });

                return ['email_masked' => (string) ContactMask::email($newEmail), 'pause' => $pause];
            });
        } catch (UniqueConstraintViolationException) {
            throw DomainApiException::contactTaken();
        }
    }
}
