<?php

namespace App\Enums;

/**
 * What a customer upload is for (docs Part 2, `POST /me/uploads`). Only the
 * purposes that have a consumer are listed.
 */
enum UploadPurpose: string
{
    case IDENTITY = 'identity';

    /** A top-up receipt: image or PDF, trade-gated (spec 009 FR-011, research R7). */
    case TOPUP_RECEIPT = 'topup_receipt';

    /** Listing media (spec 010 FR-009, FR-012), all trade-gated and stored chunk-encrypted. */
    case LISTING_PHOTO = 'listing_photo';
    case LISTING_VIDEO = 'listing_video';
    case LISTING_INVOICE = 'listing_invoice';
    case STONE_CERTIFICATE = 'stone_certificate';

    /** A photo attached to a dispute (spec 014 R13): verified customers, not trade-gated. */
    case DISPUTE_PHOTO = 'dispute_photo';

    /** The front of a collection proxy's ID (Part 2 §3; spec 014 R14): trade-gated. */
    case PROXY_ID = 'proxy_id';

    /** @return list<string> the accepted file extensions */
    public function allowedMimes(): array
    {
        return match ($this) {
            self::IDENTITY, self::DISPUTE_PHOTO, self::PROXY_ID => config('dahab-identity.allowed_mimes'),
            self::TOPUP_RECEIPT => [...config('dahab-identity.allowed_mimes'), 'pdf'],
            self::LISTING_PHOTO => config('dahab-listings.photo_mimes'),
            self::LISTING_VIDEO => config('dahab-listings.video_mimes'),
            self::LISTING_INVOICE, self::STONE_CERTIFICATE => config('dahab-listings.document_mimes'),
        };
    }

    /** The largest accepted file, in kilobytes. */
    public function maxKilobytes(): int
    {
        return (int) match ($this) {
            self::IDENTITY, self::TOPUP_RECEIPT, self::DISPUTE_PHOTO, self::PROXY_ID => config('dahab-identity.max_upload_kb'),
            self::LISTING_PHOTO => config('dahab-listings.photo_max_kb'),
            self::LISTING_VIDEO => config('dahab-listings.video_max_kb'),
            self::LISTING_INVOICE, self::STONE_CERTIFICATE => config('dahab-listings.document_max_kb'),
        };
    }

    /**
     * Identity uploads stay open to unverified customers; a dispute photo needs a
     * verified customer, suspended or not (spec 014 FR-005); everything else needs
     * the trade gate.
     */
    public function requiresTrade(): bool
    {
        return ! in_array($this, [self::IDENTITY, self::DISPUTE_PHOTO], true);
    }

    /** Needs a verified customer but not the trade gate (spec 014). */
    public function requiresVerified(): bool
    {
        return $this === self::DISPUTE_PHOTO;
    }

    /** Listing media is encrypted and served in chunks (spec 010 research R7). */
    public function isListingMedia(): bool
    {
        return in_array($this, [self::LISTING_PHOTO, self::LISTING_VIDEO, self::LISTING_INVOICE, self::STONE_CERTIFICATE], true);
    }
}
