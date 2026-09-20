<?php

namespace App\Actions\Auth\Shared;

use App\Enums\TokenAbility;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\SessionDto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasApiTokens;

final class IssueTokenFamilyAction
{
    /**
     * Mint an access + refresh pair. The access token carries only
     * `<kind>:access`, the refresh token only `<kind>:refresh`, so neither can
     * stand in for the other. Pass `$familyId` to rotate within a family.
     *
     * @param  'customer'|'staff'  $actorKind
     */
    public function execute(Model $tokenable, string $actorKind, ?string $familyId = null): SessionDto
    {
        if (! $tokenable instanceof HasApiTokens) {
            throw new \InvalidArgumentException('tokenable must use HasApiTokens');
        }

        $familyId ??= (string) Str::uuid();

        $accessTtlMinutes = (int) config('dahab-auth.access_ttl_minutes');
        $refreshTtlDays = (int) config('dahab-auth.refresh_ttl_days');

        $accessExpires = Carbon::now()->addMinutes($accessTtlMinutes);
        $refreshExpires = Carbon::now()->addDays($refreshTtlDays);

        $access = $tokenable->createToken('access', [TokenAbility::access($actorKind)->value], $accessExpires);
        $refresh = $tokenable->createToken('refresh', [TokenAbility::refresh($actorKind)->value], $refreshExpires);

        DB::table('personal_access_tokens')
            ->whereIn('id', [$access->accessToken->id, $refresh->accessToken->id])
            ->update([
                'family_id' => $familyId,
                'actor_kind' => $actorKind,
            ]);

        return new SessionDto(
            accessToken: $access->plainTextToken,
            accessTokenExpiresAt: $accessExpires->toDateTimeImmutable(),
            refreshToken: $refresh->plainTextToken,
            refreshTokenExpiresAt: $refreshExpires->toDateTimeImmutable(),
            familyId: $familyId,
        );
    }

    public function forCustomer(Customer $customer): SessionDto
    {
        return $this->execute($customer, 'customer');
    }

    public function forStaff(Staff $staff): SessionDto
    {
        return $this->execute($staff, 'staff');
    }
}
