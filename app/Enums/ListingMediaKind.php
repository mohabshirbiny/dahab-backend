<?php

namespace App\Enums;

/** `listing_media.kind` (schema §7). Only the invoice is private (spec 010). */
enum ListingMediaKind: string
{
    case PHOTO = 'photo';
    case VIDEO = 'video';
    case INVOICE = 'invoice';
    case STONE_CERTIFICATE = 'stone_certificate';

    public function isPrivate(): bool
    {
        return $this === self::INVOICE;
    }

    /** The upload purpose a token for this kind must carry. */
    public function purpose(): UploadPurpose
    {
        return match ($this) {
            self::PHOTO => UploadPurpose::LISTING_PHOTO,
            self::VIDEO => UploadPurpose::LISTING_VIDEO,
            self::INVOICE => UploadPurpose::LISTING_INVOICE,
            self::STONE_CERTIFICATE => UploadPurpose::STONE_CERTIFICATE,
        };
    }

    /** How many of this kind one listing may hold. */
    public function limit(): int
    {
        return $this === self::PHOTO ? (int) config('dahab-listings.max_photos') : 1;
    }
}
