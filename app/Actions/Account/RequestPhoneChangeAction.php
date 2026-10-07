<?php

namespace App\Actions\Account;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Notifications\PhoneChangeCodeNotification;
use App\Services\ContactChangeChallengeStore;
use App\Support\Account\ContactMask;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Ask to move the account to a new phone number (spec 017 FR-001): a code goes
 * to the new number only; a number another customer holds is refused before any
 * SMS. A new request replaces the previous one.
 */
final class RequestPhoneChangeAction
{
    public function __construct(
        private readonly ContactChangeChallengeStore $challenges,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{challenge_id: string, expires_at: Carbon, phone_masked: string} */
    public function handle(Customer $customer, string $phone, ?RequestContext $ctx = null): array
    {
        if ($phone === $customer->phone) {
            throw DomainApiException::sameContact();
        }
        if (self::phoneTaken($phone, $customer->customer_id)) {
            throw DomainApiException::contactTaken();
        }

        $lock = $this->challenges->lock($customer->customer_id);
        $lock->block(5);
        try {
            $code = self::newCode();
            $challenge = $this->challenges->begin($customer->customer_id, [
                'kind' => 'phone',
                'new_phone' => $phone,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
            ]);
        } finally {
            $lock->release();
        }

        Notification::route('sms', $phone)->notify(new PhoneChangeCodeNotification($code, $customer->preferred_lang ?? 'ar'));

        $this->audit->execute(AuditEvent::CUSTOMER_PHONE_CHANGE_REQUESTED, 'success',
            ['new_phone' => ContactMask::phone($phone)], 'customer', $customer->customer_id, $ctx,
            actorCustomerId: $customer->customer_id);

        return ['challenge_id' => $challenge['id'], 'expires_at' => $challenge['expires_at'], 'phone_masked' => (string) ContactMask::phone($phone)];
    }

    /** Another customer holds the number — read across customers (row-level security hides them). */
    public static function phoneTaken(string $phone, string $exceptCustomerId): bool
    {
        return DatabaseActor::elevate('system', fn () => Customer::query()
            ->where('phone', $phone)->where('customer_id', '!=', $exceptCustomerId)->exists());
    }

    private static function newCode(): string
    {
        $length = (int) config('dahab-auth.otp.code_length', 6);

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
