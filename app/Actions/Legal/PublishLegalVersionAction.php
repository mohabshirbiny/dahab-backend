<?php

namespace App\Actions\Legal;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\LegalDocumentCode;
use App\Exceptions\DomainApiException;
use App\Models\LegalDocument;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Publish a new version of a legal document (spec 019 FR-021, FR-030; CEO only, D1).
 *
 * A whole new version, never an edit: version = latest + 1 under a per-document lock, both
 * languages required, `is_material` as chosen. The text is fixed — no placeholders — so what
 * a customer accepted never changes meaning (FR-022). Earlier versions are untouched forever
 * (database guard DH018). The audit entry carries each body's length and sha256, not the body.
 */
final class PublishLegalVersionAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{legal_doc_id: int, code: string, version: int, published_at: string, is_material: bool} */
    public function handle(Staff $actor, string $code, ?string $bodyEn, ?string $bodyAr, bool $isMaterial, ?RequestContext $ctx = null): array
    {
        $enum = LegalDocumentCode::tryFrom($code) ?? throw DomainApiException::legalCodeUnknown();

        $en = trim((string) $bodyEn);
        $ar = trim((string) $bodyAr);
        if ($en === '' || $ar === '') {
            throw DomainApiException::legalBodyRequired();
        }

        try {
            return DB::transaction(function () use ($actor, $enum, $en, $ar, $isMaterial, $ctx) {
                // One publish of one document at a time; two of them give consecutive versions.
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['legal_publish:'.$enum->value]);

                $previous = LegalDocument::current($enum->value);
                $doc = LegalDocument::query()->create([
                    'code' => $enum->value,
                    'version' => ($previous?->version ?? 0) + 1,
                    'body_en' => $en,
                    'body_ar' => $ar,
                    'is_material' => $isMaterial,
                    'published_by' => $actor->staff_id,
                ])->refresh();

                $this->audit->execute(
                    AuditEvent::LEGAL_PUBLISHED,
                    'success',
                    [
                        'code' => $enum->value,
                        'version' => $doc->version,
                        'replaces_version' => $previous?->version,
                        'is_material' => $isMaterial,
                        'legal_doc_id' => $doc->legal_doc_id,
                        'body_en' => ['length' => mb_strlen($en), 'sha256' => hash('sha256', $en)],
                        'body_ar' => ['length' => mb_strlen($ar), 'sha256' => hash('sha256', $ar)],
                    ],
                    entityType: 'legal_document',
                    ctx: $ctx,
                    actorStaffId: $actor->staff_id,
                );

                return [
                    'legal_doc_id' => $doc->legal_doc_id,
                    'code' => $enum->value,
                    'version' => (int) $doc->version,
                    'published_at' => $doc->published_at->toIso8601String(),
                    'is_material' => $isMaterial,
                ];
            });
        } catch (QueryException $e) {
            // UNIQUE (code, version) lost a race the lock did not cover.
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw DomainApiException::legalVersionConflict();
            }

            throw $e;
        }
    }
}
