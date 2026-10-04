<?php

namespace App\Http\Resources\Customer;

use App\Actions\Disputes\Customer\OpenDisputeAction;
use App\Enums\ExtensionRequestState;
use App\Enums\InspectionOutcome;
use App\Enums\ListingMediaKind;
use App\Enums\ListingState;
use App\Enums\OrderState;
use App\Http\Resources\ListingMediaResource;
use App\Models\Branch;
use App\Models\Dispute;
use App\Models\Listing;
use App\Models\Order;
use App\Support\Orders\DeadlinePolicy;
use App\Support\Orders\OrderSettlement;
use App\Support\Orders\OrderTimeline;
use App\Support\Pricing\PricingContext;
use App\Support\Wallet\HeldByRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One of the customer's own orders, as the buyer or the seller (spec 012
 * FR-001, research R19). The other party appears only as `counterparty_ref`;
 * the seller never sees what the buyer owes and the buyer never sees the
 * seller's proceeds. Codes appear only in the order's own detail, to their
 * owner (`withCode()`), never in a list.
 */
#[OA\Schema(
    schema: 'CustomerOrder',
    description: 'A customer\'s own order (spec 012). Money as 4-dp strings, weight 3-dp. Never names the other party.',
    required: ['id', 'order_ref', 'role', 'state', 'stage', 'piece', 'branch', 'counterparty_ref', 'locked_total_price', 'deposit_amount', 'deadline', 'actions', 'timeline'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_ref', type: 'string', example: 'DH-2026-000042'),
        new OA\Property(property: 'role', type: 'string', enum: ['buyer', 'seller']),
        new OA\Property(property: 'state', type: 'string', description: 'An order_state value'),
        new OA\Property(property: 'stage', type: 'string', enum: ['bring_piece', 'at_igi', 'decide', 'pay', 'collect', 'done', 'cancelled']),
        new OA\Property(property: 'piece', type: 'object'),
        new OA\Property(property: 'branch', type: 'object', description: 'id, name_en, name_ar, address_en, address_ar, hours[{dow, opens_at, closes_at}]'),
        new OA\Property(property: 'counterparty_ref', type: 'string', description: 'The other party\'s display reference'),
        new OA\Property(property: 'locked_total_price', type: 'string'),
        new OA\Property(property: 'deposit_amount', type: 'string'),
        new OA\Property(property: 'deposit_held', type: 'string', nullable: true, description: 'Spec 015: what this order holds in the buyer\'s wallet now, from the ledger; null for the seller'),
        new OA\Property(property: 'deadline', type: 'object', nullable: true, description: '{kind: reach_branch|decision|balance|collect|return, at, overdue}'),
        new OA\Property(property: 'amount_due', type: 'string', nullable: true, description: 'Buyer, awaiting balance'),
        new OA\Property(property: 'final_total', type: 'string', nullable: true, description: 'Buyer: the total on the measured weight'),
        new OA\Property(property: 'seller_proceeds', type: 'string', nullable: true, description: 'Seller, once paid'),
        new OA\Property(property: 'inspection', type: 'object', nullable: true),
        new OA\Property(property: 'collection', type: 'object', nullable: true),
        new OA\Property(property: 'seller_return', type: 'object', nullable: true),
        new OA\Property(property: 'cancel', type: 'object', nullable: true, description: '{state, at, reason_kind: seller|deadline_missed|staff|inspection|declined|no_answer|no_pay|dispute}'),
        new OA\Property(property: 'actions', type: 'array', items: new OA\Items(type: 'string', enum: ['cancel', 'decide', 'pay', 'relist', 'report_problem', 'ask_more_time', 'name_proxy']), description: 'Spec 014 adds report_problem, ask_more_time (seller), name_proxy (buyer)'),
        new OA\Property(property: 'frozen', type: 'boolean', description: 'Spec 014: on hold by a dispute (state disputed)'),
        new OA\Property(property: 'dispute', ref: '#/components/schemas/CustomerDispute', nullable: true, description: 'Spec 014: your own dispute on this order, if you raised one; never the other party\'s'),
        new OA\Property(property: 'dispute_outcome', type: 'string', enum: ['resumed', 'cancelled'], nullable: true, description: 'Spec 014: from the order history, once a dispute on it was resolved (either party)'),
        new OA\Property(property: 'extension_request', type: 'object', nullable: true, description: 'Spec 014, the seller only: {state: waiting|accepted|refused|lapsed, reason, detail, hours_granted, answer_note, requested_at, answered_at}'),
        new OA\Property(property: 'proxy', type: 'object', nullable: true, description: 'Spec 014, the buyer only: {name, phone_masked, named_at}'),
        new OA\Property(property: 'timeline', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'collection_code', type: 'string', nullable: true, description: 'Detail only, the buyer, while ready to collect'),
        new OA\Property(property: 'return_code', type: 'string', nullable: true, description: 'Detail only, the seller, while the return is open'),
    ],
)]
class CustomerOrderResource extends JsonResource
{
    private bool $withCode = false;

