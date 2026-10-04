<?php

namespace App\Actions\Inspections;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Actions\Orders\OpenSellerReturnAction;
use App\Actions\Orders\ReleaseOrderDepositAction;
use App\Actions\Orders\SuspendSellerAction;
use App\Enums\AuditEvent;
use App\Enums\InspectionOutcome;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Enums\PieceCategory;
use App\Enums\SettingKey;
use App\Enums\SuspendedReason;
use App\Exceptions\DomainApiException;
use App\Models\BuyRequest;
use App\Models\InspectionResult;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Models\Staff;
use App\Support\Orders\DeadlinePolicy;
use App\Support\Orders\OrderSettlement;
use App\Support\Orders\StaffBranchScope;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The inspector records what IGI measured (spec 012 US4, FR-011, FR-012,
 * FR-012a, research R9; Part 2 §6, Part 3 §7). The row is immutable; the
 * server derives the karat mismatch, the weight difference and the outcome;
 * the client never sends an outcome. Effects, in the same transaction:
 * pass → awaiting the balance; weight adjust / stone regrade → waiting for
 * the buyer (a regrade first waits for staff's price); karat / counterfeit →
 * cancelled, the buyer refunded, the seller suspended, the piece returned. A
 * correction is a new row superseding the latest one, allowed only before
 * any decision or payment; it re-derives the outcome and re-runs the effects.
 */
final class RecordInspectionResultAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(
        private readonly StaffBranchScope $branches,
        private readonly Settings $settings,
        private readonly DeadlinePolicy $deadlines,
        private readonly OrderSettlement $settlement,
        private readonly ReleaseOrderDepositAction $release,
        private readonly SuspendSellerAction $suspend,
        private readonly OpenSellerReturnAction $returns,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @param  array{measured_karat?: int|null, measured_weight_g?: string|null, measured_stone_grade?: string|null,
     *               certificate_number?: string|null, inspector_note?: string|null, is_counterfeit?: bool,
     *               stone_below_claim?: bool, supersedes_id?: string|null}  $input
     * @return array{inspection: InspectionResult, order: Order}
     */
    public function handle(Staff $actor, string $orderId, array $input, ?RequestContext $ctx = null): array
    {
        $this->branches->guard($actor, $orderId, $ctx);

        return DB::transaction(function () use ($actor, $orderId, $input, $ctx) {
            $listingId = Order::query()->whereKey($orderId)->value('listing_id');
            $listing = $this->lockListing($listingId);
            $order = $this->lockOrder($orderId);
            $this->assertNotFrozen($order);
            $this->branches->assertCanActAt($actor, $order->branch_id);

            if ($listing->category !== PieceCategory::DIAMOND && (($input['measured_karat'] ?? null) === null || ($input['measured_weight_g'] ?? null) === null)) {
                throw ValidationException::withMessages(['measured_weight_g' => ['A piece with gold needs the measured karat and weight.']]);
            }

            $supersedes = $input['supersedes_id'] ?? null;
            $before = $order->state;
            $this->assertMayRecord($order, $supersedes);

            $result = InspectionResult::query()->create($this->row($order, $listing, $actor, $input, $supersedes));
            $result->refresh();

            $this->apply($order, $listing, $result, $actor, $supersedes !== null);

            $this->audit->execute(
                AuditEvent::INSPECTION_RESULT_RECORDED,
                'success',
                ['order_ref' => $order->order_ref, 'outcome' => $result->outcome->value, 'state' => $order->state->value,
                    'measured_karat' => $result->measured_karat, 'measured_weight_g' => $result->measured_weight_g === null ? null : (string) $result->measured_weight_g,
                    'supersedes_id' => $supersedes],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['state' => $before->value],
            );

            $this->flushOrderOutbox();

            return ['inspection' => $result, 'order' => $order];
        });
    }

    private function assertMayRecord(Order $order, ?string $supersedes): void
    {
        if ($supersedes === null) {
            if ($order->state !== OrderState::AT_INSPECTION) {
                throw DomainApiException::illegalOrderTransition();
            }

            return;
        }

        $latest = $order->latestInspection();
        $decided = $order->decisions()->exists();

        if (! in_array($order->state, [OrderState::WEIGHT_ADJUST_PENDING, OrderState::AWAITING_BALANCE], true)
            || $decided || $order->settlement_txn_id !== null || $latest?->inspection_id !== $supersedes) {
            throw DomainApiException::inspectionCorrectionNotAllowed();
        }
    }

    /** @return array<string, mixed> the row, with the derived columns (research R9; Part 3 §7.1) */
    private function row(Order $order, Listing $listing, Staff $actor, array $input, ?string $supersedes): array
    {
        $statedKarat = $listing->karat_code === null ? null : (int) $listing->karat_code;
        $statedWeight = $listing->stated_weight_g === null ? null : bcadd((string) $listing->stated_weight_g, '0', 3);
        $measuredKarat = isset($input['measured_karat']) ? (int) $input['measured_karat'] : null;
        $measuredWeight = isset($input['measured_weight_g']) ? bcadd((string) $input['measured_weight_g'], '0', 3) : null;
        $counterfeit = (bool) ($input['is_counterfeit'] ?? false);
        $belowClaim = (bool) ($input['stone_below_claim'] ?? false);

        $mismatch = $statedKarat !== $measuredKarat;
        $diff = $statedWeight !== null && $measuredWeight !== null && bccomp($statedWeight, '0', 3) > 0
            ? Money::round4(Money::mul(Money::div(Money::sub($measuredWeight, $statedWeight), $statedWeight), '100'))
            : null;
        $tolerance = $this->settings->numeric(SettingKey::INSPECTION_WEIGHT_TOLERANCE_PCT);

        $outcome = match (true) {
            $mismatch => InspectionOutcome::KARAT_CANCEL,
            $counterfeit => InspectionOutcome::FAKE_CANCEL,
            $belowClaim && $listing->category !== PieceCategory::GOLD => InspectionOutcome::STONE_REGRADE,
            $diff === null || Money::cmp(ltrim($diff, '-'), $tolerance) <= 0 => InspectionOutcome::PASS,
            default => InspectionOutcome::WEIGHT_ADJUST,
        };

        return [
            'order_id' => $order->order_id,
            'branch_id' => $order->branch_id,
            'inspected_by' => $actor->staff_id,
            'stated_karat' => $statedKarat,
            'stated_weight_g' => $statedWeight,
            'measured_karat' => $measuredKarat,
            'measured_weight_g' => $measuredWeight,
            'measured_stone_grade' => $input['measured_stone_grade'] ?? null,
            'certificate_number' => $input['certificate_number'] ?? null,
            'inspector_note' => $input['inspector_note'] ?? null,
            'is_counterfeit' => $counterfeit,
            'stone_below_claim' => $belowClaim,
            'karat_mismatch' => $mismatch,
            'weight_diff_pct' => $diff,
            'outcome' => $outcome,
            'supersedes_id' => $supersedes,
        ];
    }

    private function apply(Order $order, Listing $listing, InspectionResult $result, Staff $actor, bool $correction): void
    {
        $note = $correction ? OrderStateChange::NOTE_CORRECTED : null;
        $request = BuyRequest::query()->whereKey($order->buy_request_id)->firstOrFail();
        $order->setRelation('inspections', $order->inspections()->get());

        if ($result->outcome->cancels()) {
            $this->cancel($order, $listing, $actor, $note);

            return;
        }

        if ($listing->state === ListingState::AT_INSPECTION) {
            $listing = $this->moveListing($listing, ListingState::SETTLING, null, $actor, ListingStateChange::NOTE_INSPECTED);
        }

        if ($result->outcome === InspectionOutcome::PASS) {
            if ($order->state === OrderState::AT_INSPECTION) {
                $this->moveOrder($order, OrderState::INSPECTION_PASSED, null, $actor);
            }
            if ($order->state !== OrderState::AWAITING_BALANCE) {
                $this->moveOrder($order, OrderState::AWAITING_BALANCE, null, $actor, $note, [
                    'balance_due_deadline' => $this->deadlines->payWindow(),
                    'decision_due_deadline' => null,
                ]);
            }

            $f = $this->settlement->compute($order, $listing, $request, $result);
            $this->tellOrder($order->buyer_id, OrderEvent::RESULT_PASSED, $order, $listing, amount: $f->buyerTotal);
            $this->tellOrder($order->seller_id, OrderEvent::RESULT_PASSED, $order, $listing, amount: $f->buyerTotal);

            return;
        }

        // weight_adjust or stone_regrade: the buyer decides (a regrade once staff set the price).
        $weight = $result->outcome === InspectionOutcome::WEIGHT_ADJUST;
        $set = [
            'decision_due_deadline' => $weight ? $this->deadlines->payWindow() : null,
            'proposed_price' => null, 'proposed_by' => null, 'proposed_at' => null,
        ];
        if ($order->state === OrderState::WEIGHT_ADJUST_PENDING) {
            $order->forceFill($set)->save();
        } else {
            $this->moveOrder($order, OrderState::WEIGHT_ADJUST_PENDING, null, $actor, $note, $set);
        }

        if ($weight) {
            $price = $this->settlement->newPrice($order, $listing, $request, $result);
            $this->tellOrder($order->buyer_id, OrderEvent::RESULT_ADJUST, $order, $listing, amount: $price, deadline: $order->decision_due_deadline);
            $this->tellOrder($order->seller_id, OrderEvent::RESULT_BUYER_DECIDING, $order, $listing);
        } else {
            $this->tellOrder($order->buyer_id, OrderEvent::RESULT_REGRADE_PENDING, $order, $listing);
            $this->tellOrder($order->seller_id, OrderEvent::RESULT_REGRADE_PENDING, $order, $listing);
        }
    }

    /** Karat mismatch or counterfeit (Part 3 §7.2): refund, suspend the seller, return the piece without compensation. */
    private function cancel(Order $order, Listing $listing, Staff $actor, ?string $note): void
    {
        $this->moveOrder($order, OrderState::CANCELLED_INSPECTION, null, $actor, $note);
        $refunded = $this->release->handle($order, null, $actor->staff_id);
        $this->suspend->handle($order->seller_id, SuspendedReason::PIECE_MISREPRESENTED,
            "Inspection of order {$order->order_ref}: the piece was not what the listing said.", $actor);
        $opened = $this->returns->handle($order, $listing, null, null, $actor, ListingStateChange::NOTE_INSPECTION_CANCELLED);

        $this->tellOrder($order->buyer_id, OrderEvent::RESULT_CANCELLED, $order, $listing, amount: $refunded);
        $this->tellOrder($order->seller_id, OrderEvent::RESULT_CANCELLED, $order, $listing);
        $this->tellOrder($order->seller_id, OrderEvent::RETURN_WAITING, $order, $listing,
            deadline: $opened['return']->return_deadline, code: $opened['code']);
    }
}
