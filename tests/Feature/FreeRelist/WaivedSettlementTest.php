<?php

use App\Models\Listing;
use App\Models\TaxInvoice;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\FreeRelists;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 018 US3, FR-010–FR-021, research R8–R9: the link on the listing is the
// waiver — no commission, no VAT, no minimum — while the spread stays; only
// the buyer's invoice is issued.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

function ac018Relisted($test, ?Listing $origin = null, ?array $result = null): array
{
    $order = FreeRelists::ac018CollectedOrder($test, $origin, $result);
    FreeRelists::ac018Relist($test, Orders::buyer($order), $order)->assertCreated();

    return [$order, Listing::query()->where('relisted_from_order_id', $order->order_id)->sole()];
}

it('settles a relisted gold piece with commission, VAT and minimum at zero and the spread untouched', function () {
    // The ordinary sale of the same ring, for the figures to compare with.
    $plain = FreeRelists::ac018SettleRelisted($this, Orders::ring(null, ['making_charge_per_g' => '250.00']));

    [, $relisted] = ac018Relisted($this);
    $waived = FreeRelists::ac018SettleRelisted($this, $relisted);

    expect((string) $waived->commission_amount)->toBe('0.0000')
        ->and((string) $waived->vat_amount)->toBe('0.0000')
        ->and((string) $plain->commission_amount)->not->toBe('0.0000')
        // The buyer pays the same and the seller gets all of the gross; the spread is the ordinary one.
        ->and((string) $waived->final_buyer_total)->toBe((string) $plain->final_buyer_total)
        ->and((string) $waived->spread_amount)->toBe((string) $plain->spread_amount)
        ->and((string) $waived->seller_proceeds)->toBe((string) $waived->final_seller_gross);

    $kinds = collect(Orders::lines($waived, 'balance_payment'))->pluck('kind')->all();
    expect($kinds)->not->toContain('dahab_commission')->not->toContain('vat_payable');
});

it('does not apply the minimum commission to a relisted stone piece', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create();
    [, $relisted] = ac018Relisted($this, $diamond);

    $order = FreeRelists::ac018SettleRelisted($this, $relisted);

    expect((string) $order->commission_amount)->toBe('0.0000')
        ->and((string) $order->vat_amount)->toBe('0.0000')
        ->and((string) $order->seller_proceeds)->toBe((string) $order->final_seller_gross);
});

it('keeps the sale of the origin piece exactly as it was settled', function () {
    $origin = FreeRelists::ac018CollectedOrder($this);
    $before = DatabaseActor::elevate('maintenance', fn () => (array) DB::table('order')->where('order_id', $origin->order_id)->first());
    $invoices = TaxInvoice::query()->where('order_id', $origin->order_id)->count();

    FreeRelists::ac018Relist($this, Orders::buyer($origin), $origin)->assertCreated();
    $relisted = Listing::query()->where('relisted_from_order_id', $origin->order_id)->sole();
    FreeRelists::ac018SettleRelisted($this, $relisted);

    expect((string) $origin->refresh()->commission_amount)->not->toBe('0.0000')
        ->and(DatabaseActor::elevate('maintenance', fn () => (array) DB::table('order')->where('order_id', $origin->order_id)->first()))->toBe($before)
        ->and(TaxInvoice::query()->where('order_id', $origin->order_id)->count())->toBe($invoices)
        ->and($invoices)->toBe(2);
});

it('keeps the waiver when a sale of the relisted piece falls through and it sells again', function () {
    [, $relisted] = ac018Relisted($this);

    // The first buyer never pays: the sale is cancelled, the piece waits for its seller, who relists it.
    $fall = Orders::inspected($this, Orders::accepted($this, $relisted, '200000'), '9.950');
    $this->travelTo($fall->balance_due_deadline->addMinute());
    Orders::sweep();
    $seller = Orders::seller($fall);
    Listings::as($this, $seller)->postJson(Orders::CUSTOMER_URL."/{$fall->order_id}/relist", [], Listings::key())->assertOk();

    $again = Listing::query()->findOrFail($relisted->listing_id);
    expect($again->state->value)->toBe('live')->and($again->relisted_from_order_id)->not->toBeNull();

    $sold = FreeRelists::ac018SettleRelisted($this, $again);
    expect((string) $sold->commission_amount)->toBe('0.0000');
});

it('does not carry the waiver to an ordinary listing of the same customer', function () {
    $origin = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($origin);
    FreeRelists::ac018Relist($this, $buyer, $origin)->assertCreated();

    $fresh = Orders::ring($buyer);
    $order = FreeRelists::ac018SettleRelisted($this, $fresh);

    expect((string) $order->commission_amount)->not->toBe('0.0000');
});

it('issues only the buyer invoice, no seller invoice and no credit note', function () {
    [, $relisted] = ac018Relisted($this);
    $order = FreeRelists::ac018SettleRelisted($this, $relisted);

    $roles = TaxInvoice::query()->where('order_id', $order->order_id)->pluck('party_role')->map(fn ($r) => $r instanceof BackedEnum ? $r->value : $r)->all();
    expect($roles)->toBe(['buyer']);

    $view = Orders::show($this, Orders::seller($order), $order)->assertOk();
    expect($view->json('data.invoice'))->toBeNull()->and($view->json('data.no_fee'))->toBeTrue();
    expect(Orders::show($this, Orders::buyer($order), $order)->json('data.invoice.number'))->toBe($order->order_ref.'-B');
});

it('refuses, at the database, a seller invoice on a sale of no commission', function () {
    [, $relisted] = ac018Relisted($this);
    $order = FreeRelists::ac018SettleRelisted($this, $relisted);

    $state = null;
    try {
        DB::transaction(function () use ($order) {
            DB::table('tax_invoice')->insert([
                'invoice_id' => (string) Str::uuid(), 'invoice_no' => $order->order_ref.'-S', 'order_id' => $order->order_id,
                'party_role' => 'seller', 'customer_id' => $order->seller_id, 'net_amount' => '1.0000', 'vat_amount' => '0.1400',
                'gross_amount' => '1.1400', 'vat_rate' => '14', 'lines' => '[]',
            ]);
            BuyRequests::checkNow();
        });
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('DH012');
});
