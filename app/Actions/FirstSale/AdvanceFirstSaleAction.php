<?php

namespace App\Actions\FirstSale;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\AdvanceBasis;
use App\Enums\AuditEvent;
use App\Enums\LedgerEventKind;
use App\Enums\OrderEvent;
use App\Enums\SettingKey;
use App\Models\Account;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\FirstSaleAdvance;
use App\Models\FirstSaleEnablement;
use App\Models\InspectionResult;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use App\Support\FirstSale\FirstSaleEligibility;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Orders\OrderSettlement;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use App\Support\SystemActor;
use Illuminate\Support\Facades\DB;

/**
 * Pay a seller's first sale ahead when the piece is gold (spec 023 research R11–R13; TS Part 3 §6.2–6.3).
 *
 * Called inside the transaction that moved the order to `awaiting_balance` — at the inspection that
 * passes, or when the buyer accepts an adjusted price. It never throws into the caller for an
 * ineligible seller: an advance problem must not block an inspection result. Behind
 * `payout.first_sale_enabled` (default off). Testing notes: automatic — no code or staff enablement
 * is needed; one that is there is recorded as the basis. Every check is repeated here, at the moment, under
 * locks: the seller's customer row (no earlier advance), an advisory lock over the exposure sum
 * (two inspections cannot both take the last of the headroom), and `UNIQUE (seller_id)` as the
 * backstop.
 *
 * One balanced `first_sale_payout` entry: `external_equity −A`, the seller's available `+A`. The
 * amount is `min(provisional proceeds, payout.first_sale_cap_egp)`, the proceeds computed on the
 * IGI weight and the locked rates with the same waiver resolver the settlement uses.
 *
 * The money decision is the platform's, not the caller's (an inspector or the buyer triggers it),
 * so the work runs in the `system` scope with the system staff (or the enabling staff) as actor.
 */
