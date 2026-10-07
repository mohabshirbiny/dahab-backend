<?php

use App\Enums\AccountEvent;
use App\Enums\ReportReason;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Notifications\AccountNotification;
use App\Notifications\Channels\InboxChannel;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Account;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 017 T006: the schema of data-model.md — checks, guards, forced RLS —
// and what the Customer file shows of it.

function acc017Sqlstate(Closure $work, string $scope = 'system'): ?string
{
    return DatabaseActor::elevate($scope, function () use ($work) {
        DB::beginTransaction();
        try {
            $work();

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? null;
        } finally {
            DB::rollBack();
        }
    });
}

it('forces row-level security on the three new tables', function () {
    $rows = collect(DB::select("SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class
        WHERE relname IN ('customer_notification','saved_listing','listing_report')"))->keyBy('relname');

    foreach (['customer_notification', 'saved_listing', 'listing_report'] as $t) {
        expect($rows[$t]->relrowsecurity)->toBeTrue()->and($rows[$t]->relforcerowsecurity)->toBeTrue();
    }
});

it('keeps the closed shape of a customer', function () {
    $c = Customer::factory()->create();
    $set = fn (array $v) => acc017Sqlstate(fn () => DB::table('customer')->where('customer_id', $c->customer_id)->update($v));

    expect($set(['status' => 'closed']))->toBe('23514')
        ->and($set(['closed_at' => now(), 'closed_reason' => 'finished']))->toBe('23514')
        ->and($set(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'bored']))->toBe('23514')
        ->and($set(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'finished', 'closed_note' => 'x']))->toBe('23514')
        ->and($set(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'other', 'closed_note' => str_repeat('x', 501)]))->toBe('23514')
        ->and($set(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'other', 'closed_note' => 'ok']))->toBeNull();
});

it('names what opened a withdrawal pause, and an account only for a payout-account change', function () {
    $c = Customer::factory()->create();
    $insert = fn (array $v) => acc017Sqlstate(fn () => DB::table('withdrawal_pause')->insert([
        'customer_id' => $c->customer_id, 'opened_at' => now(), 'pause_until' => now()->addDay(), ...$v,
    ]));

    expect($insert(['trigger_kind' => 'phone_change']))->toBeNull()
        ->and($insert(['trigger_kind' => 'lost_phone']))->toBe('23514')
        ->and(DB::selectOne("SELECT column_default FROM information_schema.columns WHERE table_name = 'withdrawal_pause' AND column_name = 'trigger_kind'")->column_default)
        ->toContain('payout_account');
});

it('checks an inbox item: type shape, link kind and id, text lengths, one per dedupe key', function () {
    $c = Customer::factory()->create();
    $row = fn (array $v) => ['customer_id' => $c->customer_id, 'type' => 'a.b', 'link_kind' => 'none', 'title_en' => 't', 'title_ar' => 't',
        'body_en' => 'b', 'body_ar' => 'b', 'dedupe_key' => (string) Str::uuid(), ...$v];
    $insert = fn (array $v) => acc017Sqlstate(fn () => DB::table('customer_notification')->insert($row($v)));

    expect($insert(['type' => 'Bad Type']))->toBe('23514')
        ->and($insert(['link_kind' => 'order']))->toBe('23514')
        ->and($insert(['link_kind' => 'wallet', 'link_id' => 'x']))->toBe('23514')
        ->and($insert(['link_kind' => 'teleport']))->toBe('23514')
        ->and($insert(['title_en' => str_repeat('x', 201)]))->toBe('23514')
        ->and($insert(['body_ar' => str_repeat('x', 1001)]))->toBe('23514')
        ->and($insert([]))->toBeNull()
        ->and(acc017Sqlstate(fn () => DB::table('customer_notification')->insert([$row(['dedupe_key' => 'same']), $row(['dedupe_key' => 'same'])])))->toBe('23505');
});

