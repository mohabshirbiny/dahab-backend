<?php

namespace App\Actions\TopUp;

use App\Enums\AuditEvent;
use App\Enums\TopUpStatus;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\RequestContext;

/**
 * Put a pending notice on hold while staff check it (spec 009 US2 scenario
 * 7; the design's Hold / Investigate). It stays matchable. Audited; no
 * message to the customer, who sees "on hold".
 */
final class HoldTopUpAction
{
    public function __construct(private readonly ChangeTopUpStatusAction $change) {}

    public function handle(Staff $actor, string $topUpId, string $note, ?RequestContext $ctx = null): TopUp
    {
        $note = trim($note);

        return $this->change->handle($actor, $topUpId, TopUpStatus::ON_HOLD, fn () => [
            'hold_note' => $note,
            'held_by' => $actor->staff_id,
            'held_at' => now(),
        ], AuditEvent::TOPUP_HELD, $note, ctx: $ctx);
    }
}
