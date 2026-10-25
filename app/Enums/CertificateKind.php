<?php

namespace App\Enums;

/**
 * The certificate a seller brings with a stone piece (Part 3 §5 / OI-3.2). What each kind
 * costs the seller is a setting (the Dashboard's Rates page): an IGI certificate costs
 * nothing, another lab's is reduced, none is the full cost. Stored on the listing.
 */
enum CertificateKind: string
{
    case IGI = 'igi';
    case OTHER_LAB = 'other_lab';
    case NONE = 'none';

    public function costSetting(): SettingKey
    {
        return match ($this) {
            self::IGI => SettingKey::CERTIFICATE_IGI_COST_EGP,
            self::OTHER_LAB => SettingKey::CERTIFICATE_OTHER_LAB_COST_EGP,
            self::NONE => SettingKey::CERTIFICATE_NONE_COST_EGP,
        };
    }

    /** Only a piece with a stone carries a certificate. */
    public static function appliesTo(PieceCategory $category): bool
    {
        return $category !== PieceCategory::GOLD;
    }
}
