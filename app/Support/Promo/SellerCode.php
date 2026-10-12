<?php

namespace App\Support\Promo;

use App\Models\PromoCode;

/**
 * A code the gate accepted for one seller: a promo code (its terms are on the row) or a referral
 * code (the inviter). `idKey` is what "once per ID" counted against (never returned by an API).
 */
final readonly class SellerCode
{
    public function __construct(
        public ?PromoCode $promo,
        public ?string $inviterId,
        public ?string $referralCode,
        public string $idKey,
    ) {}

    public function isReferral(): bool
    {
        return $this->promo === null;
    }

    public function value(): string
    {
        return $this->promo?->code ?? (string) $this->referralCode;
    }
}
