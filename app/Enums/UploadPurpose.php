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
}
