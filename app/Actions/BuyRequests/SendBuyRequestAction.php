<?php

namespace App\Actions\BuyRequests;

use App\Actions\BuyRequests\Concerns\ReleasesRequests;
use App\Actions\BuyRequests\Concerns\RunsInQueue;
use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Enums\OrderState;
use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Support\BuyRequests\DepositLedger;
use App\Support\BuyRequests\DepositRule;
use App\Support\Controls\Controls;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPricer;
use App\Support\MarketMakers\PromoCodeGate;
use App\Support\Pricing\Settings;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A buyer asks to buy a piece and joins its line (spec 011 US1, FR-001–FR-009;
 * Part 2 §4, Part 3 §4). One transaction under the `queue` scope, the listing
 * locked first (the per-listing queue lock): check the piece can be bought
 * and is not the buyer's, that the buyer owes nothing on an order won before
 * (`awaiting_balance`, `weight_adjust_pending` → `balance_due_first`), the
 * deposit terms, the price against the tolerance;
 * take the next position; hold the deposit through the money service; record
 * the request and the acceptance of the terms; on the first request move the
 * listing `live → reserved`. The seller is told after commit. Not audited — a
 * customer action, recorded in the request and the ledger.
 */
final class SendBuyRequestAction
{
    use ReleasesRequests, RunsInQueue;

    public function __construct(
        private readonly DepositLedger $deposits,
        private readonly DepositRule $rule,
        private readonly Settings $settings,
        private readonly PromoCodeGate $promos,
    ) {}

    public function handle(Customer $buyer, string $listingId, string $confirmedPrice, int $termsId, ?RequestContext $ctx = null, ?string $promoCode = null): BuyRequest
    {
        try {
            return $this->send($buyer, $listingId, $confirmedPrice, $termsId, $ctx, $promoCode);
        } catch (DomainApiException $e) {
            // A refused use of a code is logged on its own: the request that failed rolled back.
            if ($promoCode !== null && $e->errorCode === 'promo_code_invalid') {
                DatabaseActor::queue(fn () => DB::transaction(
                    fn () => $this->promos->blocked($promoCode, $buyer, (string) ($e->details['reason'] ?? 'inactive'), $ctx),
                ));
            }

            throw $e;
        }
    }

