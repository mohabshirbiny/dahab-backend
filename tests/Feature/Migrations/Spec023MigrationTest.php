<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Ledger;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T015: the four migrations (data-model §1–§4): columns and CHECKs, the guards DH024–DH027,
// the minimum-commission CHECK on "order", listing_price_locked(), and the refusing down().
// Direct SQL; each attempt runs in a savepoint so a refusal does not abort the test's transaction.

function s23State(Closure $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return $e->errorInfo[0];
    }

    return null;
}

/** Fire the deferred constraint triggers now, then defer them again. */
function s23Fire(): ?string
{
    $state = s23State(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    return $state;
}

function s23Staff(): string
{
    return Staff::factory()->create()->staff_id;
}

function s23Code(array $over = []): array
{
    return $over + [
        'code' => 'T-'.strtoupper(substr(md5((string) microtime(true).rand()), 0, 8)), 'kind' => 'discount', 'audience' => 'anyone',
        'commission_mode' => 'percent', 'commission_percent' => '50', 'created_by' => s23Staff(),
    ];
}

function s23Migration(string $file): object
{
    return require base_path("database/migrations/{$file}.php");
}

it('adds the columns, tables and settings', function () {
    expect(Schema::hasColumns('promo_code', ['audience', 'commission_mode', 'commission_percent', 'use_limit', 'first_sale_only',
        'pays_advance', 'min_listed_days', 'requires_approval', 'starts_on', 'ends_on']))->toBeTrue()
        ->and(Schema::hasColumns('promo_code_use', ['listing_id', 'id_key', 'credit_id']))->toBeTrue()
        ->and(Schema::hasColumn('identity_document', 'id_number_hash'))->toBeTrue()
        ->and(Schema::hasColumns('order', ['commission_ordinary_amount', 'commission_given_up', 'commission_waiver_kind',
            'commission_waiver_credit_id', 'commission_floor_egp', 'commission_floor_applied', 'spread_given_up', 'settled_at']))->toBeTrue();

    foreach (['commission_credit', 'commission_credit_event', 'listing_promo', 'referral_code', 'referral',
        'first_sale_enablement', 'first_sale_advance', 'listing_view'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    // The three switches are off; the figures are the design's.
    expect(DB::table('setting')->whereIn('setting_key', ['payout.first_sale_enabled', 'promo.seller_codes_enabled', 'referral.enabled'])
        ->pluck('value_bool', 'setting_key')->map(fn ($v) => (bool) $v)->all())
        ->toBe(['payout.first_sale_enabled' => false, 'promo.seller_codes_enabled' => false, 'referral.enabled' => false])
        ->and(DB::table('setting')->where('setting_key', 'marketmaker.require_approval')->value('value_bool'))->toBeTrue()
        ->and((float) DB::table('setting')->where('setting_key', 'referral.friend_percent_off')->value('value_numeric'))->toBe(50.0)
        ->and((float) DB::table('setting')->where('setting_key', 'payout.first_sale_exposure_ceiling_egp')->value('value_numeric'))->toBe(1000000.0);
});

it('holds the promo_code term CHECKs', function () {
    $insert = fn (array $over) => fn () => DB::table('promo_code')->insert(s23Code($over));

    expect(s23State($insert(['commission_mode' => 'percent', 'commission_percent' => null])))->toBe('23514')
        ->and(s23State($insert(['commission_mode' => 'waive', 'commission_percent' => '10'])))->toBe('23514')
        ->and(s23State($insert(['commission_percent' => '100'])))->toBe('23514')
        ->and(s23State($insert(['commission_percent' => '0'])))->toBe('23514')
        // a market-maker code must be tied, waive and give the spread
        ->and(s23State($insert(['kind' => 'market_maker', 'audience' => 'anyone'])))->toBe('23514')
        ->and(s23State($insert(['kind' => 'discount', 'gives_spread' => true])))->toBe('23514')
        // a first-sale code pays the advance and is for a first sale only
        ->and(s23State($insert(['kind' => 'first_sale', 'pays_advance' => false, 'first_sale_only' => true])))->toBe('23514')
        ->and(s23State($insert(['kind' => 'discount', 'pays_advance' => true, 'first_sale_only' => true])))->toBe('23514')
        ->and(s23State($insert(['kind' => 'first_sale', 'pays_advance' => true, 'first_sale_only' => false])))->toBe('23514')
        ->and(s23State($insert(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-01'])))->toBe('23514')
        ->and(s23State($insert(['min_listed_days' => -1])))->toBe('23514')
        ->and(s23State($insert(['use_limit' => 'weekly'])))->toBe('23514')
        // a discount that does nothing is not a discount
        ->and(s23State($insert(['commission_mode' => 'none', 'commission_percent' => null])))->toBe('23514')
        // an account-tied code needs its account, an anyone code none
        ->and(s23State($insert(['audience' => 'account'])))->toBe('23514')
        ->and(s23State($insert([])))->toBeNull()
        ->and(s23State($insert(['kind' => 'first_sale', 'pays_advance' => true, 'first_sale_only' => true, 'commission_mode' => 'none', 'commission_percent' => null])))->toBeNull();
});

it('keeps a code written the spec 019 way as a waiving dealer code', function () {
    $dealer = Customer::factory()->verified()->create(['customer_type' => 'market_maker']);
    DB::table('promo_code')->insert([
        'code' => 'OLD-DEALER', 'kind' => 'market_maker', 'tied_customer_id' => $dealer->customer_id,
        'commission_waived' => true, 'gives_spread' => true, 'created_by' => s23Staff(),
    ]);

    $row = DB::table('promo_code')->where('code', 'OLD-DEALER')->first();
    expect($row->audience)->toBe('account')->and($row->commission_mode)->toBe('waive')
        ->and($row->use_limit)->toBe('unlimited')->and($row->requires_approval)->toBeTrue()
        ->and($row->min_listed_days)->toBeNull()->and($row->pays_advance)->toBeFalse();
});

it('freezes every term of a used code with DH020 but lets the cap and the switch move', function () {
    Promo::code(['code' => 'FREEZE-ME']);
    // Since 2026-10-26 an unused code may still be edited or deleted; once used, its terms are frozen.
    DB::table('promo_code_use')->insert(['code' => 'FREEZE-ME', 'customer_id' => Customer::factory()->verified()->create()->customer_id,
        'allowed' => false, 'block_reason' => 'inactive', 'id_key' => 'account:f']);
    $update = fn (array $set) => fn () => DB::table('promo_code')->where('code', 'FREEZE-ME')->update($set);

    expect(s23State($update(['commission_percent' => '60'])))->toBe('DH020')
        ->and(s23State($update(['commission_mode' => 'waive', 'commission_percent' => null, 'commission_waived' => true])))->toBe('DH020')
        ->and(s23State($update(['audience' => 'anyone', 'use_limit' => 'once'])))->toBe('DH020')
        ->and(s23State($update(['first_sale_only' => true])))->toBe('DH020')
        ->and(s23State($update(['starts_on' => '2026-12-01'])))->toBe('DH020')
        ->and(s23State($update(['min_listed_days' => 3])))->toBe('DH020')
        ->and(s23State($update(['requires_approval' => false])))->toBe('DH020')
        ->and(s23State(fn () => DB::table('promo_code')->where('code', 'FREEZE-ME')->delete()))->toBe('DH020')
        ->and(s23State($update(['monthly_cap_egp' => 250000])))->toBeNull()
        ->and(s23State($update(['is_active' => false, 'deactivated_by' => s23Staff(), 'deactivated_at' => now()])))->toBeNull();
});

it('moves a commission credit forward only, each move with its event (DH024)', function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
    $order = Orders::accepted($this);
    $seller = Customer::query()->findOrFail($order->seller_id);
    $listing = Listing::query()->findOrFail($order->listing_id);
    $credit = Promo::credit($seller, $listing, ['kind' => 'amount']);
    $id = $credit->credit_id;

    // A counter moved with no event: refused when the transaction ends.
    expect(s23State(function () use ($id) {
        DB::table('commission_credit')->where('credit_id', $id)->update(['amount_left_egp' => '100']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toBe('DH024');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    // With its event in the same transaction it passes.
    $spend = function () use ($id, $order, $seller) {
        DB::table('commission_credit')->where('credit_id', $id)->update(['amount_left_egp' => '50']);
        DB::table('commission_credit_event')->insert([
            'credit_id' => $id, 'kind' => 'spent', 'order_id' => $order->order_id,
            'ordinary_commission_egp' => '600', 'charged_commission_egp' => '450', 'reduction_egp' => '150',
            'amount_left_after_egp' => '50', 'actor_customer_id' => $seller->customer_id,
        ]);
    };
    expect(s23State($spend))->toBeNull()
        ->and(s23Fire())->toBeNull();

    $event = DB::table('commission_credit_event')->where('credit_id', $id)->first();
    // Events are append-only; a credit never goes back up, never changes identity, never deleted.
    expect(s23State(fn () => DB::table('commission_credit_event')->where('event_id', $event->event_id)->update(['reason' => 'x'])))->toBe('DH024')
        ->and(s23State(fn () => DB::table('commission_credit_event')->where('event_id', $event->event_id)->delete()))->toBe('DH024')
        ->and(s23State(fn () => DB::table('commission_credit')->where('credit_id', $id)->update(['amount_left_egp' => '150'])))->toBe('DH024')
        ->and(s23State(fn () => DB::table('commission_credit')->where('credit_id', $id)->update(['amount_egp' => '999', 'amount_left_egp' => '50'])))->toBe('DH024')
        ->and(s23State(fn () => DB::table('commission_credit')->where('credit_id', $id)->delete()))->toBe('DH024');
});

it('refuses a credit event without an actor or with a wrong reduction', function () {
    $seller = Customer::factory()->verified()->create();
    $listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]);
    $credit = Promo::credit($seller, $listing);

    $event = fn (array $over) => fn () => DB::table('commission_credit_event')->insert($over + [
        'credit_id' => $credit->credit_id, 'kind' => 'granted', 'actor_customer_id' => $seller->customer_id,
    ]);

    expect(s23State($event(['actor_customer_id' => null])))->toBe('23514')
        ->and(s23State($event(['kind' => 'spent'])))->toBe('23514')   // a spent event needs its order and figures
        ->and(s23State($event([])))->toBeNull();
});

it('holds the credit CHECKs', function () {
    $seller = Customer::factory()->verified()->create();
    $listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]);
    $code = Promo::code()->code;
    $insert = fn (array $over) => fn () => DB::table('commission_credit')->insert($over + [
        'customer_id' => $seller->customer_id, 'source' => 'promo_code', 'kind' => 'percent', 'percent_off' => '50',
        'uses_total' => 1, 'uses_left' => 1, 'listing_id' => $listing->listing_id, 'promo_code' => $code,
    ]);

    expect(s23State($insert(['percent_off' => null])))->toBe('23514')
        ->and(s23State($insert(['percent_off' => '100'])))->toBe('23514')
        ->and(s23State($insert(['uses_left' => 2])))->toBe('23514')
        ->and(s23State($insert(['uses_total' => null, 'uses_left' => null])))->toBe('23514')
        ->and(s23State($insert(['listing_id' => null])))->toBe('23514')        // a code credit is bound to a listing
        ->and(s23State($insert(['promo_code' => null])))->toBe('23514')
        ->and(s23State($insert(['status' => 'exhausted'])))->toBe('23514')     // exhausted with something left
        ->and(s23State($insert(['uses_left' => 0])))->toBe('23514')            // active with nothing left
        ->and(s23State($insert([])))->toBeNull();
});

it('keeps the listing code out of a listing a buyer is in line for (DH027)', function () {
    $seller = Customer::factory()->verified()->create();
    $listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]);
    $credit = Promo::credit($seller, $listing);
    $row = fn () => DB::table('listing_promo')->insert([
        'listing_id' => $listing->listing_id, 'promo_code' => $credit->promo_code, 'credit_id' => $credit->credit_id,
    ]);

    expect(s23State($row))->toBeNull();

    // A listing that is reserved is frozen: neither a change nor a removal.
    DB::table('listing')->where('listing_id', $listing->listing_id)->update(['state' => 'reserved']);
    expect(s23State(fn () => DB::table('listing_promo')->where('listing_id', $listing->listing_id)->delete()))->toBe('DH027')
        ->and(s23State(fn () => DB::table('listing_promo')->where('listing_id', $listing->listing_id)->update(['applied_at' => now()])))->toBe('DH027');

    // A listing that is not in a state for a code refuses one.
    $withdrawn = Listing::factory()->withPhotos(2)->withdrawn()->create(['seller_id' => $seller->customer_id]);
    $other = Promo::credit($seller, $withdrawn);
    expect(s23State(fn () => DB::table('listing_promo')->insert([
        'listing_id' => $withdrawn->listing_id, 'promo_code' => $other->promo_code, 'credit_id' => $other->credit_id,
    ])))->toBe('DH027');
});

it('keeps the view counter append-only and prunes only past the retention (DH027)', function () {
    $listing = Listing::factory()->withPhotos(2)->live()->create();
    DB::table('listing_view')->insert([
        ['listing_id' => $listing->listing_id, 'day' => now()->toDateString(), 'viewer_hash' => 'recent'],
        ['listing_id' => $listing->listing_id, 'day' => now()->subDays(200)->toDateString(), 'viewer_hash' => 'old'],
    ]);

    expect(s23State(fn () => DB::table('listing_view')->where('viewer_hash', 'recent')->update(['viewer_hash' => 'x'])))->toBe('DH027')
        ->and(s23State(fn () => DB::table('listing_view')->where('viewer_hash', 'recent')->delete()))->toBe('DH027')
        ->and(s23State(fn () => DB::table('listing_view')->where('viewer_hash', 'old')->delete()))->toBeNull()
        ->and(DB::table('listing_view')->count())->toBe(1);
});

it('guards the first-sale advance and its enablement (DH025)', function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
    $order = Orders::accepted($this);
    $txn = Ledger::topUp(Customer::factory()->verified()->create(), '100');
    $staff = s23Staff();

    $enablementId = (string) Str::uuid();
    DB::table('first_sale_enablement')->insert([
        'enablement_id' => $enablementId, 'seller_id' => $order->seller_id, 'enabled_by' => $staff, 'reason' => 'Verified at the branch in person',
    ]);
    // One open enablement per seller.
    expect(s23State(fn () => DB::table('first_sale_enablement')->insert([
        'seller_id' => $order->seller_id, 'enabled_by' => $staff, 'reason' => 'A second one for the same seller',
    ])))->toBe('23505');

    $advanceId = (string) Str::uuid();
    DB::table('first_sale_advance')->insert([
        'advance_id' => $advanceId, 'order_id' => $order->order_id, 'seller_id' => $order->seller_id, 'listing_id' => $order->listing_id,
        'amount_egp' => '1000', 'cap_applied_egp' => '100000', 'provisional_proceeds_egp' => '5000',
        'basis' => 'staff_enablement', 'enablement_id' => $enablementId, 'advance_txn_id' => $txn->ledger_txn_id,
    ]);

    $set = fn (array $values) => fn () => DB::table('first_sale_advance')->where('advance_id', $advanceId)->update($values);
    expect(s23State($set(['amount_egp' => '2000'])))->toBe('DH025')
        ->and(s23State(fn () => DB::table('first_sale_advance')->where('advance_id', $advanceId)->delete()))->toBe('DH025')
        // recovered needs its three figures and they must sum to the advance
        ->and(s23State($set(['status' => 'recovered'])))->toBe('23514')
        ->and(s23State($set(['status' => 'recovered', 'recovered_txn_id' => $txn->ledger_txn_id, 'recovered_egp' => '600', 'shortfall_absorbed_egp' => '100'])))->toBe('23514')
        ->and(s23State($set(['status' => 'written_off', 'written_off_at' => now(), 'written_off_reason' => 'buyer_did_not_pay', 'written_off_amount_egp' => '999'])))->toBe('23514')
        ->and(s23State($set(['status' => 'written_off', 'written_off_at' => now(), 'written_off_reason' => 'buyer_did_not_pay', 'written_off_amount_egp' => '1000'])))->toBeNull()
        // once ended, never again
        ->and(s23State($set(['status' => 'recovered', 'recovered_txn_id' => $txn->ledger_txn_id, 'recovered_egp' => '1000', 'shortfall_absorbed_egp' => '0'])))->toBe('DH025');

    // A seller is advanced once, ever: another order of the same seller cannot carry a second advance.
    $second = Orders::accepted($this, Orders::ring(Customer::query()->findOrFail($order->seller_id)));
    expect(s23State(fn () => DB::table('first_sale_advance')->insert([
        'order_id' => $second->order_id, 'seller_id' => $second->seller_id, 'listing_id' => $second->listing_id,
        'amount_egp' => '10', 'cap_applied_egp' => '100000', 'provisional_proceeds_egp' => '50',
        'basis' => 'staff_enablement', 'enablement_id' => $enablementId, 'advance_txn_id' => $txn->ledger_txn_id,
    ])))->toBe('23505');

    // An enablement is used once and never deleted.
    expect(s23State(fn () => DB::table('first_sale_enablement')->where('enablement_id', $enablementId)->update(['used_advance_id' => $advanceId])))->toBeNull()
        ->and(s23State(fn () => DB::table('first_sale_enablement')->where('enablement_id', $enablementId)->update(['reason' => 'rewritten history here'])))->toBe('DH025')
        ->and(s23State(fn () => DB::table('first_sale_enablement')->where('enablement_id', $enablementId)->delete()))->toBe('DH025');
});

it('locks the price of a piece that carried an advance, in every state', function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
    $order = Orders::accepted($this);
    $locked = fn (string $id) => (bool) DB::selectOne('SELECT listing_price_locked(?) AS l', [$id])->l;

    expect($locked($order->listing_id))->toBeFalse();

    $txn = Ledger::topUp(Customer::factory()->verified()->create(), '100');
    DB::table('first_sale_enablement')->insert([
        'enablement_id' => $e = (string) Str::uuid(), 'seller_id' => $order->seller_id, 'enabled_by' => s23Staff(),
        'reason' => 'Verified at the branch in person',
    ]);
    DB::table('first_sale_advance')->insert([
        'order_id' => $order->order_id, 'seller_id' => $order->seller_id, 'listing_id' => $order->listing_id,
        'amount_egp' => '1000', 'cap_applied_egp' => '100000', 'provisional_proceeds_egp' => '5000',
        'basis' => 'staff_enablement', 'enablement_id' => $e, 'advance_txn_id' => $txn->ledger_txn_id, 'status' => 'outstanding',
    ]);

    expect($locked($order->listing_id))->toBeTrue();
    foreach (['written_off'] as $status) {
        DB::table('first_sale_advance')->where('order_id', $order->order_id)->update([
            'status' => $status, 'written_off_at' => now(), 'written_off_reason' => 'buyer_did_not_pay', 'written_off_amount_egp' => '1000',
        ]);
        expect($locked($order->listing_id))->toBeTrue();
    }
});

it('refuses to roll back while the data tied to the ledger or audit exists, and runs clean without', function () {
    $m1 = s23Migration('2026_10_16_000010_promo_code_terms');
    $m2 = s23Migration('2026_10_16_000020_commission_credit');
    $m3 = s23Migration('2026_10_16_000030_first_sale_advance');

    // A new-term code blocks M1.
    Promo::code(['code' => 'BLOCKS-M1']);
    expect(fn () => $m1->down())->toThrow(RuntimeException::class);
    DB::table('promo_code')->where('code', 'BLOCKS-M1')->update(['is_active' => false, 'deactivated_by' => s23Staff(), 'deactivated_at' => now()]);

    // A credit blocks M2.
    $seller = Customer::factory()->verified()->create();
    $listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]);
    Promo::credit($seller, $listing);
    expect(fn () => $m2->down())->toThrow(RuntimeException::class);

    // An enablement blocks M3.
    DB::table('first_sale_enablement')->insert([
        'seller_id' => $seller->customer_id, 'enabled_by' => s23Staff(), 'reason' => 'Verified at the branch in person',
    ]);
    expect(fn () => $m3->down())->toThrow(RuntimeException::class);
});
