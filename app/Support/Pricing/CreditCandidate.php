<?php

namespace App\Support\Pricing;

use App\Enums\CreditKind;
use App\Enums\CreditSource;

/**
 * One credit a settlement may spend (spec 023 research R3). Built from a `commission_credit` row by
 * the caller, so the resolver stays pure.
 */
final readonly class CreditCandidate
{
    public function __construct(
        public string $id,
        public CreditKind $kind,
        public CreditSource $source,
        public ?string $percentOff,
        public ?string $amountLeft,
        public ?string $expiresAt,
        public string $grantedAt,
    ) {}
}