    private function send(Customer $buyer, string $listingId, string $confirmedPrice, int $termsId, ?RequestContext $ctx, ?string $promoCode): BuyRequest
    {
        return $this->inQueue(function () use ($buyer, $listingId, $confirmedPrice, $termsId, $ctx, $promoCode) {
            // Spec 019 US7: no buy request while everything is stopped.
            app(Controls::class)->assertPlatformRunning();

            // Everything that has been on the market is readable here; drafts are not found, and
            // neither is a live piece of a paused category (it is off the market until reopened).
            $seen = Listing::query()->whereKey($listingId)->notHiddenByPause()->firstOrFail();

            if ($seen->seller_id === $buyer->customer_id) {
                throw DomainApiException::cannotBuyOwnListing();
            }
            if (! $seen->state->isPublic()) {
                throw DomainApiException::listingNotPurchasable();
            }

            // A buyer who owes the balance, or an answer to a new price, on an order settles it first.
            $owed = Order::query()->where('buyer_id', $buyer->customer_id)
                ->whereIn('state', [OrderState::AWAITING_BALANCE->value, OrderState::WEIGHT_ADJUST_PENDING->value])
                ->orderBy('created_at')->first(['order_id', 'order_ref', 'state']);
            if ($owed !== null) {
                throw DomainApiException::balanceDueFirst([
                    'order_id' => $owed->order_id,
                    'order_ref' => $owed->order_ref,
                    'order_state' => $owed->state->value,
                ]);
            }

            $terms = LegalDocument::current(LegalDocument::DEPOSIT_AGREEMENT);
            if ($terms === null || $termsId !== $terms->legal_doc_id) {
                throw DomainApiException::depositAgreementRequired();
            }

            $listing = $this->lockListing($listingId);
            if (! $listing->state->isPublic()) {
                throw DomainApiException::listingNotPurchasable();
            }

            if (BuyRequest::query()->where('listing_id', $listingId)->where('buyer_id', $buyer->customer_id)
                ->whereIn('state', [BuyRequestState::QUEUED->value, BuyRequestState::ACCEPTED->value])->exists()) {
                throw DomainApiException::alreadyInQueue();
            }

            $pricer = app(ListingPricer::class);
            $price = $pricer->quote($listing)->currentPrice;
            if ($price === null) {
                throw DomainApiException::priceUnavailable();
            }

            // Spec 019 6B: a market-maker code is checked under the code's row lock, with the listing locked.
            $promo = $promoCode === null ? null : $this->promos->validate($buyer, $listing, $price, $promoCode);

            // The deposit as the settings set it, then lowered by a buyer code on stones (never below the minimum).
            $fullDeposit = $this->rule->deposit($price);
            $deposit = $this->rule->discounted($fullDeposit, $promo);
            if (! $this->rule->withinTolerance($confirmedPrice, $price)) {
                throw DomainApiException::priceMoved($price, $deposit);
            }

            $available = $this->deposits->available($buyer->customer_id);
            if (bccomp($available, $deposit, 4) < 0) {
                throw DomainApiException::insufficientFunds(self::shortfall($deposit, $available));
            }

            $seq = DB::table('listing_queue_seq')->where('listing_id', $listingId)->lockForUpdate()->first();
            if ($seq === null) {
                throw DomainApiException::listingNotPurchasable();
            }
            DB::table('listing_queue_seq')->where('listing_id', $listingId)->update(['next_pos' => $seq->next_pos + 1]);

            $acceptance = AgreementAcceptance::query()->create([
                'customer_id' => $buyer->customer_id,
                'legal_doc_id' => $terms->legal_doc_id,
                'context' => AgreementAcceptance::CONTEXT_BUY_REQUEST,
                'ip_address' => filled($ctx?->ip) ? $ctx->ip : null,
                'device_fingerprint' => $ctx?->deviceFingerprintHash,
            ]);

            $id = (string) Str::uuid();
            try {
                $hold = $this->deposits->hold($id, $listingId, $buyer->customer_id, $deposit);
            } catch (DomainApiException $e) {
                // The money service's own check lost a race with another spend.
                throw $e->errorCode === 'insufficient_funds'
                    ? DomainApiException::insufficientFunds(self::shortfall($deposit, $this->deposits->available($buyer->customer_id)))
                    : $e;
            }

            $now = CarbonImmutable::now();
            $request = BuyRequest::query()->create([
                'buy_request_id' => $id,
                'listing_id' => $listingId,
                'buyer_id' => $buyer->customer_id,
                'state' => BuyRequestState::QUEUED,
                'queue_position' => (int) $seq->next_pos,
                'locked_unit_rate' => $listing->karat_code === null ? null : $pricer->karatPrices($listing->karat_code)?->buyersPay,
                'locked_total_price' => $price,
                'deposit_amount' => $deposit,
                'deposit_hold_txn_id' => $hold->ledger_txn_id,
                'deposit_acceptance_id' => $acceptance->acceptance_id,
                'requested_at' => $now,
                'seller_reply_deadline' => $now->addHours($this->settings->integer(SettingKey::DEADLINE_SELLER_REPLY_HOURS)),
                'promo_code' => $promo?->code,
                // What the code does is kept on the request: the settlement runs where promo_code is unreadable.
                'promo_waives_commission' => $promo?->kind === 'market_maker',
                'promo_gives_spread' => (bool) $promo?->gives_spread,
                'deposit_discount_egp' => bcsub($fullDeposit, $deposit, 4),
            ]);

            if ($promo !== null) {
                $this->promos->allowed($promo, $buyer, $id, $price, $ctx);
            }

            if ($listing->state === ListingState::LIVE) {
                $listing = $this->moveListing($listing, ListingState::RESERVED, $buyer, null, ListingStateChange::NOTE_BUY_REQUEST_QUEUED);
            }

            $this->tell($listing->seller_id, BuyRequestEvent::NEW_REQUEST, $listing, deadline: $request->seller_reply_deadline->toIso8601String());
            $this->flushAfterCommit();

            return $request->refresh();
        });
    }

    /** @return array<string, string> */
    private static function shortfall(string $deposit, string $available): array
    {
        $short = bcsub($deposit, $available, 4);

        return [
            'deposit_amount' => $deposit,
            'available' => bcadd($available, '0', 4),
            'shortfall' => bccomp($short, '0', 4) > 0 ? $short : '0.0000',
        ];
    }
}
