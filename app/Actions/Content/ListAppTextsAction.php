<?php

namespace App\Actions\Content;

use App\Models\AppTextKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The App text list (spec 019 US1): every registered key with where it appears, the code
 * default, what is live, the pending draft, and whether the code's default moved since the
 * live override was written. Filters by kind, area, a search, whether a key is overridden and
 * its state; keyset-paginated by key. Relations are eager-loaded (no N+1).
 */
final class ListAppTextsAction
{
    public const RELATIONS = ['publishedVersion.publisher', 'draftVersion.editor'];

    /**
     * @param  array{kind?: ?string, area?: ?string, q?: ?string, changed?: ?bool, state?: ?string, include_retired?: bool}  $filters
     * @return array{rows: Collection<int, AppTextKey>, next_cursor: ?string}
     */
    public function handle(array $filters, ?string $afterKey, int $perPage): array
    {
        $rows = $this->filtered($filters)
            ->with(self::RELATIONS)
            ->when($afterKey !== null, fn (Builder $q) => $q->where('text_key', '>', $afterKey))
            ->orderBy('text_key')
            ->limit($perPage + 1)
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();

        return [
            'rows' => $rows,
            'next_cursor' => $more ? self::encode((string) $rows->last()->text_key) : null,
        ];
    }

    /** The filtered query, for the export too. */
    public function filtered(array $filters): Builder
    {
        $overridden = "EXISTS (SELECT 1 FROM app_text_version v WHERE v.text_key = app_text_key.text_key AND v.state = 'published' AND v.is_default_marker = FALSE)";
        $hasDraft = "EXISTS (SELECT 1 FROM app_text_version d WHERE d.text_key = app_text_key.text_key AND d.state = 'draft')";

        return AppTextKey::query()
            ->when(($filters['include_retired'] ?? false) !== true, fn (Builder $q) => $q->whereNull('retired_at'))
            ->when($filters['kind'] ?? null, fn (Builder $q, string $kind) => $q->where('kind', $kind))
            ->when($filters['area'] ?? null, fn (Builder $q, string $area) => $q->where('area', $area))
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', function (Builder $q) use ($filters) {
                $like = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
                $q->where(fn (Builder $w) => $w
                    ->where('text_key', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like)
                    ->orWhere('default_en', 'ilike', $like)
                    ->orWhere('default_ar', 'ilike', $like)
                    ->orWhereRaw("EXISTS (SELECT 1 FROM app_text_version t WHERE t.text_key = app_text_key.text_key AND t.state IN ('draft','published') AND (t.text_en ILIKE ? OR t.text_ar ILIKE ?))", [$like, $like]));
            })
            ->when(array_key_exists('changed', $filters) && $filters['changed'] !== null, fn (Builder $q) => $q->whereRaw(($filters['changed'] ? '' : 'NOT ').$overridden))
            ->when($filters['state'] ?? null, function (Builder $q, string $state) use ($overridden, $hasDraft) {
                match ($state) {
                    'draft' => $q->where(fn (Builder $w) => $w->whereRaw($hasDraft)->orWhereNotNull('pending_position')),
                    'published' => $q->whereRaw($overridden),
                    'default' => $q->whereRaw("NOT {$overridden}"),
                    default => null,
                };
            });
    }

    public static function encode(string $key): string
    {
        return rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $key = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $key !== false && preg_match('/^[a-z][a-z0-9_.]*$/', $key) === 1 ? $key : null;
    }
}