final class AdvanceFirstSaleAction
{
    public function __construct(
        private readonly FirstSaleEligibility $eligibility,
        private readonly OrderSettlement $settlement,
        private readonly PostLedgerEntryAction $post,
        private readonly RecordAuditLogAction $audit,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array{advance: FirstSaleAdvance|null, notify: OrderEvent|null}
     */
    public function handle(Order $order, Listing $listing, BuyRequest $request, InspectionResult $result): array
    {
        return DatabaseActor::elevate('system', fn () => $this->advance($order, $listing, $request, $result));
    }

    /** @return array{advance: FirstSaleAdvance|null, notify: OrderEvent|null} */
    private function advance(Order $order, Listing $listing, BuyRequest $request, InspectionResult $result): array
    {
        $none = ['advance' => null, 'notify' => null];

        // Already advanced (a correction that re-derives a pass): nothing to do.
        if (FirstSaleAdvance::query()->where('order_id', $order->order_id)->exists()) {
            return $none;
        }

        // The seller's row is locked so two orders of one seller cannot both pass "no earlier advance".
        $seller = Customer::query()->whereKey($order->seller_id)->lockForUpdate()->firstOrFail();

        $gate = $this->eligibility->gate($seller, $listing);

        $reason = $this->eligibility->reason($seller, $listing->category, checkCeiling: false);
        if ($gate['basis'] === AdvanceBasis::AUTOMATIC->value && ! FirstSaleEligibility::offeredAutomatically($reason)) {
            return $none;   // never offered (switch off, not gold, not the first sale): nothing to record or say
        }
        if ($reason === null) {
            // The exposure sum is read under an advisory lock for the rest of this transaction.
            DB::selectOne("SELECT pg_advisory_xact_lock(hashtext('first_sale_exposure'))");
        }

        $amount = null;
        $figures = null;
        $cap = $this->settings->numeric(SettingKey::PAYOUT_FIRST_SALE_CAP_EGP);
        if ($reason === null) {
            $figures = $this->settlement->compute($order, $listing, $request, $result);
            $amount = Money::fixed4(Money::cmp($figures->proceeds, $cap) < 0 ? $figures->proceeds : $cap);

            if (Money::cmp($amount, '0') <= 0) {
                $reason = FirstSaleEligibility::REASON_SELLER_NOT_ELIGIBLE;
            } elseif (Money::cmp(Money::add($this->eligibility->exposure(), $amount),
                $this->settings->numeric(SettingKey::PAYOUT_FIRST_SALE_EXPOSURE_CEILING_EGP)) > 0) {
                $reason = FirstSaleEligibility::REASON_CEILING;
            }
        }

        if ($reason !== null) {
            return $this->skipped($order, $reason);
        }

        $enablement = $gate['enablement_id'] === null ? null : FirstSaleEnablement::query()->whereKey($gate['enablement_id'])->lockForUpdate()->first();
        $actorStaffId = $enablement?->enabled_by ?? SystemActor::id();

        $txn = $this->postEntry($order, $listing, $seller, $amount, $actorStaffId);

        $advance = FirstSaleAdvance::query()->create([
            'order_id' => $order->order_id,
            'seller_id' => $seller->customer_id,
            'listing_id' => $listing->listing_id,
            'amount_egp' => $amount,
            'cap_applied_egp' => Money::fixed4($cap),
            'provisional_proceeds_egp' => $figures->proceeds,
            'basis' => $gate['basis'],
            'promo_code' => $gate['promo_code'],
            'enablement_id' => $gate['enablement_id'],
            'advance_txn_id' => $txn->ledger_txn_id,
        ])->refresh();

        if ($enablement !== null) {
            $enablement->used_advance_id = $advance->advance_id;
            $enablement->save();
        }

        $this->audit->execute(
            AuditEvent::FIRST_SALE_ADVANCED,
            'success',
            ['order_ref' => $order->order_ref, 'amount' => $amount, 'cap' => Money::fixed4($cap), 'basis' => $gate['basis'],
                'provisional_proceeds' => $figures->proceeds, 'txn_id' => $txn->ledger_txn_id, 'seller_id' => $seller->customer_id],
            'order',
            $order->order_id,
            actorStaffId: $actorStaffId,
        );

        return ['advance' => $advance, 'notify' => OrderEvent::ADVANCE_PAID];
    }

    /** One balanced entry: Dahab's equity out, the seller's available in. */
    private function postEntry(Order $order, Listing $listing, Customer $seller, string $amount, string $actorStaffId)
    {
        $lines = DatabaseActor::ledger(fn () => [
            new LedgerLine(Account::internal(AccountKind::EXTERNAL_EQUITY), '-'.$amount),
            new LedgerLine(Account::forCustomerKind($seller->customer_id, AccountKind::CUST_AVAILABLE), $amount),
        ]);

        return $this->post->handle(new LedgerEntry(
            LedgerEventKind::FIRST_SALE_PAYOUT,
            $lines,
            actorStaffId: $actorStaffId,
            listingId: $listing->listing_id,
            orderId: $order->order_id,
            buyRequestId: $order->buy_request_id,
        ));
    }

    /**
     * Skipped, not failed (research R11): audited with the reason, and the seller is told once that
     * they are paid when the buyer pays.
     *
     * @return array{advance: null, notify: OrderEvent|null}
     */
    private function skipped(Order $order, string $reason): array
    {
        $this->audit->execute(
            AuditEvent::FIRST_SALE_SKIPPED,
            'success',
            ['order_ref' => $order->order_ref, 'reason' => $reason, 'seller_id' => $order->seller_id],
            'order',
            $order->order_id,
            actorStaffId: SystemActor::id(),
        );

        // A seller who has been paid ahead before is not promised anything, so is not told.
        return ['advance' => null, 'notify' => $reason === FirstSaleEligibility::REASON_NOT_FIRST_GOLD || $reason === FirstSaleEligibility::REASON_NOT_GOLD
            ? null : OrderEvent::ADVANCE_UNAVAILABLE];
    }
}
