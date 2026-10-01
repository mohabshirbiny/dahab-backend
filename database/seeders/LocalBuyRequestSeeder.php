<?php

namespace Database\Seeders;

use App\Actions\BuyRequests\AcceptBuyRequestAction;
use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Enums\ListingState;
use App\Enums\PriceSource;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\GoldPrice;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPricer;
use App\Support\SystemActor;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Buy requests to try by hand (spec 011), made through the real Actions —
 * the deposit held on the ledger, the place in line, the listing reserved,
 * the order opened — never written directly. Uses the LocalListingSeeder
 * pieces and the LocalLedgerSeeder money:
 *
 *  - Hoda (900006) is first in line for Karim's gold earrings with diamonds:
 *    Karim can Accept or Decline in the app.
 *  - Karim (900007) asked for Hoda's 21K ring and Hoda accepted: an order
 *    awaiting delivery, for the Dashboard's Accepted chip and Cancel acceptance.
 *  - Hoda's 18K ring stays live, for sending a request by hand.
 *
 * Gold pieces need a gold price: when there is none at all it records one demo
 * price (the calculator never reads its age). Refuses to run outside
 * local/testing; does nothing when any buy request exists.
 */
class LocalBuyRequestSeeder extends Seeder
{
    public function run(SendBuyRequestAction $send, AcceptBuyRequestAction $accept): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalBuyRequestSeeder skipped: only runs in local/testing.');

            return;
        }

        if (BuyRequest::query()->exists()) {
            $this->command?->info('LocalBuyRequestSeeder: buy requests already there.');

            return;
        }

        $hoda = Customer::query()->where('display_ref', '900006')->first();
        $karim = Customer::query()->where('display_ref', '900007')->first();
        $terms = LegalDocument::current(LegalDocument::DEPOSIT_AGREEMENT);

        $earrings = $karim === null ? null : $this->live($karim, fn ($q) => $q->whereNotNull('asking_price')->whereNotNull('karat_code'));
        $ring = $hoda === null ? null : $this->live($hoda, fn ($q) => $q->where('karat_code', 21));

        if ($hoda === null || $karim === null || $terms === null || $earrings === null || $ring === null) {
            $this->command?->warn('LocalBuyRequestSeeder skipped: run LocalCustomerSeeder, LocalLedgerSeeder and LocalListingSeeder first.');

            return;
        }

        if (GoldPrice::query()->doesntExist()) {
            GoldPrice::query()->create(['source' => PriceSource::FEED, 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id()]);
            $this->command?->info('LocalBuyRequestSeeder: no gold price yet, recorded a demo one (24K 7944 / 7990).');
        }

        try {
            // Hoda waits in Karim's line.
            $this->as($hoda, fn () => $send->handle($hoda, $earrings->listing_id, $this->price($earrings), (int) $terms->legal_doc_id));

            // Karim asks for Hoda's ring; Hoda accepts at the piece's first branch.
            $request = $this->as($karim, fn () => $send->handle($karim, $ring->listing_id, $this->price($ring), (int) $terms->legal_doc_id));
            $branchId = (int) DB::table('listing_branch_option')->where('listing_id', $ring->listing_id)->orderBy('branch_id')->value('branch_id');
            $order = $this->as($hoda, fn () => $accept->handle($hoda, $ring->listing_id, $request->buy_request_id, $branchId))['order'];
        } catch (Throwable $e) {
            $this->command?->warn('LocalBuyRequestSeeder stopped: '.$e->getMessage());

            return;
        }

        $this->command?->info("LocalBuyRequestSeeder: 1 buyer in line on Karim's earrings; order {$order->order_ref} on Hoda's ring.");
    }

    /** The seller's first live piece matching `$where`. */
    private function live(Customer $seller, Closure $where): ?Listing
    {
        return $where(Listing::query()->where('seller_id', $seller->customer_id)->where('state', ListingState::LIVE->value))
            ->orderBy('created_at')->first();
    }

    /** What the market shows the buyer now: the price they confirm. */
    private function price(Listing $listing): string
    {
        return (string) app(ListingPricer::class)->quote($listing)->currentPrice;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function as(Customer $customer, Closure $work): mixed
    {
        DatabaseActor::push('customer', customerId: $customer->customer_id);

        try {
            return $work();
        } finally {
            DatabaseActor::pop();
        }
    }
}
