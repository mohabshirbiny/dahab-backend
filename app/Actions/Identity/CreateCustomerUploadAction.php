<?php

namespace App\Actions\Identity;

use App\Enums\UploadPurpose;
use App\Models\Customer;
use App\Services\IdentityDocumentStorage;
use App\Services\UploadTokenStore;
use Illuminate\Http\UploadedFile;

final class CreateCustomerUploadAction
{
    public function __construct(
        private readonly IdentityDocumentStorage $storage,
        private readonly UploadTokenStore $tokens,
    ) {}

    /**
     * @return array{token: string, expires_in: int}
     */
    public function handle(Customer $actor, UploadPurpose $purpose, UploadedFile $file): array
    {
        $ref = match ($purpose) {
            UploadPurpose::IDENTITY => $this->storage->store($actor->customer_id, $file),
            UploadPurpose::TOPUP_RECEIPT => $this->storage->storeAt('topup-receipts', $actor->customer_id, $file),
            // Spec 010: listing media is encrypted in chunks, never whole in memory.
            UploadPurpose::LISTING_PHOTO,
            UploadPurpose::LISTING_VIDEO,
            UploadPurpose::LISTING_INVOICE,
            UploadPurpose::STONE_CERTIFICATE => $this->storage->storeChunkedAt('listing-media', $actor->customer_id, $file),
            // Spec 014: private images, encrypted whole like identity documents.
            UploadPurpose::DISPUTE_PHOTO => $this->storage->storeAt('dispute-photos', $actor->customer_id, $file),
            UploadPurpose::PROXY_ID => $this->storage->storeAt('proxy-ids', $actor->customer_id, $file),
        };

        return $this->tokens->issue($actor->customer_id, $purpose, $ref, $file->getMimeType());
    }
}
