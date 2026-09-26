<?php

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

final class RequestContext
{
    public function __construct(
        public readonly ?string $customerId,
        public readonly ?string $staffId,
        public readonly string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $deviceId,
        public readonly ?string $deviceFingerprintHash,
    ) {
        if ($this->customerId !== null && $this->staffId !== null) {
            throw new RuntimeException('RequestContext must not carry both a customer and a staff actor');
        }
    }

    public static function anonymous(Request $request, ?string $deviceFingerprintHash = null): self
    {
        return new self(
            customerId: null,
            staffId: null,
            ip: (string) $request->ip(),
            userAgent: $request->userAgent(),
            deviceId: $request->header('X-Device-Id'),
            deviceFingerprintHash: $deviceFingerprintHash,
        );
    }

    public static function forCustomer(Request $request, string $customerId, ?string $deviceFingerprintHash = null): self
    {
        return new self(
            customerId: $customerId,
            staffId: null,
            ip: (string) $request->ip(),
            userAgent: $request->userAgent(),
            deviceId: $request->header('X-Device-Id'),
            deviceFingerprintHash: $deviceFingerprintHash,
        );
    }

    public static function forStaff(Request $request, string $staffId, ?string $deviceFingerprintHash = null): self
    {
        return new self(
            customerId: null,
            staffId: $staffId,
            ip: (string) $request->ip(),
            userAgent: $request->userAgent(),
            deviceId: $request->header('X-Device-Id'),
            deviceFingerprintHash: $deviceFingerprintHash,
        );
    }

    /**
     * Context for scheduled jobs and other background work: attributed to the
     * system actor (spec 002 FR-062), so audit and ledger actor rules hold.
     * A job binds it (`app()->instance(RequestContext::class, …)`) or passes
     * it to each Action before changing state.
     */
    public static function forSystem(): self
    {
        return new self(
            customerId: null,
            staffId: SystemActor::id(),
            ip: '127.0.0.1',
            userAgent: 'system',
            deviceId: null,
            deviceFingerprintHash: null,
        );
    }

    /** The same request metadata, attributed to a staff actor (e.g. once a sign-in resolves the row). */
    public function withStaff(string $staffId): self
    {
        return new self(
            customerId: null,
            staffId: $staffId,
            ip: $this->ip,
            userAgent: $this->userAgent,
            deviceId: $this->deviceId,
            deviceFingerprintHash: $this->deviceFingerprintHash,
        );
    }

    public function hasActor(): bool
    {
        return $this->customerId !== null || $this->staffId !== null;
    }

    public function assertHasActor(): void
    {
        if (! $this->hasActor()) {
            throw new RuntimeException('RequestContext has no actor; refusing state change');
        }
    }
}
