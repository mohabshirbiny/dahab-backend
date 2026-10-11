<?php

namespace App\Actions\Content;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\StaffPermission;
use App\Exceptions\DomainApiException;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\Staff;
use App\Support\Content\PlaceholderValidator;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Save the wording of one text as a draft (spec 019 US1, Q2, A2).
 *
 * A key has one shared draft (not one per staff member): the second person to save on a copy
 * that is no longer the draft is refused `content_draft_changed` until they reload. A draft is
 * invisible to customers; only *Publish changes* puts it live. The text must already be valid
 * (both languages, length, placeholders) and must differ from what customers see now.
 */
final class SaveDraftAction
{
    public function __construct(
        private readonly PlaceholderValidator $validator,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(
        Staff $actor,
        string $textKey,
        string $en,
        string $ar,
        ?int $draftVersion = null,
        ?bool $isHidden = null,
        ?RequestContext $ctx = null,
    ): AppTextVersion {
        $en = trim($en);
        $ar = trim($ar);

        return DB::transaction(function () use ($actor, $textKey, $en, $ar, $draftVersion, $isHidden, $ctx) {
            // The key row is the lock: two people saving one text run one after the other.
            $key = AppTextKey::query()->whereKey($textKey)->lockForUpdate()->firstOrFail();
            self::assertEditable($actor, $key);

            $draft = $key->draftVersion;
            if ($draft !== null && $draftVersion !== null && $draft->draft_version !== $draftVersion) {
                throw DomainApiException::contentDraftChanged($draft->draft_version);
            }

            $this->validator->validate($key, $en, $ar);

            $live = $key->effective();
            $hidden = $key->kind === AppTextKey::KIND_FAQ ? (bool) $isHidden : false;
            // Hiding or showing a Help entry is a change even when its words are the same.
            $liveHidden = $key->kind === AppTextKey::KIND_FAQ && (bool) $key->publishedVersion?->is_hidden;
            if ($live['en'] === $en && $live['ar'] === $ar && $hidden === $liveHidden) {
                throw DomainApiException::contentUnchanged();
            }

            $fields = [
                'is_default_marker' => false,
                'text_en' => $en,
                'text_ar' => $ar,
                'is_hidden' => $hidden,
                'edited_by' => $actor->staff_id,
                'edited_at' => now(),
            ];

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
                AuditEvent::CONTENT_DRAFT_SAVED,
                'success',
                ['text_key' => $key->text_key, 'kind' => $key->kind, 'version' => $draft->version, 'replaces_version' => $live['version'], 'text_en' => $en, 'text_ar' => $ar],
                entityType: 'app_text',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
                before: ['text_en' => $live['en'], 'text_ar' => $live['ar'], 'version' => $live['version']],
            );

            return $draft->refresh();
        });
    }

    /**
     * A template that carries a code or a link is edited only with `content.edit_secure` (D7);
     * it still has to keep its `{code}` / `{link}` (the validator). A retired key is gone from the app.
     */
    public static function assertEditable(Staff $actor, AppTextKey $key): void
    {
        if ($key->read_only && ! $actor->can(StaffPermission::CONTENT_EDIT_SECURE->value)) {
            throw DomainApiException::contentKeyReadOnly();
        }
        if ($key->isRetired()) {
            throw DomainApiException::contentKeyRetired();
        }
    }
}
