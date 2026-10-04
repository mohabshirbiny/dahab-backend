<?php

namespace Database\Seeders;

use App\Actions\BuyRequests\AcceptBuyRequestAction;
use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Actions\Disputes\Customer\OpenDisputeAction;
use App\Actions\Disputes\Staff\PassOnDisputeAction;
use App\Actions\Disputes\Staff\ResolveDisputeAction;
use App\Actions\Identity\CreateCustomerUploadAction;
use App\Actions\Inspections\RecordInspectionResultAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Actions\Orders\Customer\NameProxyAction;
use App\Actions\Orders\Customer\PayBalanceAction;
use App\Actions\Orders\Customer\RequestMoreTimeAction;
use App\Actions\Orders\Staff\AnswerExtensionRequestAction;
use App\Actions\Orders\Staff\ReceivePieceAction;
use App\Enums\AccountKind;
use App\Enums\CompensationReason;
use App\Enums\DisputeOutcome;
use App\Enums\DisputeReason;
use App\Enums\ExtensionRequestReason;
use App\Enums\LedgerEventKind;
use App\Enums\ListingMediaKind;
use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Enums\UploadPurpose;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Dispute;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Listings\ListingPricer;
use Closure;
use Database\Factories\ListingFactory;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Disputes, proxy collection and requests for more time to try by hand (spec
 * 014, research R21), made through the real Actions — never written
 * directly. Hoda (900006) sells, Karim (900007) buys; fresh 21K rings as
 * LocalOrderSeeder makes them.
 *
 *  - an order frozen at awaiting balance by Karim's open dispute, with a photo;
 *  - an order frozen when ready to collect by Hoda, passed on to the COO;
 *  - a dispute resolved "resume" with 250 EGP compensation to Karim;
 *  - a dispute resolved against the sale (deposit refunded, piece returned);
 *  - a ready-to-collect order with a proxy named (Ahmed Samir Hassan);
 *  - requests for more time: waiting, accepted (12 working hours), refused.
 *
 * Refuses to run outside local/testing; does nothing when a dispute exists.
 */
class LocalDisputeSeeder extends Seeder
{
    public const MARKER = 'Demo data (LocalDisputeSeeder)';

    /** @var list<string> */
    private array $made = [];

    public function run(
        SendBuyRequestAction $send,
        AcceptBuyRequestAction $accept,
        ReceivePieceAction $receive,
        RecordInspectionResultAction $inspect,
        PayBalanceAction $pay,
        OpenDisputeAction $open,
        PassOnDisputeAction $passOn,
        ResolveDisputeAction $resolve,
        NameProxyAction $nameProxy,
        RequestMoreTimeAction $askMoreTime,
        AnswerExtensionRequestAction $answer,
        CreateCustomerUploadAction $uploads,
        PostLedgerEntryAction $post,
    ): void {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalDisputeSeeder skipped: only runs in local/testing.');

            return;
        }
        if (DatabaseActor::elevate('system', fn () => Dispute::query()->exists())) {
            $this->command?->info('LocalDisputeSeeder: disputes already there.');

            return;
        }

        $hoda = Customer::query()->where('display_ref', '900006')->first();
        $karim = Customer::query()->where('display_ref', '900007')->first();
        $staff = fn (SeedRole $role) => Staff::query()->where('email', $role->value.'@dahab.test')->first();
        [$ceo, $coo, $ops, $finance] = [$staff(SeedRole::CEO), $staff(SeedRole::COO), $staff(SeedRole::OPERATIONS), $staff(SeedRole::FINANCE)];
        $terms = LegalDocument::current(LegalDocument::DEPOSIT_AGREEMENT);
        $proxyTerms = LegalDocument::current(LegalDocument::COLLECTION_PROXY_AUTHORISATION);

        if ($hoda === null || $karim === null || $ceo === null || $coo === null || $ops === null || $finance === null
            || $terms === null || $proxyTerms === null) {
            $this->command?->warn('LocalDisputeSeeder skipped: run LocalStaffSeeder, LocalCustomerSeeder and LocalBuyRequestSeeder first.');

            return;
        }

        $this->fund($post, $karim, $finance);

        $order = function (string $label) use ($hoda, $karim, $send, $accept, $terms): Order {
            $listing = $this->piece($hoda);
            $price = (string) app(ListingPricer::class)->quote($listing)->currentPrice;
            $request = $this->asCustomer($karim, fn () => $send->handle($karim, $listing->listing_id, $price, (int) $terms->legal_doc_id));
            $branchId = (int) DB::table('listing_branch_option')->where('listing_id', $listing->listing_id)->orderBy('branch_id')->value('branch_id');
            $order = $this->asCustomer($hoda, fn () => $accept->handle($hoda, $listing->listing_id, $request->buy_request_id, $branchId))['order'];
            $this->made[] = "{$label}: {$order->order_ref}";

            return $order;
        };
        $inspected = function (Order $o) use ($ceo, $receive, $inspect): Order {
            $this->asStaff($ceo, fn () => $receive->handle($ceo, $o->order_id));
            $this->asStaff($ceo, fn () => $inspect->handle($ceo, $o->order_id, ['measured_karat' => 21, 'measured_weight_g' => '10.000']));

            return $o;
        };
        $paid = fn (Order $o) => $this->asCustomer($karim, fn () => $pay->handle($karim, $o->order_id));
        $dispute = fn (Customer $by, Order $o, DisputeReason $reason, string $detail, array $tokens = []) => $this->asCustomer($by,
            fn () => $open->handle($by, $o->order_id, $reason, $detail, $tokens));
        $token = fn (Customer $c, UploadPurpose $purpose, int $seed) => $this->asCustomer($c,
            fn () => $uploads->handle($c, $purpose, $this->photo($seed))['token']);

