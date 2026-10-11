<?php

namespace App\Actions\Auth\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AccountEvent;
use App\Enums\AuditEvent;
use App\Enums\AuthErrorCode;
use App\Enums\CustomerStatus;
use App\Enums\PauseTrigger;
use App\Exceptions\AuthApiException;
use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Notifications\AccountNotification;
use App\Services\PasswordResetChallengeStore;
use App\Support\Account\CustomerSessions;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalSafetyStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "I forgot my password" — step 2 (spec 020 US1, research R2, R5–R7).
 *
 * The code is checked and spent under the challenge lock (wrong → counted and voided at the
 * cap; expired; reused; unknown or closed phone → `otp_invalid`). Then, in one transaction
 * under the payout-account locks and the customer row (the order of spec 017): the password
 * changes, the spec 013 safety stop runs (withdrawals not yet released are cancelled and
 * withdrawals pause), every session ends and every trusted device is forgotten, and the
 * sign-in lockout of the number is cleared. After commit the customer is told on every channel.
 * No session is issued.
 */
final class ConfirmPasswordResetAction
{
    public function __construct(
        private readonly PasswordResetChallengeStore $challenges,
        private readonly WithdrawalSafetyStop $safetyStop,
        private readonly CustomerSessions $sessions,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{signed_out_sessions: int, withdrawals_paused_until: ?Carbon, cancelled_withdrawals: int} */
    public function execute(string $phone, string $code, string $password, RequestContext $ctx): array
    {
        $customerId = $this->spend($phone, $code, $ctx);

        return DB::transaction(function () use ($customerId, $password, $ctx) {
            $this->safetyStop->lockPayoutAccounts($customerId);
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();

            // Closed since the code was sent (R2): the same answer as for no code.
            if ($customer === null || $customer->status === CustomerStatus::CLOSED) {
                throw $this->invalid();
            }

            CustomerPassword::query()->updateOrCreate(
                ['customer_id' => $customerId],
                ['password_hash' => Hash::make($password), 'password_changed_at' => now()],
            );

            ['pause' => $pause, 'cancelled' => $cancelled] = $this->safetyStop->apply(
                $customerId, PauseTrigger::PASSWORD_RESET, 'password reset', actorCustomerId: $customerId);

            $ended = $this->sessions->endOthers($customer, null);
            $forgotten = $this->sessions->forgetOthers($customer, null);

            $this->clearSignInLockout($customer);

            $pauseUntil = $pause?->pause_until;
            $this->audit->execute(AuditEvent::CUSTOMER_PASSWORD_RESET_COMPLETED, 'success', [
                'sessions_ended' => $ended,
                'devices_forgotten' => $forgotten,
                'cancelled_withdrawals' => $cancelled,
                'pause_until' => $pauseUntil?->toIso8601String(),
            ], 'customer', $customerId, $ctx, actorCustomerId: $customerId);

            $notice = new AccountNotification(AccountEvent::PASSWORD_RESET, $customer->preferred_lang ?? 'ar',
                pauseUntil: $pauseUntil?->toIso8601String(), cancelledWithdrawals: count($cancelled));
            DB::afterCommit(fn () => $customer->notify($notice));

            return [
                'signed_out_sessions' => $ended,
                'withdrawals_paused_until' => $pauseUntil,
                'cancelled_withdrawals' => count($cancelled),
            ];
        });
    }

    /** Checks the code under the lock; a right code is spent at once. @return string the customer id */
    private function spend(string $phone, string $code, RequestContext $ctx): string
    {
        $lock = $this->challenges->lock($phone);

        if (! $lock->get()) {
            throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'Verification already in progress.');
        }

        try {
            $challenge = $this->challenges->find($phone);

            if ($challenge === null) {
                throw $this->invalid();
            }

            $customerId = $challenge['customer_id'];
            $max = (int) config('dahab-auth.otp.max_verify_attempts');

            if (($challenge['verify_attempts'] ?? 0) >= $max) {
                $this->challenges->forget($phone);
                throw $this->invalid();
            }

            if (Carbon::parse($challenge['code_expires_at'])->isPast()) {
                throw new AuthApiException(AuthErrorCode::OTP_EXPIRED, 401, 'The code has expired.');
            }

            if (! Hash::check($code, $challenge['code_hash'])) {
                $challenge['verify_attempts'] = ($challenge['verify_attempts'] ?? 0) + 1;

                $this->audit->execute(
                    AuditEvent::CUSTOMER_OTP_FAILED,
                    'failure',
                    ['purpose' => 'password_reset', 'attempts' => $challenge['verify_attempts']],
                    'customer',
                    $customerId,
                    $ctx,
                    actorCustomerId: $customerId,
                );

                if ($challenge['verify_attempts'] >= $max) {
                    $this->challenges->forget($phone);
                } else {
                    $this->challenges->save($phone, $challenge);
                }

                throw $this->invalid();
            }

            $this->challenges->forget($phone);

            return $customerId;
        } finally {
            $lock->release();
        }
    }

    /** The bucket of the `auth.customer.login` limiter for this number (the throttle middleware hashes its keys). */
    private function clearSignInLockout(Customer $customer): void
    {
        RateLimiter::clear(md5('auth.customer.login'.'cust-login-id:'.mb_strtolower(trim($customer->phone))));
    }

    private function invalid(): AuthApiException
    {
        return new AuthApiException(AuthErrorCode::OTP_INVALID, 401, 'The code is incorrect.');
    }
}
