<?php

use App\Enums\SeedRole;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 data-model §1–§10: the tables, guards (DH009, DH010), CHECKs,
// uniques, append-only rows, deferred checks and forced row-level security.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

/**
 * Run SQL as maintenance and return the SQLSTATE it raised, or null. The test
 * runs inside a transaction, so SET CONSTRAINTS ALL IMMEDIATE makes the
 * deferred checks fire now (the LedgerSchemaTest technique).
 */
function disputeSqlState(Closure $work): ?string
{
    try {
        DatabaseActor::elevate('maintenance', fn () => DB::transaction(function () use ($work) {
            $work();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }));
    } catch (QueryException|PDOException $e) {
        return $e->errorInfo[0] ?? (string) $e->getCode();
    } finally {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    return null;
}

it('creates the tables with forced row-level security and the lookups without', function () {
    foreach (['dispute', 'dispute_photo', 'dispute_change', 'compensation', 'order_extension_request'] as $table) {
        $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        expect($row->relrowsecurity)->toBeTrue()->and($row->relforcerowsecurity)->toBeTrue();
        expect(DB::selectOne('SELECT count(*) AS n FROM pg_policies WHERE tablename = ?', [$table])->n)->toBe(1);
    }
    expect(DB::table('dispute_transition')->count())->toBe(4)
        ->and(DB::table('extension_request_transition')->count())->toBe(3)
        ->and(DB::table('order_transition')->where('note', 'like', '%(spec 014)')->count())->toBe(3)
        ->and(DB::table('legal_document')->where('code', 'collection_proxy_authorisation')->count())->toBe(1);
});

it('guards the dispute: transitions, a final resolution, identity columns, no delete', function () {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    $staff = Orders::staff($this, SeedRole::OPERATIONS);
    $id = $dispute->dispute_id;

    expect(disputeSqlState(fn () => DB::table('dispute')->where('dispute_id', $id)->update(['detail' => 'Something else entirely now.'])))->toBe('DH009')
        ->and(disputeSqlState(fn () => DB::table('dispute')->where('dispute_id', $id)->delete()))->toBe('DH009')
        // Resolved without a reply: the CHECK.
        ->and(disputeSqlState(fn () => DB::table('dispute')->where('dispute_id', $id)->update(['state' => 'resolved', 'outcome' => 'resume'])))->toBe('23514')
        // Passed on without its history row: the deferred check.
        ->and(disputeSqlState(fn () => DB::table('dispute')->where('dispute_id', $id)->update(['state' => 'passed_on', 'assigned_to' => $staff->staff_id, 'passed_on_at' => now()])))->toBe('DH009');

    Disputes::resolve($this, $dispute)->assertOk();
    expect(disputeSqlState(fn () => DB::table('dispute')->where('dispute_id', $id)->update(['resolution_reply' => 'A different reply after the fact.'])))->toBe('DH009');
});

it('enforces one per party, one unresolved, the buyer-only reason and the paid-order rule', function () {
    $order = Disputes::readyToCollect($this);
    $dispute = Disputes::opened($this, $order);

    // A second unresolved dispute on the order (the seller's), straight in the table.
    $insert = fn (array $over = []) => DB::table('dispute')->insert($over + [
        'dispute_id' => (string) Str::uuid(), 'order_id' => $order->order_id, 'raised_by' => $order->seller_id,
        'raised_as' => 'seller', 'reason' => 'other', 'detail' => 'A second dispute on the same order.', 'frozen_from' => 'ready_to_collect',
    ]);
    expect(disputeSqlState(fn () => $insert()))->toBe('23505')
        ->and(disputeSqlState(fn () => $insert(['raised_by' => $order->buyer_id, 'raised_as' => 'buyer'])))->toBe('23505')
        ->and(disputeSqlState(fn () => $insert(['reason' => 'not_theirs_to_sell'])))->toBe('23514')
        // The raiser must be the party they claim to be.
        ->and(disputeSqlState(fn () => $insert(['raised_as' => 'buyer'])))->toBe('DH009')
        ->and(disputeSqlState(fn () => DB::table('dispute')->where('dispute_id', $dispute->dispute_id)->update([
            'state' => 'resolved', 'outcome' => 'against_sale', 'resolution_reply' => 'Cancelled after review.', 'resolved_at' => now(),
            'resolved_by' => SystemActor::id()])))->toBe('23514');
});

it('keeps photos, history and compensation append-only', function () {
    $order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($order);
    Disputes::open($this, $buyer, $order, ['photo_tokens' => [Disputes::photoToken($this, $buyer)]])->assertCreated();
    $dispute = Disputes::of($order);
    Orders::staff($this, SeedRole::CEO);
    Disputes::resolve($this, $dispute, ['compensation' => ['party' => 'buyer', 'amount' => '100', 'reason' => 'goodwill', 'note' => 'For the trouble caused.']])->assertOk();

    foreach (['dispute_photo', 'dispute_change', 'compensation'] as $table) {
        expect(disputeSqlState(fn () => DB::table($table)->where('dispute_id', $dispute->dispute_id)->delete()))->not->toBeNull();
    }
});

it('refuses a compensation row without its matching ledger entry', function () {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    $txn = DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction')->where('buy_request_id', $order->buy_request_id)->value('ledger_txn_id'));

    expect(disputeSqlState(fn () => DB::table('compensation')->insert([
        'compensation_id' => (string) Str::uuid(), 'dispute_id' => $dispute->dispute_id, 'order_id' => $order->order_id,
        'customer_id' => $order->buyer_id, 'party' => 'buyer', 'amount' => '50', 'reason' => 'goodwill',
        'note' => 'Not backed by any entry.', 'paid_by' => SystemActor::id(), 'ledger_txn_id' => $txn,
    ])))->toBe('DH009');
});

it('guards requests for more time: one waiting, final states, the seller only', function () {
    $order = Orders::accepted($this);
    Disputes::askMoreTime($this, $order)->assertCreated();
    $id = DatabaseActor::elevate('maintenance', fn () => DB::table('order_extension_request')->value('request_id'));

    $insert = fn (array $over = []) => DB::table('order_extension_request')->insert($over + [
        'request_id' => (string) Str::uuid(), 'order_id' => $order->order_id, 'seller_id' => $order->seller_id,
        'reason' => 'other', 'detail' => 'Another request for more time.', 'deadline_at_request' => now(),
    ]);
    expect(disputeSqlState(fn () => $insert()))->toBe('23505')
        ->and(disputeSqlState(fn () => $insert(['seller_id' => $order->buyer_id, 'order_id' => Orders::accepted($this)->order_id])))->toBe('DH010')
        ->and(disputeSqlState(fn () => DB::table('order_extension_request')->where('request_id', $id)->update(['state' => 'accepted'])))->toBe('23514')
        ->and(disputeSqlState(fn () => DB::table('order_extension_request')->where('request_id', $id)->delete()))->toBe('DH010');

    DatabaseActor::elevate('maintenance', fn () => DB::table('order_extension_request')->where('request_id', $id)->update(['state' => 'lapsed']));
    expect(disputeSqlState(fn () => DB::table('order_extension_request')->where('request_id', $id)->update(['state' => 'waiting'])))->toBe('DH010');
});

it('accepts a decision-window extension and refuses one with two causes', function () {
    $order = Disputes::deciding($this);
    $dispute = Disputes::opened($this, $order);
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute)->assertOk();

    expect(DatabaseActor::elevate('maintenance', fn () => DB::table('order_deadline_extension')->where('dispute_id', $dispute->dispute_id)->value('which')))->toBe('decision')
        ->and(disputeSqlState(fn () => DB::table('order_deadline_extension')->where('dispute_id', $dispute->dispute_id)
            ->update(['extension_request_id' => DB::table('order_extension_request')->value('request_id') ?? (string) Str::uuid()])))->not->toBeNull();
});
