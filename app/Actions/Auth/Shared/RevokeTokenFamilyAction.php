<?php

namespace App\Actions\Auth\Shared;

use App\Enums\AuditEvent;
use Illuminate\Support\Facades\DB;

final class RevokeTokenFamilyAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * Revoke every access + refresh token in one family by deleting the rows,
     * which is what makes Sanctum's standard authenticator refuse them without
     * extra hooks. (`personal_access_tokens.revoked_at` exists but is not written.)
     */
    public function byFamily(string $familyId): int
    {
        $rows = DB::table('personal_access_tokens')
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->count();

        DB::table('personal_access_tokens')
            ->where('family_id', $familyId)
            ->delete();

        if ($rows > 0) {
            $this->audit->execute(
                AuditEvent::TOKEN_FAMILY_REVOKED,
                'success',
                ['family_id' => $familyId, 'rows' => $rows],
            );
        }

        return $rows;
    }

    /** Revoke every token for one actor (logout-all). */
    public function forActor(string $tokenableType, string $tokenableId): int
    {
        $rows = DB::table('personal_access_tokens')
            ->where('tokenable_type', $tokenableType)
            ->where('tokenable_id', $tokenableId)
            ->count();

        DB::table('personal_access_tokens')
            ->where('tokenable_type', $tokenableType)
            ->where('tokenable_id', $tokenableId)
            ->delete();

        if ($rows > 0) {
            $this->audit->execute(
                AuditEvent::TOKEN_LOGOUT_ALL,
                'success',
                ['tokenable_type' => $tokenableType, 'tokenable_id' => $tokenableId, 'rows' => $rows],
            );
        }

        return $rows;
    }

    /** Revoke a single token (current logout). */
    public function byTokenId(int $tokenId): void
    {
        DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
    }
}
