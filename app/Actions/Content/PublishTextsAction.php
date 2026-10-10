<?php

namespace App\Actions\Content;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\AppTextBundle;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\Staff;
use App\Support\Content\PlaceholderValidator;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * *Publish changes* (spec 019 Q2, FR-003): every pending draft goes live together as one new
 * bundle, with the publisher and the time, or none does.
 *
 * One transaction under an advisory lock, so two publishes at once give two consecutive
 * bundles and no lost draft: the second finds the first's drafts already live. Every draft is
 * validated again against what customers see now; if any fails, nothing is published and the
 * answer lists each key with its reason. The audit entry names, per key, the version it
 * replaced and the wording before and after.
 */
final class PublishTextsAction
{
    public function __construct(
        private readonly PlaceholderValidator $validator,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{bundle_version: int, published_at: string, keys: list<string>} */
    public function handle(Staff $actor, ?RequestContext $ctx = null): array
    {
        return DB::transaction(function () use ($actor, $ctx) {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('app_text_publish'))");

            $drafts = AppTextVersion::query()->draft()->orderBy('text_key')->lockForUpdate()->get();
            if ($drafts->isEmpty()) {
                throw DomainApiException::contentNothingToPublish();
            }

            $keys = AppTextKey::query()->whereIn('text_key', $drafts->pluck('text_key'))->get()->keyBy('text_key');
            $live = AppTextVersion::query()->published()->whereIn('text_key', $drafts->pluck('text_key'))->get()->keyBy('text_key');

            $errors = [];
            $changes = [];
            foreach ($drafts as $draft) {
                /** @var AppTextKey $key */
                $key = $keys[$draft->text_key];
                $current = $live->get($draft->text_key);
                $overridden = $current !== null && ! $current->is_default_marker;
                $before = $overridden
                    ? ['en' => $current->text_en, 'ar' => $current->text_ar]
                    : ['en' => $key->default_en, 'ar' => $key->default_ar];

                try {
                    if ($key->isRetired()) {
                        throw DomainApiException::contentKeyRetired();
                    }
                    if ($draft->is_default_marker) {
                        if (! $overridden) {
                            throw DomainApiException::contentUnchanged();
                        }
                    } else {
                        $this->validator->validate($key, $draft->text_en, $draft->text_ar);
                        if ($before['en'] === $draft->text_en && $before['ar'] === $draft->text_ar) {
                            throw DomainApiException::contentUnchanged();
                        }
                    }
                } catch (DomainApiException $e) {
                    $errors[$key->text_key] = ['code' => $e->errorCode, 'message' => $e->getMessage()];

                    continue;
                }

                $changes[] = [
                    'text_key' => $key->text_key,
                    'kind' => $key->kind,
                    'version' => $draft->version,
                    'replaces_version' => $current?->version,
                    'default_marker' => $draft->is_default_marker,
                    'before' => $before,
                    'after' => $draft->is_default_marker ? null : ['en' => $draft->text_en, 'ar' => $draft->text_ar],
                ];
            }

            if ($errors !== []) {
                throw DomainApiException::contentInvalid($errors);
            }

            // Numbered here, under the lock: consecutive even after a rolled-back attempt.
            $bundle = AppTextBundle::query()->create([
                'bundle_version' => ((int) AppTextBundle::query()->max('bundle_version')) + 1,
                'published_by' => $actor->staff_id,
                'key_count' => $drafts->count(),
                'kinds' => array_values(array_unique(array_column($changes, 'kind'))),
            ])->refresh();

            // The old live versions step aside first: a key has at most one published row.
            AppTextVersion::query()->published()->whereIn('text_key', $drafts->pluck('text_key'))
                ->update(['state' => AppTextVersion::SUPERSEDED]);
            AppTextVersion::query()->whereIn('version_id', $drafts->pluck('version_id'))
                ->update([
                    'state' => AppTextVersion::PUBLISHED,
                    'published_by' => $actor->staff_id,
                    // The bundle's own timestamp, microseconds included (a PHP date would lose them).
                    'published_at' => DB::raw('(SELECT published_at FROM app_text_bundle WHERE bundle_version = '.(int) $bundle->bundle_version.')'),
                    'bundle_version' => $bundle->bundle_version,
                ]);

            $this->audit->execute(
                AuditEvent::CONTENT_PUBLISHED,
                'success',
                ['bundle_version' => $bundle->bundle_version, 'key_count' => count($changes), 'keys' => $changes],
                entityType: 'app_text_bundle',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
            );

            return [
                'bundle_version' => $bundle->bundle_version,
                'published_at' => $bundle->published_at->toIso8601String(),
                'keys' => array_column($changes, 'text_key'),
            ];
        });
    }
}
