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
 * Add a Help entry (spec 019 US5, FR-030): a question and an answer, each in both languages.
 *
 * An entry is two keys, `help.faq.<slug>.question` and `.answer`, each born with a draft and
 * no live text — customers see nothing until *Publish changes*, and a hidden entry is never
 * served. It goes to the end of the list. Entries are never deleted (hide them): a published
 * wording is history (DH017).
 */
final class CreateFaqEntryAction
{
    public const QUESTION_MAX = 160;

    public const ANSWER_MAX = 1200;

    public function __construct(
        private readonly PlaceholderValidator $validator,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @param  array{question_en: string, question_ar: string, answer_en: string, answer_ar: string, is_hidden?: bool}  $texts
     * @return array{slug: string, position: int}
     */
    public function handle(Staff $actor, string $slug, array $texts, ?RequestContext $ctx = null): array
    {
        return DB::transaction(function () use ($actor, $slug, $texts, $ctx) {
            // Entries are numbered one after another: two at once get two positions.
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('app_text_faq'))");

            $base = 'help.faq.'.$slug;
            if (AppTextKey::query()->whereKey($base.'.question')->exists()) {
                throw DomainApiException::contentFaqExists($slug);
            }

            $parts = [
                'question' => [trim($texts['question_en']), trim($texts['question_ar']), self::QUESTION_MAX, false],
                'answer' => [trim($texts['answer_en']), trim($texts['answer_ar']), self::ANSWER_MAX, (bool) ($texts['is_hidden'] ?? false)],
            ];

            $position = ((int) AppTextKey::query()
                ->where('kind', AppTextKey::KIND_FAQ)
                ->max(DB::raw('GREATEST(COALESCE(position, 0), COALESCE(pending_position, 0))'))) + 1;

            foreach ($parts as $part => [$en, $ar, $max, $hidden]) {
                $key = AppTextKey::query()->create([
                    'text_key' => $base.'.'.$part,
                    'kind' => AppTextKey::KIND_FAQ,
                    'area' => 'Help',
                    'description' => 'Help: the '.$part.' of "'.$slug.'"',
                    // The app has nothing of its own to show for a Help entry.
                    'default_en' => '',
                    'default_ar' => '',
                    'max_length' => $max,
                    'inbox' => false,
                    'position' => $position,
                ]);

                $this->validator->validate($key, $en, $ar);

                AppTextVersion::query()->create([
                    'text_key' => $key->text_key,
                    'version' => 1,
                    'state' => AppTextVersion::DRAFT,
                    'draft_version' => 1,
                    'is_default_marker' => false,
                    'text_en' => $en,
                    'text_ar' => $ar,
                    'is_hidden' => $hidden,
                    'edited_by' => $actor->staff_id,
                    'edited_at' => now(),
                ]);
            }

            $this->audit->execute(
                AuditEvent::CONTENT_FAQ_CREATED,
                'success',
                ['slug' => $slug, 'position' => $position, 'question_en' => $parts['question'][0], 'question_ar' => $parts['question'][1], 'hidden' => $parts['answer'][3]],
                entityType: 'app_text',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
            );

            return ['slug' => $slug, 'position' => $position];
        });
    }
}
