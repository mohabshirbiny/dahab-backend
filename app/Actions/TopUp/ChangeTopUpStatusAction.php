<?php

namespace App\Actions\TopUp;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\TopUpStatus;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\RequestContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The shared shape of the staff moves that do not touch money — hold,
 * un-hold, reject (spec 009 FR-025a): lock the notice, check the move,
 * write the status columns, audit with the note as the reason. The row lock
 * makes a concurrent match, cancel or second move lose cleanly (409).
 */
final class ChangeTopUpStatusAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * @param  Closure(TopUp): array<string, mixed>  $columns  the columns to write for the move
     * @param  array<string, mixed>  $auditExtra
     */
    public function handle(
        Staff $actor,
        string $topUpId,
        TopUpStatus $to,
        Closure $columns,
        AuditEvent $event,
        ?string $note,
        array $auditExtra = [],
        ?RequestContext $ctx = null,
    ): TopUp {
        return DB::transaction(function () use ($actor, $topUpId, $to, $columns, $event, $note, $auditExtra, $ctx) {
            $topUp = TopUp::query()->whereKey($topUpId)->lockForUpdate()->firstOrFail();

            if (! $topUp->status->canMoveTo($to)) {
                throw DomainApiException::illegalTopUpTransition();
            }

            $before = $topUp->status;
            $topUp->forceFill(['status' => $to] + $columns($topUp))->save();

            $this->audit->execute(
                $event,
                'success',
                ['status' => $to->value, 'number' => $topUp->number(), 'customer_ref' => $topUp->customer()->value('display_ref')] + $auditExtra,
                'topup',
                $topUp->topup_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['status' => $before->value],
                reason: $note,
            );

            return $topUp;
        });
    }
}
