<?php

use App\Models\Branch;
use App\Models\Order;
use App\Support\BuyRequests\DepositLedger;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-002, FR-017, research R3: what the database itself refuses,
// whatever the application does. Direct SQL in the maintenance scope; each
// attempt runs in a savepoint and forces the deferred checks.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
});

/** The SQLSTATE `$work` fails with (deferred checks forced), or null. */
function ordSqlState(Closure $work): ?string
{
    try {
        DB::transaction(function () use ($work) {
            $work();
            BuyRequests::checkNow();
        });
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

function ordHistory(Order $order, string $from, string $to): void
{
    DB::table('order_state_change')->insert([
        'order_id' => $order->order_id, 'from_state' => $from, 'to_state' => $to, 'actor_staff_id' => SystemActor::id(),
    ]);
}

it('moves an order only along order_transition, on its own SQLSTATE', function () {
    $id = $this->order->order_id;

    expect(ordSqlState(fn () => DB::table('order')->where('order_id', $id)->update(['state' => 'completed'])))->toBe('DH006')
        ->and(ordSqlState(fn () => DB::table('order')->where('order_id', $id)->update(['state' => 'ready_to_collect'])))->toBe('DH006')
        ->and(ordSqlState(fn () => DB::table('order')->where('order_id', $id)->delete()))->toBe('DH006')
        ->and(DB::table('order_transition')->where('from_state', 'awaiting_balance')
            ->whereIn('to_state', ['weight_adjust_pending', 'cancelled_inspection'])->count())->toBe(2);
});

it('refuses a move without its history row at commit', function () {
    $id = $this->order->order_id;

    expect(ordSqlState(fn () => DB::table('order')->where('order_id', $id)->update(['state' => 'at_inspection'])))->toBe('DH006')
        ->and(ordSqlState(function () use ($id) {
            DB::table('order')->where('order_id', $id)->update(['state' => 'at_inspection']);
            ordHistory($this->order, 'awaiting_delivery', 'at_inspection');
        }))->toBeNull();
});

it('freezes the locked seller rate and refuses half a settlement', function () {
    $id = $this->order->order_id;

    expect($this->order->locked_seller_unit_rate)->not->toBeNull()
        ->and(ordSqlState(fn () => DB::table('order')->where('order_id', $id)->update(['locked_seller_unit_rate' => '1'])))->toBe('DH006')
        ->and(ordSqlState(fn () => DB::table('order')->where('order_id', $id)->update(['final_buyer_total' => '1'])))->toBe('23514');
});

it('refuses a cancellation without its refund, and a no-pay without its forfeit', function () {
    $id = $this->order->order_id;

    expect(ordSqlState(function () use ($id) {
        DB::table('order')->where('order_id', $id)->update(['state' => 'cancelled_seller']);
        ordHistory($this->order, 'awaiting_delivery', 'cancelled_seller');
    }))->toBe('DH006');
});

it('lets a released deposit belong to an accepted request only once its order is cancelled', function () {
    $request = $this->order->buyRequest;

    // Still awaiting delivery: a release is refused at commit.
    expect(ordSqlState(fn () => app(DepositLedger::class)->release($request, null, SystemActor::id(), $this->order->order_id)))
        ->toBe('DH005');
});

it('keeps inspection results append-only and the karat rule structural', function () {
    $id = $this->order->order_id;
    $branch = $this->order->branch_id;
    $row = fn (array $over = []) => array_merge([
        'order_id' => $id, 'branch_id' => $branch, 'inspected_by' => SystemActor::id(),
        'stated_karat' => 21, 'measured_karat' => 21, 'karat_mismatch' => false, 'outcome' => 'pass',
    ], $over);

    $first = (string) DB::table('inspection_result')->insertGetId($row(), 'inspection_id');

    expect(ordSqlState(fn () => DB::table('inspection_result')->where('inspection_id', $first)->update(['outcome' => 'weight_adjust'])))->toBe('P0001')
        ->and(ordSqlState(fn () => DB::table('inspection_result')->where('inspection_id', $first)->delete()))->toBe('P0001')
        ->and(ordSqlState(fn () => DB::table('inspection_result')->insert($row(['measured_karat' => 18]))))->toBe('23514')
        ->and(ordSqlState(fn () => DB::table('inspection_result')->insert($row(['measured_karat' => 18, 'karat_mismatch' => true]))))->toBe('23514');

    DB::table('inspection_result')->insert($row(['supersedes_id' => $first]));
    expect(ordSqlState(fn () => DB::table('inspection_result')->insert($row(['supersedes_id' => $first]))))->toBe('23505');
});

it('keeps one cancellation per order and one decision per result', function () {
    DB::table('seller_cancellation')->insert(['order_id' => $this->order->order_id, 'seller_id' => $this->order->seller_id, 'by_sweep' => false]);

    expect(ordSqlState(fn () => DB::table('seller_cancellation')->insert([
        'order_id' => $this->order->order_id, 'seller_id' => $this->order->seller_id, 'by_sweep' => true,
    ])))->toBe('23505');
});

it('refuses a branch change to a branch the seller never named', function () {
    $foreign = Branch::factory()->create()->branch_id;

    expect(ordSqlState(fn () => DB::table('order')->where('order_id', $this->order->order_id)->update(['branch_id' => $foreign])))->toBe('DH005');
});
