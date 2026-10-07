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
use App\Services\ContactChangeChallengeStore;
use App\Support\Account\ContactMask;
use App\Support\Account\CustomerSessions;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalSafetyStop;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Prove the new number with its code and move the account to it (spec 017
 * FR-002). In one transaction, under the payout-account locks then the
 * customer row: the number changes, withdrawals not yet released are cancelled
 * and new ones pause (the spec 013 safety stop), every other session ends and
 * every other trusted device is forgotten. After commit the old number and the
 * email are told.
 */
final class ConfirmPhoneChangeAction
{
    public function __construct(
        private readonly ContactChangeChallengeStore $challenges,
        private readonly WithdrawalSafetyStop $safetyStop,
        private readonly CustomerSessions $sessions,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{customer: Customer, pause: ?WithdrawalPause, cancelled: list<string>} */
    public function handle(Customer $customer, string $challengeId, string $code, ?string $currentFamily, ?string $currentFingerprint, ?RequestContext $ctx = null): array
    {
        $newPhone = $this->spend($customer, $challengeId, $code);

        try {
            $result = DB::transaction(function () use ($customer, $newPhone, $currentFamily, $currentFingerprint, $ctx) {
                $this->safetyStop->lockPayoutAccounts($customer->customer_id);
                $locked = Customer::query()->whereKey($customer->customer_id)->lockForUpdate()->firstOrFail();

                if (RequestPhoneChangeAction::phoneTaken($newPhone, $locked->customer_id)) {
                    throw DomainApiException::contactTaken();
                }
                $oldPhone = $locked->phone;
                $locked->forceFill(['phone' => $newPhone])->save();

                ['pause' => $pause, 'cancelled' => $cancelled] = $this->safetyStop->apply(
                    $locked->customer_id, PauseTrigger::PHONE_CHANGE, 'phone number changed', actorCustomerId: $locked->customer_id);

                $ended = $this->sessions->endOthers($locked, $currentFamily);
                $forgotten = $this->sessions->forgetOthers($locked, $currentFingerprint);

                $this->audit->execute(AuditEvent::CUSTOMER_PHONE_CHANGED, 'success', [
                    'pause_until' => $pause?->pause_until?->toIso8601String(),
                    'cancelled_withdrawals' => $cancelled,
                    'sessions_ended' => $ended,
                    'devices_forgotten' => $forgotten,
                ], 'customer', $locked->customer_id, $ctx, actorCustomerId: $locked->customer_id,
                    before: ['phone' => ContactMask::phone($oldPhone)], reason: null);

                $lang = $locked->preferred_lang ?? 'ar';
                $notice = fn (?array $only) => new AccountNotification(AccountEvent::PHONE_CHANGED, $lang,
                    detail: ContactMask::phone($newPhone), pauseUntil: $pause?->pause_until?->toIso8601String(),
                    cancelledWithdrawals: count($cancelled), only: $only);
                DB::afterCommit(function () use ($oldPhone, $locked, $notice) {
                    Notification::route('sms', $oldPhone)->notify($notice(['sms']));
                    $locked->notify($notice(['inbox', 'mail']));
                });

                return ['customer' => $locked, 'pause' => $pause, 'cancelled' => $cancelled];
            });
        } catch (UniqueConstraintViolationException) {
            // Another customer took the number between the check and the write.
            throw DomainApiException::contactTaken();
        }

        return $result;
    }

    /** Check the code under the customer's lock; a right code is spent at once. @return string the new number */
    private function spend(Customer $customer, string $challengeId, string $code): string
    {
        $lock = $this->challenges->lock($customer->customer_id);
        $lock->block(5);
        try {
            $challenge = $this->challenges->find($challengeId);
            if ($challenge === null || $challenge['customer_id'] !== $customer->customer_id || ($challenge['kind'] ?? null) !== 'phone') {
                throw DomainApiException::changeCodeInvalid(0);
            }

            $max = (int) config('dahab-auth.otp.max_verify_attempts');
            if ($challenge['attempts'] >= $max) {
                $this->challenges->forget($challengeId);
                throw DomainApiException::changeCodeLocked();
            }

            if (! Hash::check($code, $challenge['code_hash'])) {
                $challenge['attempts']++;
                if ($challenge['attempts'] >= $max) {
                    $this->challenges->forget($challengeId);
                    throw DomainApiException::changeCodeLocked();
                }
                $this->challenges->save($challengeId, $challenge);
                throw DomainApiException::changeCodeInvalid($max - $challenge['attempts']);
            }

            $this->challenges->forget($challengeId);

            return (string) $challenge['new_phone'];
        } finally {
            $lock->release();
        }
    }
}
