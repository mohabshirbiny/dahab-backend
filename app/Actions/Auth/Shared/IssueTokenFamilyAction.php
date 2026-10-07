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
     * Spec 017 (research R4): the tokens remember the device that signed in;
     * a rotation keeps the device of its family.
     *
     * @param  'customer'|'staff'  $actorKind
     */
    public function execute(Model $tokenable, string $actorKind, ?string $familyId = null, ?string $deviceFingerprintHash = null, ?string $devicePlatform = null): SessionDto
    {
        if (! $tokenable instanceof HasApiTokens) {
            throw new \InvalidArgumentException('tokenable must use HasApiTokens');
        }

        if ($familyId !== null) {
            $device = DB::table('personal_access_tokens')->where('family_id', $familyId)
                ->whereNotNull('device_fingerprint_hash')->first(['device_fingerprint_hash', 'device_platform']);
            $deviceFingerprintHash ??= $device?->device_fingerprint_hash;
            $devicePlatform ??= $device?->device_platform;
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
                'device_fingerprint_hash' => $deviceFingerprintHash,
                'device_platform' => $devicePlatform,
            ]);

        return new SessionDto(
            accessToken: $access->plainTextToken,
            accessTokenExpiresAt: $accessExpires->toDateTimeImmutable(),
            refreshToken: $refresh->plainTextToken,
            refreshTokenExpiresAt: $refreshExpires->toDateTimeImmutable(),
            familyId: $familyId,
        );
    }

    public function forCustomer(Customer $customer, ?string $deviceFingerprintHash = null): SessionDto
    {
        return $this->execute($customer, 'customer', null, $deviceFingerprintHash, $deviceFingerprintHash === null ? null : self::platform());
    }

    /** The platform the app declares (`X-Device-Platform`, as the fingerprint), or web. */
    public static function platform(): string
    {
        $platform = strtolower((string) request()?->header('X-Device-Platform', 'web'));

        return in_array($platform, ['ios', 'android', 'web'], true) ? $platform : 'web';
    }

    public function forStaff(Staff $staff): SessionDto
    {
        return $this->execute($staff, 'staff');
    }
}
