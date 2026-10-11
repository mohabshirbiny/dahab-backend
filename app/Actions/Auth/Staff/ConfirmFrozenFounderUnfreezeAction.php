<?php

namespace App\Actions\Auth\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\FounderSecurity\FreezeFounderAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Models\AccountFreeze;
use App\Models\Staff;
use App\Support\Auth\StaffTotpVerifier;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The frozen founder gives the first unfreeze confirmation (spec 021 C1, research R8) without a
 * session: it proves the person still controls the password, the authenticator and a device
 * already known for them, records that, and nothing more. No token is ever minted.
 *
 * Checks in order (R16): password (uniform 401 `invalid_credentials`, like sign-in) → an open
 * freeze (409 `not_frozen`) → a known device (422 `device_id_required` / 403 `device_not_known`)
 * → the second factor, with the sign-in's replay guard (401 `mfa_invalid`) → slot 1 free
 * (409 `unfreeze_already_confirmed`). Failures are audited `founder.unfreeze_confirm_failed`.
 */
final class ConfirmFrozenFounderUnfreezeAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly StaffTotpVerifier $totp,
    ) {}

    /** @return array{freeze_id: string, slot: 1, waiting_for: 'founder'} */
    public function execute(string $email, string $password, ?string $code, ?string $recoveryCode, ?string $note, RequestContext $ctx): array
    {
        $staff = Staff::query()->where('email', $email)->with(['password', 'mfa'])->first();

        if ($staff === null || $staff->is_system || $staff->password === null) {
            $this->fail($ctx, 'unknown_account', null, $email);
            throw AuthApiException::invalidCredentials();
        }

        $actorCtx = $ctx->withStaff($staff->staff_id);

        if (! Hash::check($password, $staff->password->password_hash)) {
            $this->fail($actorCtx, 'wrong_password', $staff);
            throw AuthApiException::invalidCredentials();
        }
        if (! $staff->is_active || ! $staff->is_founder) {
            $this->fail($actorCtx, $staff->is_active ? 'not_a_founder' : 'inactive', $staff);
            throw AuthApiException::invalidCredentials();
        }

        $freeze = $staff->activeFreeze;
        if ($freeze === null) {
            $this->fail($actorCtx, 'not_frozen', $staff);
            throw DomainApiException::notFrozen();
        }

        if ($ctx->deviceFingerprintHash === null) {
            $this->fail($actorCtx, 'device_id_required', $staff);
            throw AuthApiException::deviceIdRequired();
        }
        if (! $staff->isKnownDevice($ctx->deviceFingerprintHash)) {
            $this->fail($actorCtx, 'device_not_known', $staff);
            throw DomainApiException::deviceNotKnown();
        }

        $method = $code !== null ? 'totp' : 'recovery_code';
        $valid = $staff->mfa !== null && ($code !== null
            ? $this->totp->verifyTotp($staff, $code)
            : $this->totp->redeemRecoveryCode($staff, (string) $recoveryCode));
        if (! $valid) {
            $this->fail($actorCtx, 'wrong_code', $staff, null, $method);
            throw AuthApiException::mfaInvalid();
        }

        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        $recorded = DB::transaction(function () use ($freeze, $staff, $actorCtx, $note, $method) {
            FreezeFounderAction::lock();

            $locked = AccountFreeze::query()->lockForUpdate()->find($freeze->freeze_id);
            if ($locked === null || ! $locked->isOpen() || $locked->unfreeze_confirm_1 !== null) {
                return false;
            }

            $locked->update([
                'unfreeze_confirm_1' => $staff->staff_id,
                'unfreeze_confirm_1_at' => now(),
                'unfreeze_confirm_1_kind' => AccountFreeze::KIND_FROZEN_FOUNDER,
            ]);

            $this->audit->execute(
                AuditEvent::FOUNDER_UNFREEZE_CONFIRMED,
                'success',
                ['freeze_id' => $locked->freeze_id, 'slot' => 1, 'kind' => AccountFreeze::KIND_FROZEN_FOUNDER, 'method' => $method],
                'staff',
                $staff->staff_id,
                $actorCtx,
                reason: $note,
            );

            return true;
        });

        if (! $recorded) {
            $this->fail($actorCtx, 'already_confirmed', $staff);
            throw DomainApiException::unfreezeAlreadyConfirmed();
        }

        return ['freeze_id' => $freeze->freeze_id, 'slot' => 1, 'waiting_for' => 'founder'];
    }

    private function fail(RequestContext $ctx, string $reason, ?Staff $staff, ?string $email = null, ?string $method = null): void
    {
        $this->audit->execute(
            AuditEvent::FOUNDER_UNFREEZE_CONFIRM_FAILED,
            'failure',
            array_filter(['reason' => $reason, 'email' => $email, 'method' => $method]),
            'staff',
            $staff?->staff_id,
            $ctx,
        );
    }
}