it('lets a customer write no inbox item and read only their own', function () {
    $c = Customer::factory()->create();
    $insert = fn () => DB::table('customer_notification')->insert(['customer_id' => $c->customer_id, 'type' => 'a.b', 'link_kind' => 'none',
        'title_en' => 't', 'title_ar' => 't', 'body_en' => 'b', 'body_ar' => 'b', 'dedupe_key' => 'k']);

    DatabaseActor::push('customer', customerId: $c->customer_id);
    try {
        DB::beginTransaction();
        try {
            $insert();
            $refused = null;
        } catch (QueryException $e) {
            $refused = $e->errorInfo[0] ?? null;
        } finally {
            DB::rollBack();
        }
    } finally {
        DatabaseActor::pop();
    }

    expect($refused)->toBe('42501');
});

it('moves a report out of open once, keeps its facts and its staff fields shaped (DH015)', function () {
    Orders::workedPrices();
    $piece = Orders::ring();
    $reporter = Customer::factory()->verified()->create();
    $id = DatabaseActor::elevate('system', fn () => DB::table('listing_report')->insertGetId([
        'listing_id' => $piece->listing_id, 'reporter_id' => $reporter->customer_id, 'reason' => ReportReason::OTHER->value,
    ], 'report_id'));
    $staff = SystemActor::id();
    $set = fn (array $v) => acc017Sqlstate(fn () => DB::table('listing_report')->where('report_id', $id)->update($v));

    expect($set(['reason' => 'photos_not_genuine']))->toBe('DH015')
        ->and($set(['state' => 'dismissed', 'handled_at' => now(), 'handled_by' => $staff]))->toBe('23514')
        ->and($set(['state' => 'actioned', 'handled_at' => now()]))->toBe('23514')
        ->and($set(['state' => 'listing_gone']))->toBe('23514')
        ->and($set(['state' => 'dismissed', 'handled_at' => now(), 'handled_by' => $staff, 'staff_note' => 'fine']))->toBeNull()
        ->and(acc017Sqlstate(fn () => DB::table('listing_report')->insert(['listing_id' => $piece->listing_id, 'reporter_id' => $reporter->customer_id, 'reason' => 'nope'])))->toBe('23514');
});

it('refuses any new row for a closed customer (DH013)', function () {
    $c = Customer::factory()->verified()->create();
    DatabaseActor::elevate('system', fn () => DB::table('customer')->where('customer_id', $c->customer_id)
        ->update(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'finished']));
    Orders::workedPrices();
    $piece = Orders::ring();

    expect(acc017Sqlstate(fn () => DB::table('saved_listing')->insert(['customer_id' => $c->customer_id, 'listing_id' => $piece->listing_id, 'summary' => '{}'])))->toBe('DH013')
        ->and(acc017Sqlstate(fn () => DB::table('listing_report')->insert(['listing_id' => $piece->listing_id, 'reporter_id' => $c->customer_id, 'reason' => 'other'])))->toBe('DH013')
        ->and(acc017Sqlstate(fn () => Listing::factory()->create(['seller_id' => $c->customer_id])))->toBe('DH013');
});

it('shows the Customer file the pause trigger and the closure', function () {
    $c = Account::customer();
    DatabaseActor::elevate('system', function () use ($c) {
        DB::table('withdrawal_pause')->insert(['customer_id' => $c->customer_id, 'opened_at' => now(), 'pause_until' => now()->addDay(), 'trigger_kind' => 'email_change']);
    });
    Listings::actAsStaff($this, SeedRole::VERIFICATION);

    $this->getJson("/api/v1/dashboard/customers/{$c->customer_id}")->assertOk()
        ->assertJsonPath('data.withdrawal_pause.trigger_kind', 'email_change')
        ->assertJsonPath('data.closure', null);

    app(InboxChannel::class)->send($c, tap(new AccountNotification(AccountEvent::PASSWORD_CHANGED, 'en'), fn ($n) => $n->id = 'acc017-file'));
    $this->getJson("/api/v1/dashboard/customers/{$c->customer_id}/notifications")->assertOk()->assertJsonCount(1, 'data');

    expect(ListingReport::query()->count())->toBe(0);
});
