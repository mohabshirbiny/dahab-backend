<?php

namespace App\Actions\TopUp;

use App\Enums\TopUpStatus;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\TopUp;
use Illuminate\Support\Facades\DB;

/**
 * The customer withdraws their own pending notice (spec 009 FR-025b). Moves
 * no money. The row lock serialises it against a staff match: exactly one
 * wins (a cancelled notice is never credited, a credited one never
 * cancelled). A suspended customer may cancel too (L2).
 */
final class CancelTopUpNoticeAction
{
    public function handle(Customer $customer, string $topUpId): TopUp
    {
        return DB::transaction(function () use ($customer, $topUpId) {
            $topUp = TopUp::query()
                ->whereKey($topUpId)
                ->where('customer_id', $customer->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $topUp->status->canMoveTo(TopUpStatus::CANCELLED)) {
                throw DomainApiException::illegalTopUpTransition();
            }

            $topUp->forceFill(['status' => TopUpStatus::CANCELLED, 'cancelled_at' => now()])->save();

            return $topUp;
        });
    }
}
