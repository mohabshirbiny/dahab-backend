<?php

namespace App\Actions\Finance;

use App\Enums\UploadPurpose;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use App\Services\UploadTokenStore;
use Illuminate\Http\UploadedFile;

/**
 * A staff member's upload (spec 015 research R5): stored encrypted whole like
 * the customers' private files, under `bank-proofs/{staff_id}/`, and a
 * single-use token bound to that staff member and the purpose, so the POST
 * that uses it stays JSON (and covered by its Idempotency-Key).
 */
final class CreateStaffUploadAction
{
    public function __construct(
        private readonly IdentityDocumentStorage $storage,
        private readonly UploadTokenStore $tokens,
    ) {}

    /** The token owner of a staff member (customer tokens use the bare customer id). */
    public static function owner(Staff $staff): string
    {
        return 'staff:'.$staff->staff_id;
    }

    /** @return array{token: string, expires_in: int} */
    public function handle(Staff $staff, UploadPurpose $purpose, UploadedFile $file): array
    {
        $ref = $this->storage->storeAt('bank-proofs', $staff->staff_id, $file);

        return $this->tokens->issue(self::owner($staff), $purpose, $ref, $file->getMimeType());
    }
}
