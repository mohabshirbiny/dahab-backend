<?php

namespace App\Actions\BuyRequests;

use App\Actions\BuyRequests\Concerns\ReleasesRequests;
use App\Actions\BuyRequests\Concerns\RunsInQueue;
use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Support\BuyRequests\DepositLedger;
use App\Support\BuyRequests\DepositRule;
use App\Support\Listings\ListingPricer;
use App\Support\Pricing\Settings;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A buyer asks to buy a piece and joins its line (spec 011 US1, FR-001–FR-009;
 * Part 2 §4, Part 3 §4). One transaction under the `queue` scope, the listing
 * locked first (the per-listing queue lock): check the piece can be bought
 * and is not the buyer's, the deposit terms, the price against the tolerance;
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
    ) {}

    public function handle(Customer $buyer, string $listingId, string $confirmedPrice, int $termsId, ?RequestContext $ctx = null): BuyRequest
    {
        return $this->inQueue(function () use ($buyer, $listingId, $confirmedPrice, $termsId, $ctx) {
            // Everything that has been on the market is readable here; drafts are not found.
            $seen = Listing::query()->whereKey($listingId)->firstOrFail();

            if ($seen->seller_id === $buyer->customer_id) {
                throw DomainApiException::cannotBuyOwnListing();
            }
            if (! $seen->state->isPublic()) {
                throw DomainApiException::listingNotPurchasable();
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

            $deposit = $this->rule->deposit($price);
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
            ]);

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
