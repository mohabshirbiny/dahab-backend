<?php

namespace Database\Seeders;

use App\Actions\BuyRequests\AcceptBuyRequestAction;
use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Actions\Inspections\RecordInspectionResultAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Actions\Orders\Customer\CancelOrderBySellerAction;
use App\Actions\Orders\Customer\PayBalanceAction;
use App\Actions\Orders\ForfeitDepositAction;
use App\Actions\Orders\Staff\HandoverPieceAction;
use App\Actions\Orders\Staff\ReceivePieceAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Enums\ListingMediaKind;
use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Listings\ListingPricer;
use App\Support\SystemActor;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\ListingFactory;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orders in each state to try by hand (spec 012, research R23), made through
 * the real Actions — the deposit held, the acceptance, the receipt, the
 * inspection result, the payment, the handover, the cancellation, the
 * forfeit — never written directly. Hoda (900006) sells a fresh 21K ring per
 * order (a factory listing walked to live, as LocalListingSeeder does) and
 * Karim (900007) buys it; the CEO account (every permission, no assigned
 * branch) acts for Dahab. Karim first gets a demo top-up through the money
 * service so every deposit and balance can be paid.
 *
 * The forfeited order needs the balance window to pass: the clock is moved
 * (Carbon::setTestNow) for that one pass only. Refuses to run outside
 * local/testing; does nothing when an order has moved past acceptance.
 */
class LocalOrderSeeder extends Seeder
{
    public const MARKER = 'Demo data (LocalOrderSeeder)';

    /** @var list<string> */
    private array $made = [];

    public function run(
        SendBuyRequestAction $send,
        AcceptBuyRequestAction $accept,
        ReceivePieceAction $receive,
        RecordInspectionResultAction $inspect,
        PayBalanceAction $pay,
        HandoverPieceAction $handover,
        CancelOrderBySellerAction $cancel,
        ForfeitDepositAction $forfeit,
        PostLedgerEntryAction $post,
    ): void {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalOrderSeeder skipped: only runs in local/testing.');

            return;
        }

        if (Order::query()->where('state', '!=', 'awaiting_delivery')->exists()) {
            $this->command?->info('LocalOrderSeeder: orders past acceptance already there.');

            return;
        }

        $hoda = Customer::query()->where('display_ref', '900006')->first();
        $karim = Customer::query()->where('display_ref', '900007')->first();
        $staff = Staff::query()->where('email', SeedRole::CEO->value.'@dahab.test')->first();
        $finance = Staff::query()->where('email', SeedRole::FINANCE->value.'@dahab.test')->first();
        $terms = LegalDocument::current(LegalDocument::DEPOSIT_AGREEMENT);

        if ($hoda === null || $karim === null || $staff === null || $finance === null || $terms === null) {
            $this->command?->warn('LocalOrderSeeder skipped: run LocalStaffSeeder, LocalCustomerSeeder and LocalBuyRequestSeeder first.');

            return;
        }

        $this->fund($post, $karim, $finance);

        // One fresh piece, requested by Karim and accepted by Hoda: an order awaiting delivery.
        $order = function (string $label) use ($hoda, $karim, $send, $accept, $terms): Order {
            $listing = $this->piece($hoda);
            $price = (string) app(ListingPricer::class)->quote($listing)->currentPrice;
            $request = $this->asCustomer($karim, fn () => $send->handle($karim, $listing->listing_id, $price, (int) $terms->legal_doc_id));
            $branchId = (int) DB::table('listing_branch_option')->where('listing_id', $listing->listing_id)->orderBy('branch_id')->value('branch_id');
            $order = $this->asCustomer($hoda, fn () => $accept->handle($hoda, $listing->listing_id, $request->buy_request_id, $branchId))['order'];
            $this->made[] = "{$label}: {$order->order_ref}";

            return $order;
        };
        $received = fn (Order $o) => $this->asStaff($staff, fn () => $receive->handle($staff, $o->order_id));
        $result = fn (Order $o, array $input) => $this->asStaff($staff, fn () => $inspect->handle($staff, $o->order_id, $input));
        $paid = fn (Order $o) => $this->asCustomer($karim, fn () => $pay->handle($karim, $o->order_id));

