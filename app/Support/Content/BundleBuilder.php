<?php

namespace App\Support\Content;

use App\Models\AppTextBundle;
use App\Models\AppTextKey;
use Illuminate\Support\Facades\DB;

/**
 * The public bundle the customer app downloads (spec 019 R3, FR-006).
 *
 * It carries only what staff published for the kinds the app reads (`app` and `faq` — never a
 * notification template), only keys still in the app, and never a default marker: a reverted
 * key is simply absent, so the app shows its own default. Its version tag is
 * `"<bundle_version>-<sha1 of the setting placeholder values>"`, so it moves when a text is
 * published **or** a number a text may state changes, and an unchanged tag answers 304.
 *
 * A request costs three queries at most: the latest bundle, the settings, and the texts (the
 * texts are skipped when the tag already matches).
 */
final class BundleBuilder
{
    public const KINDS = [AppTextKey::KIND_APP, AppTextKey::KIND_FAQ];

    public function __construct(private readonly SettingPlaceholders $placeholders) {}

    /**
     * The tag and what it was made from; cheap, so a 304 never loads a text.
     *
     * @return array{version: int, published_at: ?string, settings: array<string, string>, tag: string}
     */
    public function tag(): array
    {
        $latest = AppTextBundle::query()->orderByDesc('bundle_version')->first(['bundle_version', 'published_at']);
        $settings = $this->placeholders->values();
        $version = (int) ($latest?->bundle_version ?? 0);

        return [
            'version' => $version,
            'published_at' => $latest?->published_at?->toIso8601String(),
            'settings' => $settings,
            'tag' => '"'.$version.'-'.$this->placeholders->fingerprint($settings).'"',
        ];
    }

    /**
     * The overrides and the FAQ, as the bundle serves them.
     *
     * @return array{texts: array<string, array{en: string, ar: string}>, faq: list<array<string, mixed>>}
     */
    public function content(): array
    {
        $rows = DB::table('app_text_version as v')
            ->join('app_text_key as k', 'k.text_key', '=', 'v.text_key')
            ->where('v.state', 'published')
            ->where('v.is_default_marker', false)
            ->whereNull('k.retired_at')
            ->whereIn('k.kind', self::KINDS)
            ->orderBy('k.text_key')
            ->get(['k.text_key', 'k.kind', 'k.position', 'v.text_en', 'v.text_ar', 'v.is_hidden']);

        $texts = [];
        $faq = [];
        foreach ($rows as $row) {
            if ($row->kind === AppTextKey::KIND_APP) {
                $texts[$row->text_key] = ['en' => $row->text_en, 'ar' => $row->text_ar];

                continue;
            }

            // `help.faq.<slug>.question` / `.answer`: an entry is served once both halves are
            // published, in the order of its position; hiding is set on the answer.
            if (preg_match('/^(help\.faq\.[a-z0-9_]+)\.(question|answer)$/', $row->text_key, $m) !== 1) {
                continue;
            }
            $entry = &$faq[$m[1]];
            $entry['key'] = $m[1];
            $entry['position'] = $row->position ?? ($entry['position'] ?? null);
            $entry[$m[2]] = ['en' => $row->text_en, 'ar' => $row->text_ar];
            if ($m[2] === 'answer') {
                $entry['hidden'] = (bool) $row->is_hidden;
            }
            unset($entry);
        }

        $entries = array_values(array_filter(
            $faq,
            fn (array $e) => isset($e['question'], $e['answer']) && ! ($e['hidden'] ?? false),
        ));
        usort($entries, fn (array $a, array $b) => [$a['position'] ?? PHP_INT_MAX, $a['key']] <=> [$b['position'] ?? PHP_INT_MAX, $b['key']]);

        return [
            'texts' => $texts,
            'faq' => array_map(fn (array $e) => [
                'key' => $e['key'],
                'position' => $e['position'],
                'question' => $e['question'],
                'answer' => $e['answer'],
            ], $entries),
        ];
    }
}
