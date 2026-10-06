<?php

namespace App\Actions\Account;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Notifications\EmailChangeLinkNotification;
use App\Support\Account\ContactMask;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use App\Support\Withdrawals\ConfirmationTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ask to move the account to a new email (spec 017 FR-010): a single-use link
 * goes to the new address (`one_time_token` purpose `email_change`, only its
 * HMAC stored, research R2). A new request spends the older open links.
 */
final class RequestEmailChangeAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{expires_at: Carbon, email_masked: string} */
    public function handle(Customer $customer, string $email, ?RequestContext $ctx = null): array
    {
        if ($customer->email !== null && mb_strtolower($customer->email) === mb_strtolower($email)) {
            throw DomainApiException::sameContact();
        }
        if (self::emailTaken($email, $customer->customer_id)) {
            throw DomainApiException::contactTaken();
        }

        $token = ConfirmationTokens::generate();
        $minutes = (int) config('dahab-account.email_change_minutes');
        $expiresAt = now()->addMinutes($minutes);

        DB::transaction(function () use ($customer, $email, $token, $expiresAt) {
            DB::table('one_time_token')->where('actor_customer_id', $customer->customer_id)
                ->where('purpose', 'email_change')->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('one_time_token')->insert([
                'token_hash' => ConfirmationTokens::hash($token),
                'purpose' => 'email_change',
                'actor_customer_id' => $customer->customer_id,
                'payload' => json_encode(['new_email' => $email], JSON_THROW_ON_ERROR),
                'expires_at' => $expiresAt,
            ]);
        });

        Notification::route('mail', $email)->notify(new EmailChangeLinkNotification(
            config('dahab-account.email_change_url').'?token='.$token, $customer->preferred_lang ?? 'ar', $minutes));

        $this->audit->execute(AuditEvent::CUSTOMER_EMAIL_CHANGE_REQUESTED, 'success',
            ['new_email' => ContactMask::email($email)], 'customer', $customer->customer_id, $ctx,
            actorCustomerId: $customer->customer_id);

        return ['expires_at' => $expiresAt, 'email_masked' => (string) ContactMask::email($email)];
    }

    public static function emailTaken(string $email, string $exceptCustomerId): bool
    {
        return DatabaseActor::elevate('system', fn () => Customer::query()
            ->whereRaw('lower(email::text) = ?', [mb_strtolower($email)])
            ->where('customer_id', '!=', $exceptCustomerId)->exists());
    }
}
