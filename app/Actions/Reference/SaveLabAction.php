<?php

namespace App\Actions\Reference;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AccountEvent;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Jobs\NotifyCustomerJob;
use App\Models\Lab;
use App\Models\Listing;
use App\Models\Staff;
use App\Notifications\AccountNotification;
use Illuminate\Support\Facades\DB;

/**
 * Add or edit a lab: audited with before and after. A changed cost reaches the pieces not yet
 * accepted, whose sellers are told after commit; an order already keeps the cost it locked.
 */
final class SaveLabAction
{
    private const COSTS = ['certificate_own_cost_egp', 'certificate_other_cost_egp', 'certificate_none_cost_egp', 'gold_inspection_cost_egp'];

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(Staff $actor, array $data): Lab
    {
        return DB::transaction(function () use ($actor, $data) {
            $lab = Lab::query()->create($data)->refresh();

            $this->audit->execute(
                AuditEvent::LAB_CREATED,
                'success',
                ['lab_id' => $lab->lab_id] + $lab->only(Lab::EDITABLE),
                entityType: 'lab',
                actorStaffId: $actor->staff_id,
            );

            return $lab;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Staff $actor, int $labId, array $data): Lab
    {
        return DB::transaction(function () use ($actor, $labId, $data) {
            $lab = Lab::query()->lockForUpdate()->findOrFail($labId);
            $lab->fill($data);

            $changed = array_keys(array_filter($lab->getDirty(), fn ($value, $key) => ! in_array($key, self::COSTS, true)
                || bccomp((string) $value, (string) $lab->getOriginal($key), 4) !== 0, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $lab->refresh();
            }

            $before = array_intersect_key($lab->getOriginal(), array_flip($changed));
            $lab->save();

            $this->audit->execute(
                AuditEvent::LAB_UPDATED,
                'success',
                ['lab_id' => $lab->lab_id, 'changes' => $lab->only($changed)],
                entityType: 'lab',
                actorStaffId: $actor->staff_id,
                before: $before,
            );

            if (array_intersect($changed, self::COSTS) !== []) {
                $this->tellSellers($lab);
            }

            return $lab;
        });
    }

    private function tellSellers(Lab $lab): void
    {
        $sellers = Listing::query()->where('lab_id', $lab->lab_id)
            ->whereIn('state', [ListingState::IN_REVIEW->value, ListingState::CHANGES_REQUESTED->value, ListingState::LIVE->value, ListingState::RESERVED->value])
            ->distinct()->pluck('seller_id');

        DB::afterCommit(function () use ($sellers, $lab) {
            foreach ($sellers as $seller) {
                NotifyCustomerJob::dispatch($seller, new AccountNotification(AccountEvent::LAB_COST_CHANGED, 'en', detail: $lab->name_en));
            }
        });
    }
}
