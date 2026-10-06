<?php

namespace App\Actions\Account;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use App\Support\Account\CustomerSessions;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The customer's own sessions (spec 017 FR-020): the open ones, one per token
 * family, newest activity first, the current one marked; signing one out ends
 * it and, when it is tied to a device, every session of that device, and
 * forgets the device. The current session signs out through logout.
 */
final class CustomerSessionsAction
{
    public function __construct(
        private readonly CustomerSessions $sessions,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(Customer $customer, ?string $currentFamily): array
    {
        $rows = DB::table('personal_access_tokens')
            ->where('tokenable_type', $customer->getMorphClass())
            ->where('tokenable_id', $customer->customer_id)
            ->whereNotNull('family_id')
            ->groupBy('family_id')
            ->havingRaw('bool_or(expires_at IS NULL OR expires_at > now())')
            ->selectRaw('family_id, max(device_fingerprint_hash) AS fingerprint, max(device_platform) AS platform,
                min(created_at) AS started_at, max(COALESCE(last_used_at, created_at)) AS last_active_at')
            ->orderByDesc('last_active_at')->orderByDesc('family_id')
            ->get();

        $agents = CustomerTrustedDevice::query()->where('customer_id', $customer->customer_id)
            ->pluck('user_agent', 'fingerprint_hash');

        return $rows->map(fn (object $r) => [
            'session_id' => (string) $r->family_id,
            'platform' => $r->platform,
            'user_agent' => $r->fingerprint === null ? null : $agents->get($r->fingerprint),
            'device_known' => $r->fingerprint !== null && $agents->has($r->fingerprint),
            'started_at' => $r->started_at,
            'last_active_at' => $r->last_active_at,
            'is_current' => $currentFamily !== null && (string) $r->family_id === $currentFamily,
        ])->values()->all();
    }

    /** @return array{signed_out_sessions: int, device_forgotten: bool} */
    public function signOut(Customer $customer, string $familyId, ?string $currentFamily, ?RequestContext $ctx = null): array
    {
        if ($familyId === $currentFamily) {
            throw DomainApiException::currentSession();
        }

        return DB::transaction(function () use ($customer, $familyId, $ctx) {
            $result = $this->sessions->signOut($customer, $familyId) ?? throw new ModelNotFoundException;

            $this->audit->execute(AuditEvent::CUSTOMER_SESSION_SIGNED_OUT, 'success',
                ['session_id' => $familyId, 'sessions_ended' => $result['sessions'], 'device_forgotten' => $result['device_forgotten']],
                'customer', $customer->customer_id, $ctx, actorCustomerId: $customer->customer_id);

            return ['signed_out_sessions' => $result['sessions'], 'device_forgotten' => $result['device_forgotten']];
        });
    }
}
