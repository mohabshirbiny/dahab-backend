<?php

namespace App\Actions\Legal;

use App\Enums\LegalDocumentCode;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\LegalDocument;
use App\Support\RequestContext;

/**
 * The versions of `terms` and `privacy` the app showed at sign-up (spec 019 FR-026).
 *
 * The ids are optional — an older app sends none, and meets the first-acceptance gate at its
 * first sign-in (D2). Ids that are sent must be the live version of the right document, or
 * the registration is refused `legal_document_not_current` *before* anything is created.
 */
final class RecordSignupAcceptancesAction
{
    /**
     * @param  array{terms?: ?int, privacy?: ?int}  $ids
     * @return list<LegalDocument>
     */
    public function validate(array $ids): array
    {
        $docs = [];
        foreach (['terms' => LegalDocumentCode::TERMS, 'privacy' => LegalDocumentCode::PRIVACY] as $key => $code) {
            $id = $ids[$key] ?? null;
            if ($id === null) {
                continue;
            }
            $live = LegalDocument::current($code->value);
            if ($live === null || $live->legal_doc_id !== (int) $id) {
                throw DomainApiException::legalDocumentNotCurrent();
            }
            $docs[] = $live;
        }

        return $docs;
    }

    /** @param  list<LegalDocument>  $docs */
    public function record(string $customerId, array $docs, ?RequestContext $ctx): void
    {
        foreach ($docs as $doc) {
            AgreementAcceptance::query()->create([
                'customer_id' => $customerId,
                'legal_doc_id' => $doc->legal_doc_id,
                'context' => 'signup',
                'ip_address' => filled($ctx?->ip) ? $ctx->ip : null,
                'device_fingerprint' => $ctx?->deviceFingerprintHash,
            ]);
        }
    }
}