        try {
            $received($order('at inspection'));

            $o = $order('buyer deciding (weight 9.50 g of 10.00 g)');
            $received($o);
            $result($o, ['measured_karat' => 21, 'measured_weight_g' => '9.500', 'inspector_note' => 'Weighed twice; 9.50 g.']);

            $o = $order('waiting for the balance');
            $received($o);
            $result($o, ['measured_karat' => 21, 'measured_weight_g' => '10.000', 'certificate_number' => 'IGI-EG-10001']);

            $o = $order('ready to collect');
            $received($o);
            $result($o, ['measured_karat' => 21, 'measured_weight_g' => '10.000']);
            $paid($o);

            $o = $order('completed');
            $received($o);
            $result($o, ['measured_karat' => 21, 'measured_weight_g' => '10.000']);
            $paid($o);
            $code = DatabaseActor::elevate('system', fn () => OrderCollection::query()->where('order_id', $o->order_id)->sole()->code_encrypted);
            $this->asStaff($staff, fn () => $handover->handle($staff, $o->order_id, $code));

            $o = $order('cancelled by the seller');
            $this->asCustomer($hoda, fn () => $cancel->bySeller($hoda, $o->order_id));

            $o = $order('cancelled at inspection (karat 18 of 21), piece held for the seller');
            $received($o);
            $result($o, ['measured_karat' => 18, 'measured_weight_g' => '10.000', 'inspector_note' => 'Hallmark reads 21K; the metal tests as 18K.']);

            $o = $order('buyer did not pay (deposit forfeited), piece held for the seller');
            $received($o);
            $result($o, ['measured_karat' => 21, 'measured_weight_g' => '10.000']);
            $due = Order::query()->whereKey($o->order_id)->value('balance_due_deadline');
            CarbonImmutable::setTestNow(CarbonImmutable::parse($due)->addMinute());
            try {
                DatabaseActor::elevate('system', fn () => $forfeit->handle(Staff::query()->findOrFail(SystemActor::id()), $o->order_id));
            } finally {
                CarbonImmutable::setTestNow();
            }
        } catch (Throwable $e) {
            $this->command?->warn('LocalOrderSeeder stopped: '.$e->getMessage());
        }

        $this->command?->info('LocalOrderSeeder: '.count($this->made).' orders.');
        foreach ($this->made as $line) {
            $this->command?->line("  {$line}");
        }
    }

    /** Enough in Karim's wallet for every deposit and balance (a demo top-up, through the money service). */
    private function fund(PostLedgerEntryAction $post, Customer $karim, Staff $finance): void
    {
        if (LedgerTransaction::query()->where('memo', 'like', self::MARKER.'%')->exists()) {
            return;
        }
        DB::transaction(fn () => $post->handle(new LedgerEntry(LedgerEventKind::TOPUP, [
            new LedgerLine(Account::internal(AccountKind::BANK), '-500000'),
            new LedgerLine(Account::forCustomerKind($karim->customer_id, AccountKind::CUST_AVAILABLE), '500000'),
        ], actorStaffId: $finance->staff_id, memo: self::MARKER.': bank transfer matched by reference')));
    }

    /** A live 21K ring of Hoda's, 10.000 g, with two photos. */
    private function piece(Customer $seller): Listing
    {
        $reviewer = Staff::query()->where('email', SeedRole::OPERATIONS->value.'@dahab.test')->value('staff_id');

        return DB::transaction(function () use ($seller, $reviewer) {
            $listing = Listing::factory()->gold()->create([
                'seller_id' => $seller->customer_id, 'karat_code' => 21, 'stated_weight_g' => '10.000', 'making_charge_per_g' => '250.00',
            ]);
            foreach ([0, 1] as $i) {
                ListingFactory::attachMedia($listing, ListingMediaKind::PHOTO, $this->photo(count($this->made) * 2 + $i), 'image/png', $i);
            }
            ListingFactory::walk($listing, [ListingState::IN_REVIEW, ListingState::LIVE], null, $reviewer);

            return $listing->refresh();
        });
    }

    /** A small coloured square, as a real PNG on disk. */
    private function photo(int $seed): UploadedFile
    {
        $image = imagecreatetruecolor(320, 320);
        imagefill($image, 0, 0, imagecolorallocate($image, 200 + ($seed * 7) % 50, 160 + ($seed * 13) % 60, 70 + ($seed * 29) % 80));
        imagefilledellipse($image, 160, 160, 180, 180, imagecolorallocate($image, 235, 205, 110));
        $path = (string) tempnam(sys_get_temp_dir(), 'seed');
        imagepng($image, $path);

        return new UploadedFile($path, "order{$seed}.png", 'image/png', null, true);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function asCustomer(Customer $customer, Closure $work): mixed
    {
        DatabaseActor::push('customer', customerId: $customer->customer_id);

        try {
            return $work();
        } finally {
            DatabaseActor::pop();
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function asStaff(Staff $staff, Closure $work): mixed
    {
        DatabaseActor::push('staff', staffId: $staff->staff_id);

        try {
            return $work();
        } finally {
            DatabaseActor::pop();
        }
    }
}