    public function withCode(): self
    {
        $this->withCode = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;
        $me = (string) $request->user('customer')?->getKey();
        $seller = $o->seller_id === $me;
        $listing = $o->listing;
        $result = $o->latestInspection();
        $settlement = app(OrderSettlement::class);
        // Read once per request, not once per order in a list (no N+1).
        $rates = $request->attributes->get('order_settlement_rates')
            ?? tap(app(PricingContext::class)->rates(), fn ($r) => $request->attributes->set('order_settlement_rates', $r));
        $deadline = app(DeadlinePolicy::class)->running($o, $o->sellerReturn);
        $return = $o->sellerReturn;
        $collection = $o->collection;

        $newPrice = null;
        if ($result !== null && $result->outcome->needsDecision()) {
            $newPrice = $settlement->newPrice($o, $listing, $o->buyRequest, $result, $rates);
        }

        $amountDue = null;
        $finalTotal = $o->final_buyer_total === null ? null : bcadd((string) $o->final_buyer_total, '0', 4);
        if (! $seller && $o->state === OrderState::AWAITING_BALANCE) {
            $f = $settlement->compute($o, $listing, $o->buyRequest, $result, $rates);
            $amountDue = $f->balance;
            $finalTotal = $f->buyerTotal;
        }

        $cancelChange = $o->state->isCancelled()
            ? $o->stateChanges->first(fn ($c) => $c->to_state === $o->state)
            : null;

        $data = [
            'id' => $o->order_id,
            'order_ref' => $o->order_ref,
            'role' => $seller ? 'seller' : 'buyer',
            'state' => $o->state->value,
            'stage' => $o->state->customerStage(),
            'piece' => self::piece($listing),
            'branch' => self::branch($o->branch),
            'counterparty_ref' => $seller ? $o->buyer?->display_ref : $o->seller?->display_ref,
            'locked_total_price' => bcadd((string) $o->locked_total_price, '0', 4),
            'deposit_amount' => bcadd((string) $o->buyRequest->deposit_amount, '0', 4),
            'deposit_held' => $seller ? null : app(HeldByRequest::class)->of($o->buy_request_id),
            'deadline' => $deadline === null ? null : [
                'kind' => $deadline['kind']->value,
                'at' => $deadline['at']->toIso8601String(),
                'overdue' => $deadline['overdue'],
            ],
            'amount_due' => $amountDue,
            'final_total' => $seller ? null : $finalTotal,
            'seller_proceeds' => $seller && $o->seller_proceeds !== null ? bcadd((string) $o->seller_proceeds, '0', 4) : null,
            'inspection' => $result === null ? null : [
                'inspection_id' => $result->inspection_id,
                'outcome' => $result->outcome->value,
                'stated_karat' => $result->stated_karat,
                'measured_karat' => $result->measured_karat,
                'stated_weight_g' => $result->stated_weight_g === null ? null : (string) $result->stated_weight_g,
                'measured_weight_g' => $result->measured_weight_g === null ? null : (string) $result->measured_weight_g,
                'weight_diff_pct' => $result->weight_diff_pct === null ? null : (string) $result->weight_diff_pct,
                'measured_stone_grade' => $result->measured_stone_grade,
                'certificate_number' => $result->certificate_number,
                'inspector_note' => $result->inspector_note,
                'inspected_at' => $result->created_at->toIso8601String(),
                'new_price' => $newPrice,
                'price_pending' => $result->outcome === InspectionOutcome::STONE_REGRADE && $o->proposed_price === null,
                'decision_needed' => $o->state === OrderState::WEIGHT_ADJUST_PENDING,
            ],
            'collection' => $collection === null || $seller ? null : [
                'code_available' => $collection->collected_at === null,
                'collect_deadline' => $o->collect_deadline?->toIso8601String(),
                'collected_at' => $collection->collected_at?->toIso8601String(),
                'window_passed' => $listing->state === ListingState::UNCOLLECTED_EXPIRED,
            ],
            'seller_return' => $return === null || ! $seller ? null : [
                'return_deadline' => $return->return_deadline->toIso8601String(),
                'code_available' => $return->isOpen(),
                'can_relist' => $return->isOpen() && $listing->state === ListingState::AWAITING_SELLER_RETURN,
                'collected_at' => $return->collected_at?->toIso8601String(),
                'relisted_at' => $return->relisted_at?->toIso8601String(),
                'window_passed' => $listing->state === ListingState::SELLER_UNCLAIMED,
            ],
            'cancel' => $cancelChange === null ? null : [
                'state' => $o->state->value,
                'at' => $cancelChange->changed_at->toIso8601String(),
                'reason_kind' => OrderTimeline::cancelKind($cancelChange),
            ],
            'actions' => self::actions($o, $seller, $listing, $me),
            'timeline' => OrderTimeline::build($o, forStaff: false),
            // Spec 014. Row security returns only the caller's own dispute and requests.
            'frozen' => $o->state === OrderState::DISPUTED,
            'dispute' => ($own = self::ownDispute($o, $me)) === null ? null : (new DisputeResource($own))->resolve($request),
            'dispute_outcome' => self::disputeOutcome($o),
            'extension_request' => $seller ? self::extensionRequest($o) : null,
            'proxy' => ! $seller && $collection !== null && $collection->is_proxy ? [
                'name' => $collection->proxy_name,
                'phone_masked' => self::maskPhone((string) $collection->proxy_phone),
                'named_at' => $collection->proxy_named_at?->toIso8601String(),
            ] : null,
        ];

        if ($this->withCode) {
            $data['collection_code'] = ! $seller && $collection !== null && $collection->collected_at === null && $o->state === OrderState::READY_TO_COLLECT
                ? $collection->code_encrypted : null;
            $data['return_code'] = $seller && $return !== null && $return->isOpen() ? $return->code_encrypted : null;
        }

        return $data;
    }

