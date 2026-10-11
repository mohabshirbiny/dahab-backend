<?php

namespace App\Actions\Content;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\Staff;
use App\Support\Content\PlaceholderValidator;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Put a text back (spec 019 FR-002, review fix).
 *
 * - **to the code default** makes a *default marker* draft. Once published it ends the
 *   override: the bundle no longer carries the key and the app shows its own current default —
 *   even one a later release changed. The old default is never pinned.
 * - **to an earlier version** makes a draft copying that version's wording; published, it is a
 *   new version (the earlier one is untouched).
 *
 * Both are drafts: nothing changes for customers until *Publish changes*.
 */
final class RevertTextAction
{
    public const DEFAULT = 'default';

    public function __construct(
        private readonly PlaceholderValidator $validator,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $textKey, string|int $to, ?RequestContext $ctx = null): AppTextVersion
    {
        return DB::transaction(function () use ($actor, $textKey, $to, $ctx) {
            $key = AppTextKey::query()->whereKey($textKey)->lockForUpdate()->firstOrFail();
            SaveDraftAction::assertEditable($actor, $key);

            $live = $key->effective();
            $source = null;
            if ($to !== self::DEFAULT) {
                $source = AppTextVersion::query()
                    ->where('text_key', $key->text_key)
                    ->where('version', (int) $to)
                    ->where('state', '!=', AppTextVersion::DRAFT)
                    ->firstOrFail();
            }

            // Restoring a version that itself ended the override is a revert to default.
            $toDefault = $source === null || $source->is_default_marker;

            if ($toDefault) {
                if (! $live['overridden']) {
                    throw DomainApiException::contentUnchanged();
                }
                $fields = ['is_default_marker' => true, 'text_en' => '', 'text_ar' => '', 'is_hidden' => false];
            } else {
                $this->validator->validate($key, $source->text_en, $source->text_ar);
                if ($live['en'] === $source->text_en && $live['ar'] === $source->text_ar) {
                    throw DomainApiException::contentUnchanged();
                }
                $fields = ['is_default_marker' => false, 'text_en' => $source->text_en, 'text_ar' => $source->text_ar, 'is_hidden' => $source->is_hidden];
            }

            $fields += ['edited_by' => $actor->staff_id, 'edited_at' => now()];
            $draft = $key->draftVersion;
            if ($draft !== null) {
                $draft->forceFill($fields + ['draft_version' => $draft->draft_version + 1])->save();
            } else {
                $draft = AppTextVersion::query()->create($fields + [
                    'text_key' => $key->text_key,
                    'version' => ((int) AppTextVersion::query()->where('text_key', $key->text_key)->max('version')) + 1,
                    'state' => AppTextVersion::DRAFT,
                    'draft_version' => 1,
                ]);
            }

            $this->audit->execute(
                AuditEvent::CONTENT_REVERTED,
                'success',
                [
                    'text_key' => $key->text_key,
                    'to' => $toDefault ? self::DEFAULT : $source->version,
                    'version' => $draft->version,
                    'replaces_version' => $live['version'],
                ],
                entityType: 'app_text',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
                before: ['text_en' => $live['en'], 'text_ar' => $live['ar'], 'version' => $live['version']],
            );

            return $draft->refresh();
        });
    }
}
