<?php

namespace App\Actions\Content;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\AppTextKey;
use App\Models\Staff;

/**
 * App texts as CSV (spec 019 FR-011; the spec 006/008/009 export pattern): key, kind, area,
 * English, Arabic, version, published date — what customers see for each key (the override,
 * else the code's default). UTF-8 with a BOM; spreadsheet formulas neutralised. Audited.
 */
final class ExportAppTextsAction
{
    public function __construct(
        private readonly ListAppTextsAction $list,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{csv: string, rows: int}
     */
    public function handle(Staff $viewer, array $filters): array
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Arabic
        $put = fn (array $cells) => fputcsv($out, array_map([self::class, 'cell'], $cells), escape: '');

        $put(['Key', 'Kind', 'Area', 'English', 'Arabic', 'Version', 'Published at']);

        $rows = 0;
        $this->list->filtered($filters)->with('publishedVersion')->orderBy('text_key')
            ->chunk(500, function ($keys) use ($put, &$rows) {
                /** @var AppTextKey $k */
                foreach ($keys as $k) {
                    $live = $k->effective();
                    $published = $live['overridden'] ? $k->publishedVersion : null;
                    $put([
                        $k->text_key, $k->kind, $k->area, $live['en'], $live['ar'],
                        $published?->version ?? '', $published?->published_at?->toIso8601String() ?? '',
                    ]);
                    $rows++;
                }
            });

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->execute(
            AuditEvent::CONTENT_EXPORTED,
            'success',
            ['filters' => array_filter($filters, fn ($v) => $v !== null && $v !== false), 'rows' => $rows],
            entityType: 'app_text',
            actorStaffId: $viewer->staff_id,
        );

        return ['csv' => $csv, 'rows' => $rows];
    }

    /** Neutralise spreadsheet formulas (the spec 006 rule). */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && ! is_numeric($value) ? "'".$value : $value;
    }
}
