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
 * Move a Help entry to a position (spec 019 US5, FR-031), 1 = first.
 *
 * The move is a draft like any other: the new order is written to `pending_position` on both
 * keys of every entry that moves and goes live with *Publish changes*, together with the text
 * drafts. Moving an entry back to where it was clears the pending order. Positions stay
 * consecutive.
 */
final class ReorderFaqAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{slug: string, position: int} */
    public function handle(Staff $actor, string $slug, int $position, ?RequestContext $ctx = null): array
    {
        return DB::transaction(function () use ($actor, $slug, $position, $ctx) {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('app_text_faq'))");

            $keys = AppTextKey::query()
                ->where('kind', AppTextKey::KIND_FAQ)
                ->where('text_key', 'like', 'help.faq.%')
                ->whereNull('retired_at')
                ->orderBy('text_key')
                ->lockForUpdate()
                ->get();

            // Entries by slug, each with its two keys.
            $entries = [];
            foreach ($keys as $key) {
                if (preg_match('/^help\.faq\.([a-z0-9_]+)\.(question|answer)$/', $key->text_key, $m) === 1) {
                    $entries[$m[1]][] = $key;
                }
            }
            if (! isset($entries[$slug])) {
                throw DomainApiException::contentFaqUnknown();
            }

            // The order customers will see once published: the pending one where there is one.
            $effective = fn (array $pair) => $pair[0]->pending_position ?? $pair[0]->position ?? PHP_INT_MAX;
            uksort($entries, fn (string $a, string $b) => [$effective($entries[$a]), $a] <=> [$effective($entries[$b]), $b]);

            $order = array_keys($entries);
            $from = array_search($slug, $order, true);
            $to = max(0, min(count($order) - 1, $position - 1));
            if ($from === $to) {
                throw DomainApiException::contentUnchanged();
            }
            array_splice($order, $from, 1);
            array_splice($order, $to, 0, [$slug]);

            foreach ($order as $index => $name) {
                $target = $index + 1;
                foreach ($entries[$name] as $key) {
                    $pending = $key->position === $target ? null : $target;
                    if ($key->pending_position !== $pending) {
                        $key->forceFill(['pending_position' => $pending])->save();
                    }
                }
            }

            $this->audit->execute(
                AuditEvent::CONTENT_FAQ_REORDERED,
                'success',
                ['slug' => $slug, 'position' => $to + 1, 'order' => $order],
                entityType: 'app_text',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
            );

            return ['slug' => $slug, 'position' => $to + 1];
        });
    }
}
