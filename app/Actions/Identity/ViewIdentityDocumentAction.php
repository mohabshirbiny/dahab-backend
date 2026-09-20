<?php

namespace App\Actions\Identity;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Hands a reviewer one side of a decrypted identity image. The view log
 * row and the read share one transaction (docs Part 1 §5.4): the log is
 * written first, and the image is only returned if the whole transaction
 * commits — so a view can never occur without its log, and a failed read
 * leaves no phantom log behind.
 */
final class ViewIdentityDocumentAction
{
    public const SIDE_FRONT = 'front';

    public const SIDE_BACK = 'back';

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly IdentityDocumentStorage $storage,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @return array{bytes: string, mime: string}
     */
    public function handle(Staff $actor, string $documentId, string $side, RequestContext $ctx): array
    {
        if (! in_array($side, [self::SIDE_FRONT, self::SIDE_BACK], true)) {
            throw DomainApiException::documentImageDeleted();
        }

        return DB::transaction(function () use ($actor, $documentId, $side, $ctx) {
            $document = IdentityDocument::query()->findOrFail($documentId);

            if ($document->image_deleted_at !== null) {
                throw DomainApiException::documentImageDeleted();
            }

            $ref = $side === self::SIDE_FRONT ? $document->front_ref : $document->back_ref;

            if ($ref === null) {
                throw DomainApiException::documentImageDeleted();
            }

            DocumentViewLog::query()->create([
                'document_id' => $document->document_id,
                'viewed_by' => $actor->staff_id,
                'ip_address' => $ctx->ip,
            ]);

            $this->audit->execute(
                AuditEvent::IDENTITY_DOCUMENT_VIEWED,
                'success',
                ['status' => $document->status->value, 'side' => $side],
                'identity_document',
                $document->document_id,
                $ctx,
                actorStaffId: $actor->staff_id,
            );

            $bytes = $this->storage->read($ref);

            return ['bytes' => $bytes, 'mime' => $this->mimeOf($bytes)];
        });
    }

    private function mimeOf(string $bytes): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, self::IMAGE_MIMES, true) ? $mime : 'application/octet-stream';
    }
}
