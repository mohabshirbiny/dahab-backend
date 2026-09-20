<?php

namespace App\Actions\Auth\Shared;

use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\SessionDto;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Exchanges a refresh token for a new access + refresh pair in the same
 * family. The caller has already been authenticated on its own guard and
 * checked for the `<kind>:refresh` ability; this action owns rotation and
 * replay detection, shared by the customer and dashboard surfaces.
 */
final class RotateRefreshTokenAction
{
    public function __construct(
        private readonly IssueTokenFamilyAction $issueTokens,
        private readonly RevokeTokenFamilyAction $revoke,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function execute(Customer|Staff $actor, mixed $currentToken): SessionDto
    {
        if (! $currentToken instanceof PersonalAccessToken || $currentToken->family_id === null) {
            throw AuthApiException::refreshInvalid();
        }

        $familyId = (string) $currentToken->family_id;

        // Atomic claim: only one caller can move rotated_at from NULL, so a
        // concurrent or replayed use of the same refresh token loses here.
        $claimed = DB::table('personal_access_tokens')
            ->where('id', $currentToken->getKey())
            ->whereNull('rotated_at')
            ->update(['rotated_at' => now()]);

        if ($claimed === 0) {
            // Replay of an already-rotated refresh token: burn the whole family.
            $this->revoke->byFamily($familyId);

            throw AuthApiException::refreshInvalid();
        }

        $actorKind = $actor instanceof Customer ? 'customer' : 'staff';

        return DB::transaction(function () use ($actor, $actorKind, $familyId, $currentToken) {
            // Retire the previous access token; the rotated refresh row stays
            // (marked) so a later replay is recognisable.
            DB::table('personal_access_tokens')
                ->where('family_id', $familyId)
                ->where('id', '!=', $currentToken->getKey())
                ->delete();

            $session = $this->issueTokens->execute($actor, $actorKind, $familyId);

            $this->audit->execute(
                AuditEvent::TOKEN_ROTATED,
                'success',
                ['family_id' => $familyId],
                entityType: 'token_family',
                actorCustomerId: $actor instanceof Customer ? $actor->getKey() : null,
                actorStaffId: $actor instanceof Staff ? $actor->getKey() : null,
            );

            return $session;
        });
    }
}
