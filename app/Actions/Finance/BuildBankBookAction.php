<?php

namespace App\Actions\Finance;

use App\Support\Finance\BankBookQuery;

/** The bank book for a Cairo period, one page (spec 015 FR-011). */
final class BuildBankBookAction
{
    /** @return array{rows: list<array<string, mixed>>, next_cursor: string|null, summary: array<string, string>} */
    public function handle(string $from, string $to, ?string $cursor, int $perPage): array
    {
        $book = new BankBookQuery($from, $to);
        $summary = $book->summary();
        $rows = $book->rows($summary['opening'], BankBookQuery::decodeCursor($cursor), $perPage + 1);
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        return [
            'rows' => $rows,
            'next_cursor' => $more && $rows !== [] ? BankBookQuery::encodeCursor(end($rows)['posting_id']) : null,
            'summary' => $summary,
        ];
    }
}
