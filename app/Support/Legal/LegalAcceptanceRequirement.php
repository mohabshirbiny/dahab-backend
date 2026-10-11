<?php

namespace App\Support\Legal;

use App\Enums\LegalDocumentCode;
use Illuminate\Support\Facades\DB;

/**
 * What a customer still has to accept before they carry on (spec 019 R8, FR-025).
 *
 * Computed on read from the published versions and the customer's own acceptances — never a
 * stored flag, so publishing a version needs no fan-out job. For each of `terms` and `privacy`
 * that has a published version:
 *
 * - `first` — the customer has no recorded acceptance of that document at all (D2);
 * - `material` — a version marked material, newer than the latest one they accepted, is
 *   published (Terms §13: "asked to accept it again before you continue").
 *
 * Declarations (ownership, deposit, …) are not gated here: each form re-accepts its own
 * current version.
 */
final class LegalAcceptanceRequirement
{
    public const FIRST = 'first';

    public const MATERIAL = 'material';

    /** @return list<array{code: string, version: int, legal_doc_id: int, reason: string}> */
    public static function for(string $customerId): array
    {
        $codes = LegalDocumentCode::acceptedByEveryone();

        $versions = DB::table('legal_document')->whereIn('code', $codes)
            ->orderBy('code')->orderByDesc('version')
            ->get(['legal_doc_id', 'code', 'version', 'is_material']);
        if ($versions->isEmpty()) {
            return [];
        }

        $accepted = DB::table('agreement_acceptance as a')
            ->join('legal_document as d', 'd.legal_doc_id', '=', 'a.legal_doc_id')
            ->where('a.customer_id', $customerId)
            ->whereIn('d.code', $codes)
            ->groupBy('d.code')
            ->selectRaw('d.code, MAX(d.version) AS latest')
            ->pluck('latest', 'code');

        $required = [];
        foreach ($versions->groupBy('code') as $code => $rows) {
            $live = $rows->first();
            $latestAccepted = $accepted[$code] ?? null;

            $reason = null;
            if ($latestAccepted === null) {
                $reason = self::FIRST;
            } elseif ((int) $latestAccepted < (int) $live->version
                && $rows->contains(fn ($r) => (bool) $r->is_material && (int) $r->version > (int) $latestAccepted)) {
                $reason = self::MATERIAL;
            }

            if ($reason !== null) {
                $required[] = ['code' => (string) $code, 'version' => (int) $live->version, 'legal_doc_id' => (int) $live->legal_doc_id, 'reason' => $reason];
            }
        }

        return $required;
    }
}