        try {
            // 1. Frozen at awaiting balance, open, with a photo.
            $o = $inspected($order('frozen: Karim disputes before paying'));
            $dispute($karim, $o, DisputeReason::DISAGREE_INSPECTION,
                'I weighed it at home and it was 10.4 grams; the listing photos showed a deeper colour.', [$token($karim, UploadPurpose::DISPUTE_PHOTO, 1)]);

            // 2. Frozen when ready to collect by the seller, passed on to the COO.
            $o = $inspected($order('frozen: Hoda disputes after payment, passed on'));
            $paid($o);
            $d = $dispute($hoda, $o, DisputeReason::MONEY_WRONG, 'The amount in my wallet is less than the price I accepted.');
            $this->asStaff($ops, fn () => $passOn->handle($ops, $d->dispute_id, $coo->staff_id, self::MARKER.': please check the settlement figures.'));

            // 3. Resolved: resume, with compensation.
            $o = $inspected($order('dispute resolved: resumed with 250 EGP to Karim'));
            $d = $dispute($karim, $o, DisputeReason::OTHER_SIDE_UNRESPONSIVE, 'The seller has not answered my question about the hallmark.');
            $this->asStaff($ceo, fn () => $resolve->handle($ceo, $d->dispute_id, DisputeOutcome::RESUME,
                'We checked the hallmark with IGI: it is genuine 21K. You can pay the balance now.',
                ['party' => 'buyer', 'amount' => '250', 'reason' => CompensationReason::IGI_DELAY, 'note' => self::MARKER.': IGI took two days.']));

            // 4. Resolved against the sale, before payment.
            $o = $order('dispute resolved against the sale (deposit back, piece returned)');
            $this->asStaff($ceo, fn () => $receive->handle($ceo, $o->order_id));
            $d = $dispute($karim, $o, DisputeReason::NOT_AS_LISTED, 'The ring at the branch has a scratch the listing did not show.');
            $this->asStaff($finance, fn () => $resolve->handle($finance, $d->dispute_id, DisputeOutcome::AGAINST_SALE,
                'The piece did not match its listing. Your deposit is back in your wallet.'));

            // 5. A proxy named on a paid order.
            $o = $inspected($order('ready to collect, proxy named'));
            $paid($o);
            $idToken = $token($karim, UploadPurpose::PROXY_ID, 2);
            $this->asCustomer($karim, fn () => $nameProxy->handle($karim, $o->order_id, 'Ahmed Samir Hassan', '+201001234567',
                $idToken, (int) $proxyTerms->legal_doc_id));

            // 6. Requests for more time: waiting, accepted, refused.
            $o = $order('awaiting delivery, more time requested');
            $this->asCustomer($hoda, fn () => $askMoreTime->handle($hoda, $o->order_id, ExtensionRequestReason::BRANCH_CLOSED,
                'The branch was closed when I went yesterday evening.'));
            $o = $order('awaiting delivery, more time given (12 working hours)');
            $r = $this->asCustomer($hoda, fn () => $askMoreTime->handle($hoda, $o->order_id, ExtensionRequestReason::TRAVELLING,
                'I am in Alexandria until tomorrow night.'));
            $this->asStaff($ops, fn () => $answer->accept($ops, $r->request_id, 12, 'Extended — please bring it the day after tomorrow.'));
            $o = $order('awaiting delivery, more time refused');
            $r = $this->asCustomer($hoda, fn () => $askMoreTime->handle($hoda, $o->order_id, ExtensionRequestReason::OTHER,
                'Something came up at work this week.'));
            $this->asStaff($ops, fn () => $answer->refuse($ops, $r->request_id, 'The buyer has waited long enough; please bring it today.'));
        } catch (Throwable $e) {
            $this->command?->warn('LocalDisputeSeeder stopped: '.$e->getMessage());
        }

        $this->command?->info('LocalDisputeSeeder: '.count($this->made).' orders.');
        foreach ($this->made as $line) {
            $this->command?->line("  {$line}");
        }
    }

    /** Enough in Karim's wallet for every deposit and balance (a demo top-up, through the money service). */
    private function fund(PostLedgerEntryAction $post, Customer $karim, Staff $finance): void
    {
        DB::transaction(fn () => $post->handle(new LedgerEntry(LedgerEventKind::TOPUP, [
            new LedgerLine(Account::internal(AccountKind::BANK), '-400000'),
            new LedgerLine(Account::forCustomerKind($karim->customer_id, AccountKind::CUST_AVAILABLE), '400000'),
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
                ListingFactory::attachMedia($listing, ListingMediaKind::PHOTO, $this->photo(100 + count($this->made) * 2 + $i), 'image/png', $i);
            }
            ListingFactory::walk($listing, [ListingState::IN_REVIEW, ListingState::LIVE], null, $reviewer);

            return $listing->refresh();
        });
    }

    /** A small coloured square, as a real PNG on disk. */
    private function photo(int $seed): UploadedFile
    {
        $image = imagecreatetruecolor(320, 320);
        imagefill($image, 0, 0, imagecolorallocate($image, 120 + ($seed * 7) % 100, 140 + ($seed * 13) % 80, 90 + ($seed * 29) % 120));
        $path = (string) tempnam(sys_get_temp_dir(), 'seed');
        imagepng($image, $path);

        return new UploadedFile($path, "dispute{$seed}.png", 'image/png', null, true);
    }

    /** @template T @param Closure(): T $work @return T */
    private function asCustomer(Customer $customer, Closure $work): mixed
    {
        DatabaseActor::push('customer', customerId: $customer->customer_id);
        try {
            return $work();
        } finally {
            DatabaseActor::pop();
        }
    }

    /** @template T @param Closure(): T $work @return T */
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
