<?php

use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Staff;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 019 6B (D3 as recommended, awaiting Finance): a market-maker purchase settles with no
// commission, no VAT on it, and the positive spread credited to the dealer; the entry balances.

beforeEach(function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
});

function paidOrder($test, bool $withCode): Order
{
    $order = Orders::accepted($test);

    if ($withCode) {
        $staff = Staff::factory()->create();
        $buyer = Orders::buyer($order);
        DatabaseActor::elevate('maintenance', function () use ($order, $buyer, $staff) {
            DB::table('customer')->where('customer_id', $buyer->customer_id)->update(['customer_type' => 'market_maker']);
            PromoCode::query()->create([
                'code' => 'DEALER-'.strtoupper(substr($order->order_id, 0, 6)), 'kind' => 'market_maker', 'tied_customer_id' => $buyer->customer_id,
                'commission_waived' => true, 'gives_spread' => true, 'created_by' => $staff->staff_id,
            ]);
            DB::table('buy_request')->where('buy_request_id', $order->buy_request_id)->update(['promo_code' => 'DEALER-'.strtoupper(substr($order->order_id, 0, 6))]);
        });
    }

    Orders::inspected($test, $order);
    Orders::pay($test, Orders::buyer($order), $order)->assertOk();

    return $order->refresh();
}

/** @return array<string, string> account kind => summed amount of this settlement entry */
function settlementByKind(Order $order): array
{
    $sum = [];
    foreach (Orders::lines($order, 'balance_payment') as $line) {
        $sum[$line->kind] = bcadd($sum[$line->kind] ?? '0', $line->amount, 4);
    }

    return $sum;
}

it('settles an ordinary purchase with commission, VAT and the spread to Dahab', function () {
    $order = paidOrder($this, false);
    $lines = settlementByKind($order);

    expect(bccomp($order->commission_amount, '0', 4))->toBe(1)
        ->and($lines)->toHaveKey('dahab_commission')
        ->and($lines)->toHaveKey('dahab_spread');
});

it('settles a market-maker purchase with no commission and the spread to the dealer', function () {
    $ordinary = paidOrder($this, false);
    $order = paidOrder($this, true);
    $lines = settlementByKind($order);

    expect($order->commission_amount)->toBe('0.0000')
        ->and($order->vat_amount)->toBe('0.0000')
        ->and($lines)->not->toHaveKey('dahab_commission')
        ->and($lines)->not->toHaveKey('vat_payable')
        ->and($lines)->not->toHaveKey('dahab_spread');

    // The spread returned to the dealer's available wallet, and the whole entry balances to zero.
    expect(bccomp($order->spread_amount, '0', 4))->toBe(1);
    $total = '0';
    foreach (Orders::lines($order, 'balance_payment') as $line) {
        $total = bcadd($total, $line->amount, 4);
    }
    expect($total)->toBe('0.0000')
        // With nothing taken as commission or VAT, the seller keeps the whole gross.
        ->and($order->seller_proceeds)->toBe($order->final_seller_gross);

    // The buyer's total and the sale itself are the same as an ordinary one at the same rates.
    expect($order->final_buyer_total)->toBe($ordinary->final_buyer_total)
        ->and($order->final_seller_gross)->toBe($ordinary->final_seller_gross);
});
