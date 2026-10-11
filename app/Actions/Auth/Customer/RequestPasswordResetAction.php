<?php

namespace App\Actions\Auth\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Notifications\PasswordResetCodeNotification;
use App\Services\PasswordResetChallengeStore;
use App\Support\RequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * "I forgot my password" — step 1 (spec 020 US1, research R1/R2).
 *
 * The answer is the same for a registered, unregistered, closed or cooling-down phone: it is
 * computed from configuration, never from the account. Only an existing, not-closed account
 * gets an SMS and an audit row. An unknown or closed phone does one dummy hash so the work
 * (and the timing) matches; the SMS itself is queued, so its latency is not on this path.
 *
 * The send cooldown and the hourly cap are honoured without changing the answer.
 */
final class RequestPasswordResetAction
{
    public function __construct(
        private readonly PasswordResetChallengeStore $challenges,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{expires_in_seconds: int, resend_available_at: Carbon} */
    public function execute(string $phone, RequestContext $ctx): array
    {
        $answer = [
            'expires_in_seconds' => (int) config('dahab-auth.otp.ttl_seconds'),
            'resend_available_at' => now()->addSeconds((int) config('dahab-auth.otp.send_cooldown_seconds')),
        ];

        $customer = Customer::query()->where('phone', $phone)->first();

        if ($customer === null || $customer->status === CustomerStatus::CLOSED) {
            Hash::make('password-reset-dummy');
            Log::info('password_reset.requested_for_unusable_phone');

            return $answer;
        }

        $lock = $this->challenges->lock($phone);
        if (! $lock->get()) {
            // A request for this phone is already in progress: nothing more to send.
            return $answer;
        }

        try {
            $existing = $this->challenges->find($phone);

            if ($existing !== null && $this->mustWait($existing)) {
                return $answer;
            }

            $code = $this->newOtpCode();
            $payload = [
                'customer_id' => $customer->customer_id,
                'code_hash' => Hash::make($code),
                'code_expires_at' => now()->addSeconds($answer['expires_in_seconds'])->toIso8601String(),
                'verify_attempts' => 0,
                'sends' => ($existing['sends'] ?? 0) + 1,
                'last_sent_at' => now()->toIso8601String(),
            ];

            if ($existing !== null) {
                $this->challenges->save($phone, [...$payload, 'challenge_expires_at' => $existing['challenge_expires_at']]);
            } else {
                $this->challenges->begin($phone, $payload);
            }

            Notification::route('sms', $customer->phone)
                ->notify(new PasswordResetCodeNotification($code, $customer->preferred_lang ?? 'ar'));

            $this->audit->execute(
                AuditEvent::CUSTOMER_PASSWORD_RESET_REQUESTED,
                'success',
                ['channel' => 'sms'],
                'customer',
                $customer->customer_id,
                $ctx,
                actorCustomerId: $customer->customer_id,
            );
        } finally {
            $lock->release();
        }

        return $answer;
    }

    /** @param array<string, mixed> $challenge */
    private function mustWait(array $challenge): bool
    {
        $cooldown = (int) config('dahab-auth.otp.send_cooldown_seconds');
        if (Carbon::parse($challenge['last_sent_at'])->addSeconds($cooldown)->isFuture()) {
            return true;
        }

        return ($challenge['sends'] ?? 0) >= (int) config('dahab-auth.otp.max_sends_per_hour');
    }

    private function newOtpCode(): string
    {
        // Same local-only fixed code as the other OTP actions.
        if (app()->environment('development', 'local')) {
            return '123456';
        }

        $length = (int) config('dahab-auth.otp.code_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
