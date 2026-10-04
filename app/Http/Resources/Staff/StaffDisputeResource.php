<?php

namespace App\Http\Resources\Staff;

use App\Enums\DisputeState;
use App\Enums\OrderState;
use App\Enums\StaffPermission;
use App\Models\Dispute;
use App\Models\Staff;
use App\Support\Disputes\CompensationCaps;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A dispute for staff (spec 014 FR-008): the queue row, and with `detail()`
 * the customer's words, the photo ids, the history with staff notes,
 * compensation paid, the order (the spec 012 staff detail) and what the
 * caller may do with their codes — including what is left of their
 * compensation caps today (null = uncapped).
 */
#[OA\Schema(
    schema: 'StaffDispute',
    required: ['id', 'ref', 'order', 'reason', 'raised_as', 'raised_by', 'state', 'opened_at', 'age_seconds'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ref', type: 'string', example: 'DSP-4417'),
        new OA\Property(property: 'order', type: 'object', description: 'Row: {id, ref, state, frozen_from}. Detail: the StaffOrder detail plus frozen_from'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'reason_label', type: 'string'),
        new OA\Property(property: 'raised_as', type: 'string', enum: ['buyer', 'seller']),
        new OA\Property(property: 'raised_by', type: 'object', description: '{customer_id, display_ref}'),
        new OA\Property(property: 'state', type: 'string', enum: ['open', 'passed_on', 'resolved']),
        new OA\Property(property: 'assigned_to', type: 'object', nullable: true, description: '{staff_id, name}'),
        new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'age_seconds', type: 'integer'),
        new OA\Property(property: 'detail', type: 'string', description: 'Detail only'),
        new OA\Property(property: 'photos', type: 'array', items: new OA\Items(type: 'object'), description: 'Detail only: [{id, mime, position}]'),
        new OA\Property(property: 'history', type: 'array', items: new OA\Items(type: 'object'), description: 'Detail only: [{kind, by: {type, name}, assigned_to, note, at}]'),
        new OA\Property(property: 'outcome', type: 'string', nullable: true),
        new OA\Property(property: 'reply', type: 'string', nullable: true),
        new OA\Property(property: 'resolved_by', type: 'object', nullable: true),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'compensations', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'can', type: 'object', description: '{pass_on, resolve_resume, resolve_against_sale, compensate, suspend_seller, compensation_caps: {per_payment, left_today}|null}'),
    ],
)]
class StaffDisputeResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Dispute $d */
        $d = $this->resource;
        $order = $d->order;

        $data = [
            'id' => $d->dispute_id,
            'ref' => $d->dispute_ref,
            'order' => ['id' => $d->order_id, 'ref' => $order?->order_ref, 'state' => $order?->state->value, 'frozen_from' => $d->frozen_from->value],
            'reason' => $d->reason->value,
            'reason_label' => $d->reason->label(),
            'raised_as' => $d->raised_as,
            'raised_by' => ['customer_id' => $d->raised_by, 'display_ref' => $d->raiser?->display_ref],
            'state' => $d->state->value,
            'assigned_to' => $d->assigned_to === null ? null : ['staff_id' => $d->assigned_to, 'name' => $d->assignee?->full_name],
            'opened_at' => $d->opened_at->toIso8601String(),
            'age_seconds' => max(0, now()->getTimestamp() - $d->opened_at->getTimestamp()),
            'outcome' => $d->outcome?->value,
        ];

        if (! $this->detail) {
            return $data;
        }

        /** @var Staff|null $staff */
        $staff = $request->user('staff');
        $has = fn (StaffPermission $p): bool => $staff !== null && $staff->can($p->value);
        $open = $d->state !== DisputeState::RESOLVED && $order?->state === OrderState::DISPUTED;

        return array_merge($data, [
            'order' => (new StaffOrderResource($order))->detail()->resolve($request) + ['frozen_from' => $d->frozen_from->value],
            'detail' => $d->detail,
            'photos' => $d->photos->map(fn ($p) => ['id' => $p->photo_id, 'mime' => $p->mime, 'position' => $p->position])->values()->all(),
            'history' => $d->changes->map(fn ($c) => [
                'kind' => $c->kind->value,
                'by' => $c->actor_staff_id !== null
                    ? ['type' => 'staff', 'staff_id' => $c->actor_staff_id, 'name' => $c->actorStaff?->full_name]
                    : ['type' => 'customer', 'role' => $d->raised_as],
                'assigned_to' => $c->assigned_to === null ? null : ['staff_id' => $c->assigned_to, 'name' => $c->assignee?->full_name],
                'note' => $c->note,
                'at' => $c->at->toIso8601String(),
            ])->values()->all(),
            'reply' => $d->resolution_reply,
            'resolved_by' => $d->resolved_by === null ? null : ['staff_id' => $d->resolved_by, 'name' => $d->resolver?->full_name],
            'resolved_at' => $d->resolved_at?->toIso8601String(),
            'compensations' => $d->compensations->map(fn ($c) => [
                'party' => $c->party,
                'amount' => bcadd((string) $c->amount, '0', 4),
                'reason' => $c->reason->value,
                'note' => $c->note,
                'paid_by' => ['staff_id' => $c->paid_by, 'name' => $c->payer?->full_name],
                'paid_at' => $c->paid_at->toIso8601String(),
            ])->values()->all(),
            'can' => [
                'pass_on' => $open && $has(StaffPermission::DISPUTE_HANDLE),
                'resolve_resume' => $open && $has(StaffPermission::DISPUTE_HANDLE),
                'resolve_against_sale' => $open && $d->frozen_from !== OrderState::READY_TO_COLLECT
                    && $has(StaffPermission::DISPUTE_HANDLE) && $has(StaffPermission::ORDER_REFUND),
                'compensate' => $open && $has(StaffPermission::DISPUTE_HANDLE) && $has(StaffPermission::COMPENSATION_PAY),
                'suspend_seller' => $open && $d->frozen_from !== OrderState::READY_TO_COLLECT && $has(StaffPermission::CUSTOMER_SUSPEND),
                'compensation_caps' => $staff === null || ! $has(StaffPermission::COMPENSATION_PAY) ? null : app(CompensationCaps::class)->forStaff($staff),
            ],
        ]);
    }
}
