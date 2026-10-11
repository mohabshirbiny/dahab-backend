<?php

namespace App\Http\Resources\FounderSecurity;

use App\Actions\FounderSecurity\ListFreezesAction;
use App\Models\AccountFreeze;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardFreezeConfirmation',
    required: ['slot', 'kind', 'by', 'at'],
    properties: [
        new OA\Property(property: 'slot', type: 'integer', enum: [1, 2]),
        new OA\Property(property: 'kind', type: 'string', enum: ['frozen_founder', 'second_approver', 'founder']),
        new OA\Property(property: 'by', ref: '#/components/schemas/DashboardStaffRef'),
        new OA\Property(property: 'at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'DashboardAccountFreeze',
    description: 'Spec 021: a freeze of a founder account and its two unfreeze confirmations (slot 1: the frozen founder or a holder of founder.unfreeze_second; slot 2, always last: a founder who is not frozen).',
    required: ['id', 'frozen_staff', 'frozen_by', 'frozen_at', 'reason', 'stage', 'confirmations', 'can_confirm', 'my_slot'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'frozen_staff', ref: '#/components/schemas/DashboardStaffRef'),
        new OA\Property(property: 'frozen_by', ref: '#/components/schemas/DashboardStaffRef'),
        new OA\Property(property: 'frozen_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'stage', type: 'string', enum: ['awaiting_first', 'awaiting_founder', 'unfrozen']),
        new OA\Property(property: 'confirmations', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardFreezeConfirmation')),
        new OA\Property(property: 'unfrozen_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'can_confirm', type: 'boolean', description: 'The viewer\'s slot is free and in order.'),
        new OA\Property(property: 'my_slot', type: 'integer', enum: [1, 2], nullable: true, description: '2 for a founder who is not frozen, 1 for a holder of founder.unfreeze_second.'),
        new OA\Property(property: 'sessions_ended', type: 'integer', description: 'Only on the freeze answer.'),
        new OA\Property(property: 'pending_approvals_ended', type: 'integer', description: 'Only on the freeze answer.'),
    ],
)]
class AccountFreezeResource extends JsonResource
{
    /** @var array{sessions_ended?: int, pending_approvals_ended?: int} */
    private array $counts = [];

    /** @param array{sessions_ended?: int, pending_approvals_ended?: int} $counts */
    public function withCounts(array $counts): self
    {
        $this->counts = $counts;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var AccountFreeze $f */
        $f = $this->resource;
        $viewer = $request->user();
        $viewer = $viewer instanceof Staff ? $viewer : null;

        $confirmations = [];
        if ($f->unfreeze_confirm_1 !== null) {
            $confirmations[] = [
                'slot' => 1,
                'kind' => $f->unfreeze_confirm_1_kind,
                'by' => DeviceApprovalResource::ref($f->confirmer1),
                'at' => $f->unfreeze_confirm_1_at?->toIso8601String(),
            ];
        }
        if ($f->unfreeze_confirm_2 !== null) {
            $confirmations[] = [
                'slot' => 2,
                'kind' => 'founder',
                'by' => DeviceApprovalResource::ref($f->confirmer2),
                'at' => $f->unfreeze_confirm_2_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $f->freeze_id,
            'frozen_staff' => DeviceApprovalResource::ref($f->frozenStaff),
            'frozen_by' => DeviceApprovalResource::ref($f->frozenBy),
            'frozen_at' => $f->frozen_at->toIso8601String(),
            'reason' => $f->reason,
            'stage' => $f->stage(),
            'confirmations' => $confirmations,
            'unfrozen_at' => $f->unfrozen_at?->toIso8601String(),
            'can_confirm' => ListFreezesAction::canConfirm($f, $viewer),
            'my_slot' => ListFreezesAction::slotFor($f, $viewer),
            ...$this->counts,
        ];
    }
}
