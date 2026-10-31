<?php

namespace App\Enums;

/**
 * The certificate a seller brings with a stone piece: from a listed lab (the listing names it),
 * from another lab (the seller types its name), or none. What it costs depends on the lab that
 * inspects the piece, see App\Support\Listings\CertificateFee. Stored on the listing.
 */
enum CertificateKind: string
{
    case LAB = 'lab';
    case OTHER_LAB = 'other_lab';
    case NONE = 'none';

    /** The value older clients send for IGI, read as `lab` with the main lab. */
    public const LEGACY_IGI = 'igi';

    /** Only a piece with a stone carries a certificate. */
    public static function appliesTo(PieceCategory $category): bool
    {
        return $category !== PieceCategory::GOLD;
    }
}
