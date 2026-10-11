<?php

namespace App\Actions\Legal;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\LegalDocumentCode;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Support\Legal\LegalAcceptanceRequirement;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * The customer accepts the live version of `terms` or `privacy` (spec 019 FR-025, D2).
 *
 * One immutable acceptance row with the document, version, time, address and device — the
 * evidence trail — in context `first_acceptance` or `material_reaccept`, whichever the
 * requirement asked for. Allowed in every account state, a suspended customer included.
 */
final class AcceptLegalVersionAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{code: string, version: int, accepted_at: string, remaining: list<array<string, mixed>>} */
    public function handle(Customer $customer, int $legalDocId, ?RequestContext $ctx = null): array
    {
        return DB::transaction(function () use ($customer, $legalDocId, $ctx) {
            $doc = LegalDocument::query()->find($legalDocId);
            if ($doc === null || ! self::acceptedByEveryone($doc->code)) {
                throw DomainApiException::legalDocumentNotCurrent();
            }

            $live = LegalDocument::current($doc->code);
            if ($live === null || $live->legal_doc_id !== $doc->legal_doc_id) {
                throw DomainApiException::legalDocumentNotCurrent();
            }

            $need = collect(LegalAcceptanceRequirement::for($customer->customer_id))->firstWhere('code', $doc->code);
            if ($need === null) {
                throw DomainApiException::legalAcceptanceNotRequired();
            }

            $acceptance = AgreementAcceptance::query()->create([
                'customer_id' => $customer->customer_id,
                'legal_doc_id' => $doc->legal_doc_id,
                'context' => $need['reason'] === LegalAcceptanceRequirement::FIRST ? 'first_acceptance' : 'material_reaccept',
                'ip_address' => filled($ctx?->ip) ? $ctx->ip : null,
                'device_fingerprint' => $ctx?->deviceFingerprintHash,
            ]);

            $this->audit->execute(
                AuditEvent::LEGAL_ACCEPTED,
                'success',
                ['code' => $doc->code, 'version' => $doc->version, 'legal_doc_id' => $doc->legal_doc_id, 'context' => $acceptance->context],
                entityType: 'legal_document',
                ctx: $ctx,
                actorCustomerId: $customer->customer_id,
            );

            return [
                'code' => $doc->code,
                'version' => (int) $doc->version,
                'accepted_at' => now()->toIso8601String(),
                'remaining' => LegalAcceptanceRequirement::for($customer->customer_id),
            ];
        });
    }

    private static function acceptedByEveryone(string $code): bool
    {
        return in_array($code, LegalDocumentCode::acceptedByEveryone(), true);
    }
}
