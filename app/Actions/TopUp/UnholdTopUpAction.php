<?php

namespace App\Actions\TopUp;

use App\Enums\AuditEvent;
use App\Enums\TopUpStatus;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\RequestContext;

/**
 * Take a notice off hold, back to pending (spec 009 FR-025a). The last hold
 * note stays on the row for the record. Audited; no customer message.
 */
final class UnholdTopUpAction
{
    public function __construct(private readonly ChangeTopUpStatusAction $change) {}

    public function handle(Staff $actor, string $topUpId, ?RequestContext $ctx = null): TopUp
    {
        return $this->change->handle($actor, $topUpId, TopUpStatus::PENDING, fn () => [], AuditEvent::TOPUP_UNHELD, null, ctx: $ctx);
    }
}
