<?php

namespace App\Actions\Content;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\AppTextKey;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Throw away the pending draft of a text (spec 019 US1). The draft row is deleted — it was
 * never customer-visible — and the audit entry keeps what it said and who wrote it.
 */
final class DiscardDraftAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $textKey, ?RequestContext $ctx = null): void
    {
        DB::transaction(function () use ($actor, $textKey, $ctx) {
            $key = AppTextKey::query()->whereKey($textKey)->lockForUpdate()->firstOrFail();
            $draft = $key->draftVersion ?? throw DomainApiException::contentNoDraft();

            $draft->delete();

            $this->audit->execute(
                AuditEvent::CONTENT_DRAFT_DISCARDED,
                'success',
                ['text_key' => $key->text_key, 'version' => $draft->version, 'was_default_marker' => $draft->is_default_marker],
                entityType: 'app_text',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
                before: ['text_en' => $draft->text_en, 'text_ar' => $draft->text_ar, 'edited_by' => $draft->edited_by],
            );
        });
    }
}