    /** @return list<string> what the caller may do now */
    private static function actions(Order $o, bool $seller, Listing $listing, string $me): array
    {
        // Spec 014: each party reports at most one problem per order, from the four freezable states.
        $report = in_array($o->state, OpenDisputeAction::FREEZABLE, true) && self::ownDispute($o, $me) === null ? 'report_problem' : null;

        if ($seller) {
            $waiting = ($o->relationLoaded('extensionRequests') ? $o->extensionRequests : $o->extensionRequests()->get())
                ->contains(fn ($r) => $r->state === ExtensionRequestState::WAITING);

            return array_values(array_filter([
                $o->state === OrderState::AWAITING_DELIVERY ? 'cancel' : null,
                $o->sellerReturn?->isOpen() && $listing->state === ListingState::AWAITING_SELLER_RETURN ? 'relist' : null,
                $report,
                $o->state === OrderState::AWAITING_DELIVERY && $o->reach_branch_deadline?->isFuture() && ! $waiting ? 'ask_more_time' : null,
            ]));
        }

        return array_values(array_filter([
            $o->state === OrderState::WEIGHT_ADJUST_PENDING && $o->decision_due_deadline !== null ? 'decide' : null,
            $o->state === OrderState::AWAITING_BALANCE && $o->balance_due_deadline?->isFuture() ? 'pay' : null,
            $report,
            $o->state === OrderState::READY_TO_COLLECT && $o->collection !== null && $o->collection->collected_at === null ? 'name_proxy' : null,
        ]));
    }

    /** The caller's own dispute (row security already hides the other side's). */
    private static function ownDispute(Order $o, string $me): ?Dispute
    {
        $all = $o->relationLoaded('disputes') ? $o->disputes : $o->disputes()->get();

        return $all->first(fn (Dispute $d) => $d->raised_by === $me)?->setRelation('order', $o);
    }

    /** Spec 014: resumed or cancelled, read from the order's own history (never the dispute rows). */
    private static function disputeOutcome(Order $o): ?string
    {
        $last = $o->stateChanges->filter(fn ($c) => $c->from_state === OrderState::DISPUTED)->last();

        return $last === null ? null : ($last->to_state === OrderState::CANCELLED_INSPECTION ? 'cancelled' : 'resumed');
    }

    /** @return array<string, mixed>|null the seller's latest request for more time */
    private static function extensionRequest(Order $o): ?array
    {
        $r = ($o->relationLoaded('extensionRequests') ? $o->extensionRequests : $o->extensionRequests()->get())->last();

        return $r === null ? null : [
            'state' => $r->state->value,
            'reason' => $r->reason->value,
            'detail' => $r->detail,
            'hours_granted' => $r->hours_granted,
            'answer_note' => $r->answer_note,
            'requested_at' => $r->requested_at->toIso8601String(),
            'answered_at' => $r->answered_at?->toIso8601String(),
        ];
    }

    private static function maskPhone(string $phone): string
    {
        return strlen($phone) <= 7 ? $phone : substr($phone, 0, 4).str_repeat('•', strlen($phone) - 7).substr($phone, -3);
    }

    /** @return array<string, mixed> */
    public static function piece(Listing $l): array
    {
        return [
            'listing_id' => $l->listing_id,
            'category' => $l->category->value,
            'piece_type' => ['id' => $l->piece_type_id, 'name_en' => $l->pieceType?->name_en, 'name_ar' => $l->pieceType?->name_ar],
            'karat' => $l->karat_code,
            'weight_g' => $l->stated_weight_g === null ? null : bcadd((string) $l->stated_weight_g, '0', 3),
            'listing_state' => $l->state->value,
            'photo' => $l->relationLoaded('photos')
                ? ListingMediaResource::first($l->photos->where('is_private', false), ListingMediaKind::PHOTO, ListingMediaResource::MARKET)
                : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function branch(?Branch $b): ?array
    {
        return $b === null ? null : [
            'id' => $b->branch_id,
            'name_en' => $b->name_en,
            'name_ar' => $b->name_ar,
            'address_en' => $b->address_en,
            'address_ar' => $b->address_ar,
            'hours' => $b->relationLoaded('hours') ? $b->hours->map(fn ($h) => [
                'dow' => $h->dow, 'opens_at' => substr((string) $h->opens_at, 0, 5), 'closes_at' => substr((string) $h->closes_at, 0, 5),
            ])->values()->all() : [],
        ];
    }
}
