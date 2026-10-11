<?php

namespace App\Console\Commands;

use App\Models\AppTextKey;
use App\Notifications\Templates\Catalogue;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * `app-texts:sync` — registers the customer app's texts (spec 019 R1, T015).
 *
 * The Flutter code is the source of the keys and their defaults; its generated manifest is
 * kept in the Backend at `database/data/app_text_keys.json`. This command makes the registry
 * match it: new keys are added, a key whose default English or Arabic changed is updated and
 * stamped `default_changed_at` (so staff can review an override written against the old
 * wording), a key that left the manifest — or is listed under `retired` — is `retired`.
 * Nothing is ever deleted, and no version, draft or published text is touched.
 *
 * Run after migrations in every deploy that adds keys. Runs as the system actor.
 */
class SyncAppTexts extends Command
{
    protected $signature = 'app-texts:sync {--file= : Manifest path (default database/data/app_text_keys.json)}';

    protected $description = 'Register the customer app\'s text keys and their defaults from the app manifest';

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?: base_path('database/data/app_text_keys.json'));
        if (! is_file($path)) {
            $this->components->error("Manifest not found: {$path}");

            return self::FAILURE;
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->components->error('The manifest is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        $entries = $manifest['keys'] ?? null;
        if (! is_array($entries)) {
            $this->components->error('The manifest has no "keys" list.');

            return self::FAILURE;
        }
        $retired = array_values(array_filter((array) ($manifest['retired'] ?? []), 'is_string'));

        $counts = DatabaseActor::elevate('system', fn () => DB::transaction(function () use ($entries, $retired) {
            $counts = $this->sync($entries, $retired);
            // Spec 019 T048: the customer messages the PHP catalogue holds (kind `notification`).
            foreach ($this->syncCatalogue() as $k => $n) {
                $counts[$k] += $n;
            }

            return $counts;
        }), SystemActor::id());

        $this->components->info(sprintf(
            'App texts: %d added, %d default(s) changed, %d unchanged, %d retired, %d brought back.',
            $counts['added'], $counts['changed'], $counts['unchanged'], $counts['retired'], $counts['restored'],
        ));

        return self::SUCCESS;
    }

    /**
     * Registers every customer message of `Catalogue::registryEntries()` as a `notification` key:
     * read-only ones (code or link, D7) are marked so, a default that moved is stamped, and a
     * message that left the catalogue is retired. Nothing is deleted.
     *
     * @return array{added: int, changed: int, unchanged: int, retired: int, restored: int}
     */
    private function syncCatalogue(): array
    {
        $now = now();
        $counts = ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'retired' => 0, 'restored' => 0];
        $seen = [];

        foreach (Catalogue::registryEntries() as $key => $entry) {
            $seen[] = $key;
            $attributes = [
                'kind' => AppTextKey::KIND_NOTIFICATION,
                'area' => Catalogue::AREA,
                'description' => $entry['desc'],
                'default_en' => $entry['en'],
                'default_ar' => $entry['ar'],
                'placeholders' => array_values($entry['vars']),
                'required_placeholders' => $entry['required'],
                'read_only' => $entry['read_only'],
                'inbox' => $entry['inbox'],
                'max_length' => max(2 * max(mb_strlen($entry['en']), mb_strlen($entry['ar'])), 40),
            ];

            $row = AppTextKey::query()->whereKey($key)->lockForUpdate()->first();
            if ($row === null) {
                AppTextKey::query()->create($attributes + ['text_key' => $key, 'synced_at' => $now]);
                $counts['added']++;

                continue;
            }

            $defaultChanged = $row->default_en !== $attributes['default_en'] || $row->default_ar !== $attributes['default_ar'];
            $restored = $row->isRetired();
            $row->forceFill($attributes + ['synced_at' => $now, 'retired_at' => null])->save();
            if ($defaultChanged) {
                AppTextKey::query()->whereKey($key)->update(['default_changed_at' => DB::raw('clock_timestamp()')]);
            }

            match (true) {
                $defaultChanged => $counts['changed']++,
                $restored => $counts['restored']++,
                default => $counts['unchanged']++,
            };
        }

        $gone = AppTextKey::query()->where('kind', AppTextKey::KIND_NOTIFICATION)->whereNull('retired_at')->whereNotIn('text_key', $seen)->pluck('text_key');
        if ($gone->isNotEmpty()) {
            AppTextKey::query()->whereIn('text_key', $gone)->update(['retired_at' => $now, 'synced_at' => $now]);
            $counts['retired'] = $gone->count();
        }

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $retired
     * @return array{added: int, changed: int, unchanged: int, retired: int, restored: int}
     */
    private function sync(array $entries, array $retired): array
    {
        $now = now();
        $counts = ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'retired' => 0, 'restored' => 0];
        $seen = [];

        foreach ($entries as $entry) {
            $key = (string) ($entry['key'] ?? '');
            $kind = (string) ($entry['kind'] ?? AppTextKey::KIND_APP);
            if ($key === '' || in_array($key, $retired, true)) {
                continue;
            }
            $seen[] = $key;

            $attributes = [
                'kind' => $kind,
                'area' => (string) ($entry['area'] ?? ''),
                'description' => (string) ($entry['where'] ?? $entry['description'] ?? ''),
                'default_en' => (string) $entry['default_en'],
                'default_ar' => (string) $entry['default_ar'],
                'placeholders' => array_values((array) ($entry['placeholders'] ?? [])),
                'max_length' => (int) ($entry['max_length'] ?? max(2 * mb_strlen((string) $entry['default_en']), 40)),
            ];

            $row = AppTextKey::query()->whereKey($key)->lockForUpdate()->first();
            if ($row === null) {
                AppTextKey::query()->create($attributes + ['text_key' => $key, 'synced_at' => $now]);
                $counts['added']++;

                continue;
            }

            $defaultChanged = $row->default_en !== $attributes['default_en'] || $row->default_ar !== $attributes['default_ar'];
            $restored = $row->isRetired();

            $row->forceFill($attributes + ['synced_at' => $now, 'retired_at' => null]);
            $row->save();
            if ($defaultChanged) {
                // The database's own clock, as the publish uses for published_at: they compare.
                AppTextKey::query()->whereKey($key)->update(['default_changed_at' => DB::raw('clock_timestamp()')]);
            }

            match (true) {
                $defaultChanged => $counts['changed']++,
                $restored => $counts['restored']++,
                default => $counts['unchanged']++,
            };
        }

        // The sync owns the keys the app declares; faq and notification keys have their own sources.
        $gone = AppTextKey::query()->where('kind', AppTextKey::KIND_APP)->whereNull('retired_at')
            ->where(fn ($q) => $q->whereNotIn('text_key', $seen)->orWhereIn('text_key', $retired))
            ->pluck('text_key');
        if ($gone->isNotEmpty()) {
            AppTextKey::query()->whereIn('text_key', $gone)->update(['retired_at' => $now, 'synced_at' => $now]);
            $counts['retired'] = $gone->count();
        }

        return $counts;
    }
}
