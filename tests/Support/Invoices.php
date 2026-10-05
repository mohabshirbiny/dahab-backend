<?php

namespace Tests\Support;

use App\Enums\SeedRole;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Models\TaxInvoice;
use App\Support\Invoices\Documents\TaxDocumentRenderer;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 016 tax-invoice tests. Invoices are only ever
 * made by a real balance payment through the HTTP boundary; credit notes by
 * the staff endpoint. The worked order is Part 3 §3.3: commission 600, VAT 84,
 * buyer total 55,631.25, seller proceeds 54,684.75.
 */
final class Invoices
{
    public const STAFF_URL = '/api/v1/dashboard/invoices';

    public const CREDIT_NOTES_URL = '/api/v1/dashboard/credit-notes';

    public const CUSTOMER_URL = '/api/v1/customer/me/invoices';

    /** Fill Dahab's six details so documents can be made. */
    public static function configureIssuer(): void
    {
        config(['dahab-invoices.issuer' => [
            'legal_name_en' => 'Dahab Test Company', 'legal_name_ar' => 'شركة دهب للاختبار',
            'address_en' => '1 Test Street, Cairo', 'address_ar' => '١ شارع الاختبار، القاهرة',
            'tax_registration_no' => '100-200-300', 'commercial_register_no' => '12345',
        ]]);
    }

    /** Swap mPDF for a fast stand-in that records what it was asked to render. */
    public static function fakeRenderer(): FakeTaxDocumentRenderer
    {
        $fake = new FakeTaxDocumentRenderer;
        app()->instance(TaxDocumentRenderer::class, $fake);

        return $fake;
    }

    /** The §3.3 ring (or `$listing`) accepted, inspected at 10.000 g and paid. */
    public static function paid(TestCase $test, ?Listing $listing = null, string $weight = '10.000'): Order
    {
        $order = Orders::inspected($test, Orders::accepted($test, $listing), $weight);
        Orders::pay($test, Orders::buyer($order), $order)->assertOk();

        return $order->refresh();
    }

    /** A paid diamond (asking 120,000; stone commission 6,000) — the spec 012 stone fixture. */
    public static function paidDiamond(TestCase $test): Order
    {
        $listing = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => Customer::factory()->verified()->create()->customer_id]);

        return self::paidPiece($test, $listing, ['measured_stone_grade' => 'VS1 G']);
    }

    /** A paid gold-with-diamond piece measured at 6.100 g 21K. */
    public static function paidGoldWithDiamond(TestCase $test): Order
    {
        $listing = Listing::factory()->goldWithDiamond()->withPhotos(3)->live()->create(['seller_id' => Customer::factory()->verified()->create()->customer_id]);

        return self::paidPiece($test, $listing, ['measured_karat' => 21, 'measured_weight_g' => '6.100']);
    }

    /** @param  array<string, mixed>  $result */
    private static function paidPiece(TestCase $test, Listing $listing, array $result): Order
    {
        $order = Orders::accepted($test, $listing, '200000');
        Orders::staff($test, SeedRole::OPERATIONS);
        Orders::receive($test, $order)->assertOk();
        Orders::staff($test, SeedRole::IGI_BRANCH);
        Orders::result($test, $order, $result)->assertCreated();
        Orders::pay($test, Orders::buyer($order), $order)->assertOk();

        return $order->refresh();
    }

    public static function of(Order $order, string $party): TaxInvoice
    {
        return TaxInvoice::query()->where('order_id', $order->order_id)->where('party_role', $party)->firstOrFail();
    }

    public static function credit(TestCase $test, TaxInvoice $invoice, string $amount, string $reason = 'Commission was overstated after a correction.', ?string $key = null): TestResponse
    {
        return $test->postJson(self::STAFF_URL."/{$invoice->invoice_id}/credit-notes", ['amount' => $amount, 'reason' => $reason], Finance::key($key));
    }

    /** @return list<CreditNote> */
    public static function notes(TaxInvoice $invoice): array
    {
        return CreditNote::query()->where('invoice_id', $invoice->invoice_id)->orderBy('issued_at')->get()->all();
    }
}
