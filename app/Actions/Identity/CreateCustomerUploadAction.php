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
        $ref = $this->storage->store($actor->customer_id, $file);

        return $this->tokens->issue($actor->customer_id, $purpose, $ref);
    }
}
