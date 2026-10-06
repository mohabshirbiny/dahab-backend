<?php

namespace App\Actions\Account;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AccountEvent;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Notifications\AccountNotification;
use App\Support\Account\CustomerSessions;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Change the password with the current one (spec 017 FR-012): every other
 * session ends, trusted devices stay; the customer is told on every channel.
 * A wrong current password counts against the sign-in limiter of the number.
 */
final class ChangePasswordAction
{
    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{signed_out_sessions: int} */
    public function handle(Customer $customer, string $current, string $new, ?string $currentFamily, ?RequestContext $ctx = null): array
    {
        $password = CustomerPassword::query()->whereKey($customer->customer_id)->first();
        if ($password === null || ! Hash::check($current, $password->password_hash)) {
            $this->countFailedSignIn($customer);
            $this->audit->execute(AuditEvent::CUSTOMER_PASSWORD_CHANGED, 'failure', ['reason' => 'current_password_wrong'],
                'customer', $customer->customer_id, $ctx, actorCustomerId: $customer->customer_id);

            throw DomainApiException::currentPasswordWrong();
        }

        return DB::transaction(function () use ($customer, $current, $new, $currentFamily, $ctx) {
            $password = CustomerPassword::query()->whereKey($customer->customer_id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($current, $password->password_hash)) {
                // Changed by a racing request in between.
                throw DomainApiException::currentPasswordWrong();
            }

            $password->forceFill(['password_hash' => Hash::make($new), 'password_changed_at' => now()])->save();
            $ended = $this->sessions->endOthers($customer, $currentFamily);

            $this->audit->execute(AuditEvent::CUSTOMER_PASSWORD_CHANGED, 'success', ['sessions_ended' => $ended],
                'customer', $customer->customer_id, $ctx, actorCustomerId: $customer->customer_id);

            $notice = new AccountNotification(AccountEvent::PASSWORD_CHANGED, $customer->preferred_lang ?? 'ar');
            DB::afterCommit(fn () => $customer->notify($notice));

            return ['signed_out_sessions' => $ended];
        });
    }

    /** The bucket of the `auth.customer.login` limiter for this number (the throttle middleware hashes its keys). */
    private function countFailedSignIn(Customer $customer): void
    {
        $window = (int) config('dahab-auth.rate_limits.customer_login.per_identity_window');
        RateLimiter::hit(md5('auth.customer.login'.'cust-login-id:'.mb_strtolower(trim($customer->phone))), $window);
    }
}
