<?php

namespace App\Actions\Disputes\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\DisputePhoto;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * A staff member opens one photo of a dispute (spec 014 FR-008, research R13):
 * decrypted from the private disk, never cached, one `dispute.photo_viewed`
 * audit row per view, written in the same transaction as the read.
 */
final class ViewDisputePhotoAction
{
    public function __construct(
        private readonly IdentityDocumentStorage $storage,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{bytes: string, mime: string} */
    public function handle(Staff $actor, string $disputeId, string $photoId, ?RequestContext $ctx = null): array
    {
        return DB::transaction(function () use ($actor, $disputeId, $photoId, $ctx) {
            $photo = DisputePhoto::query()->where('dispute_id', $disputeId)->whereKey($photoId)
                ->with('dispute:dispute_id,dispute_ref')->firstOrFail();

            $this->audit->execute(AuditEvent::DISPUTE_PHOTO_VIEWED, 'success',
                ['dispute_ref' => $photo->dispute->dispute_ref, 'photo_id' => $photo->photo_id, 'position' => $photo->position],
                'dispute', $disputeId, $ctx, actorStaffId: $actor->staff_id);

            return ['bytes' => $this->storage->read($photo->storage_ref), 'mime' => $photo->mime];
        });
    }
}
