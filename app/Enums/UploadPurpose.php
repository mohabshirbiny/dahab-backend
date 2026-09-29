<?php

namespace App\Enums;

/**
 * What a customer upload is for (docs Part 2, `POST /me/uploads`). Only the
 * purposes that have a consumer are listed; listing photos, invoices, stone
 * certificates and proxy IDs join when their endpoints exist.
 */
enum UploadPurpose: string
{
    case IDENTITY = 'identity';

    /** A top-up receipt: image or PDF, trade-gated (spec 009 FR-011, research R7). */
    case TOPUP_RECEIPT = 'topup_receipt';

    /** @return list<string> the accepted file extensions */
    public function allowedMimes(): array
    {
        return match ($this) {
            self::IDENTITY => config('dahab-identity.allowed_mimes'),
            self::TOPUP_RECEIPT => [...config('dahab-identity.allowed_mimes'), 'pdf'],
        };
    }
}
