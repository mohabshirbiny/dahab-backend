<?php

namespace App\Providers;

use App\Exceptions\AuthApiException;
use App\Models\PersonalAccessToken;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\HttpSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Support\DatabaseActorEvents;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerSmsSender();
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configurePasswordPolicy();
        $this->registerSmsNotificationChannel();

        // Row-level security (spec 003): token owners load under a bootstrap
        // elevation; queued jobs and migrate/seed run under audited elevations.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        DatabaseActorEvents::register();
    }

    /**
     * The `sms` driver is chosen by config alone, so wiring a real provider is
     * an .env change. `log` is the default and needs no credentials.
     */
    private function registerSmsSender(): void
    {
        $this->app->singleton(SmsSender::class, function ($app) {
            $driver = (string) config('sms.default');
            $config = config('sms.drivers.'.$driver, []);

            return match ($driver) {
                'http' => new HttpSmsSender($config, (string) config('sms.from')),
                default => new LogSmsSender($config['channel'] ?? null),
            };
        });
    }

    private function registerSmsNotificationChannel(): void
    {
        Notification::resolved(function (ChannelManager $service) {
            $service->extend('sms', fn ($app) => $app->make(SmsChannel::class));
        });
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        // Every auth surface owns its limiter (and therefore its buckets):
        // customer register / customer login / OTP / password reset /
        // dashboard login / refresh. Throttle keys are hashed together with the
        // limiter name, so hitting one never consumes another's budget.

        $register = config('dahab-auth.rate_limits.customer_register');
        RateLimiter::for('auth.customer.register', function (Request $request) use ($register) {
            return [
                $this->limit($register['per_identity_max'], $register['per_identity_window'], 'cust-register-id:'.$this->identity($request, 'phone')),
                $this->limit($register['per_ip_max'], $register['per_ip_window'], 'cust-register-ip:'.$request->ip()),
            ];
        });

        // Registration OTP guessing. Keyed by the registration_ref (the only
        // identity the client holds at that point) and by IP. The session
        // itself also dies after `otp.max_verify_attempts` wrong codes; this
        // bucket is the cheaper outer wall.
        $registerOtp = config('dahab-auth.rate_limits.customer_register_otp');
        RateLimiter::for('auth.customer.register.otp', function (Request $request) use ($registerOtp) {
            return [
                $this->limit($registerOtp['per_session_max'], $registerOtp['per_session_window'], 'cust-register-otp-ref:'.$this->identity($request, 'registration_ref')),
                $this->limit($registerOtp['per_ip_max'], $registerOtp['per_ip_window'], 'cust-register-otp-ip:'.$request->ip()),
            ];
        });

        $registerDocuments = config('dahab-auth.rate_limits.customer_register_documents');
        RateLimiter::for('auth.customer.register.documents', function (Request $request) use ($registerDocuments) {
            return [
                $this->limit($registerDocuments['per_session_max'], $registerDocuments['per_session_window'], 'cust-register-documents-ref:'.$this->identity($request, 'registration_ref')),
                $this->limit($registerDocuments['per_ip_max'], $registerDocuments['per_ip_window'], 'cust-register-documents-ip:'.$request->ip()),
            ];
        });

        $login = config('dahab-auth.rate_limits.customer_login');
        RateLimiter::for('auth.customer.login', function (Request $request) use ($login) {
            return [
                $this->lockout($this->limit($login['per_identity_max'], $login['per_identity_window'], 'cust-login-id:'.$this->identity($request, 'phone'))),
                $this->limit($login['per_ip_max'], $login['per_ip_window'], 'cust-login-ip:'.$request->ip()),
            ];
        });

        $sLogin = config('dahab-auth.rate_limits.staff_login');
        RateLimiter::for('auth.staff.login', function (Request $request) use ($sLogin) {
            return [
                $this->lockout($this->limit($sLogin['per_identity_max'], $sLogin['per_identity_window'], 'staff-login-id:'.$this->identity($request, 'email'))),
                $this->limit($sLogin['per_ip_max'], $sLogin['per_ip_window'], 'staff-login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('customer.uploads', function (Request $request) {
            return $this->limit((int) config('dahab-identity.uploads_per_minute'), 60, 'customer-uploads:'.($request->user('customer')?->getAuthIdentifier() ?? $request->ip()));
        });

        $mfa = config('dahab-auth.rate_limits.staff_mfa');
        RateLimiter::for('auth.staff.mfa', function (Request $request) use ($mfa) {
            return [
                $this->limit($mfa['per_session_max'], $mfa['per_session_window'], 'staff-mfa-session:'.$this->identity($request, 'session_ref')),
                $this->limit($mfa['per_ip_max'], $mfa['per_ip_window'], 'staff-mfa-ip:'.$request->ip()),
            ];
        });

        $reset = config('dahab-auth.rate_limits.password_reset_request');
        RateLimiter::for('auth.password_reset.request', function (Request $request) use ($reset) {
            $identity = $this->identity($request, 'phone', $this->identity($request, 'email'));

            return [
                $this->limit($reset['per_identity_max'], $reset['per_identity_window'], 'reset-id:'.$identity),
                $this->limit($reset['per_ip_max'], $reset['per_ip_window'], 'reset-ip:'.$request->ip()),
            ];
        });

        $refresh = config('dahab-auth.rate_limits.refresh');
        RateLimiter::for('auth.refresh', function (Request $request) use ($refresh) {
            $token = $request->user()?->currentAccessToken();
            $key = $token?->family_id ?? $request->ip();

            return $this->limit($refresh['per_family_max'], $refresh['per_family_window'], 'refresh:'.$key);
        });

        RateLimiter::for('auth.otp.send', function (Request $request) {
            $phone = $this->identity($request, 'phone');
            $cooldown = (int) config('dahab-auth.otp.send_cooldown_seconds');
            $perHour = (int) config('dahab-auth.otp.max_sends_per_hour');

            return [
                $this->limit(1, max($cooldown, 1), 'otp-cooldown:'.$phone),
                Limit::perHour($perHour)->by('otp-hourly:'.$phone),
            ];
        });

        RateLimiter::for('auth.otp.verify', function (Request $request) {
            $challenge = $this->identity($request, 'challenge_id');
            $max = (int) config('dahab-auth.otp.max_verify_attempts');

            return Limit::perMinutes(5, $max)->by('otp-verify:'.$challenge);
        });
    }

    private function limit(int $max, int $windowSeconds, string $key): Limit
    {
        return new Limit($key, $max, $windowSeconds);
    }

    /**
     * An exhausted identity bucket means "this account is locked", which clients
     * tell apart (`account_locked`) from a plain per-IP throttle
     * (`too_many_requests`, rendered centrally in bootstrap/app.php). Attaching
     * the code to the limit keeps that decision out of URL matching.
     */
    private function lockout(Limit $limit): Limit
    {
        return $limit->response(
            fn (Request $request, array $headers) => throw AuthApiException::accountLocked($headers)
        );
    }

    /** Normalised identifier from the request body, else $fallback, else the caller's IP. */
    private function identity(Request $request, string $field, ?string $fallback = null): string
    {
        $value = $request->input($field);

        if (is_string($value) && trim($value) !== '') {
            return mb_strtolower(trim($value));
        }

        return $fallback ?? (string) $request->ip();
    }

    private function configurePasswordPolicy(): void
    {
        $min = (int) config('dahab-auth.password.min_length', 10);
        $checkPwned = (bool) config('dahab-auth.password.check_pwned', true);

        Password::defaults(function () use ($min, $checkPwned) {
            $rule = Password::min($min);

            return $checkPwned && ! app()->environment('testing')
                ? $rule->uncompromised()
                : $rule;
        });
    }
}
