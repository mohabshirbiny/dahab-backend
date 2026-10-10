<?php

use App\Actions\Content\PublishTextsAction;
use App\Actions\Content\SaveDraftAction;
use App\Enums\SeedRole;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\AppTextBundle;
use App\Models\AppTextVersion;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AppTexts;

uses(RefreshDatabase::class);

// Spec 019 FR-105, T019: two publishes at once give two consecutive bundles and no lost
// draft. Connection A holds its publish open; B must wait for A's advisory lock; a draft
// B saves meanwhile is not lost and goes out in the next bundle. Same technique as the spec
// 018 concurrency tests: the fixtures are committed and removed at the end.

function ac019OnConnection(string $name, Closure $work): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);
    DatabaseActor::reapply();

    try {
        return $work();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/** B's attempt must first block on A's lock (55P03 with a short lock_timeout). */
function ac019BlockedWhileAHolds(Closure $attempt): bool
{
    return ac019OnConnection('pgsql_b', function () use ($attempt) {
        DB::statement("SET lock_timeout = '300ms'");
        try {
            $attempt();

            return false;
        } catch (QueryException $e) {
            return ($e->errorInfo[0] ?? null) === '55P03';
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::statement('SET lock_timeout = 0');
        }
    });
}

function ac019AsStaff(string $staffId, Closure $work): mixed
{
    DatabaseActor::push('staff', staffId: $staffId);

    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

/** Commit the fixtures, run the race, then remove every committed row. */
function ac019WithCommitted(Closure $build, Closure $race): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack();
    DatabaseActor::reapply();

    try {
        $fixtures = DB::transaction(fn () => $build());
        Auth::forgetGuards();
        $race(...$fixtures);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reapply();
        DatabaseActor::elevate('maintenance', function () {
            $keep = ['migrations', 'karat', 'karat_price_adjustment', 'legal_document', 'listing_transition', 'buy_request_transition',
                'order_transition', 'withdrawal_transition', 'payout_account_transition', 'dispute_transition', 'extension_request_transition', 'piece_type', 'setting', 'staff', 'branch'];
            $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->pluck('tablename')
                ->reject(fn ($t) => in_array($t, $keep, true))->map(fn ($t) => '"'.$t.'"')->implode(', ');
            DB::statement("TRUNCATE {$tables} CASCADE");
            DB::table('staff')->where('staff_id', '!=', SystemActor::id())->delete();
            DB::table('branch')->delete();
        });
        (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
        Account::flushInternalCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::purge('pgsql_b');
        DB::beginTransaction();
    }
}

it('gives two publishes at once two consecutive bundles and loses no draft', function () {
    ac019WithCommitted(
        function () {
            $this->seed(DashboardRolesAndPermissionsSeeder::class);
            $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();
            AppTexts::key('order.test.one');
            AppTexts::key('order.test.two');
            // Draft of the first text, written as the staff member would.
            ac019AsStaff($staff->staff_id, fn () => app(SaveDraftAction::class)->handle($staff, 'order.test.one', 'One live', 'واحد لايف'));

            return [$staff];
        },
        function (Staff $staff) {
            $publish = fn () => ac019AsStaff($staff->staff_id, fn () => app(PublishTextsAction::class)->handle($staff));

            // A: publishing, not yet committed — it holds the publish lock and its drafts.
            DB::beginTransaction();
            $first = $publish();

            // B, meanwhile: saves another text's draft (not blocked), then tries to publish (blocked).
            ac019OnConnection('pgsql_b', fn () => ac019AsStaff($staff->staff_id, fn () => app(SaveDraftAction::class)->handle($staff, 'order.test.two', 'Two live', 'اتنين لايف')));
            $waited = ac019BlockedWhileAHolds(fn () => DB::transaction($publish));

            DB::commit();
            DatabaseActor::reapply();

            // B again, after A committed: the draft it saved is still there and goes out next.
            $second = ac019OnConnection('pgsql_b', $publish);

            expect($waited)->toBeTrue()
                ->and($first['bundle_version'])->toBe(1)
                ->and($first['keys'])->toBe(['order.test.one'])
                ->and($second['bundle_version'])->toBe(2)
                ->and($second['keys'])->toBe(['order.test.two']);

            $state = DatabaseActor::elevate('maintenance', fn () => [
                'bundles' => AppTextBundle::query()->orderBy('bundle_version')->pluck('bundle_version')->all(),
                'live' => AppTextVersion::query()->where('state', 'published')->orderBy('text_key')->pluck('bundle_version', 'text_key')->all(),
                'drafts' => AppTextVersion::query()->where('state', 'draft')->count(),
            ]);
            expect($state['bundles'])->toBe([1, 2])
                ->and($state['live'])->toBe(['order.test.one' => 1, 'order.test.two' => 2])
                ->and($state['drafts'])->toBe(0);
        },
    );
});

it('refuses the second of two publishes of the same drafts, never publishing them twice', function () {
    ac019WithCommitted(
        function () {
            $this->seed(DashboardRolesAndPermissionsSeeder::class);
            $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();
            AppTexts::key('order.test.one');
            ac019AsStaff($staff->staff_id, fn () => app(SaveDraftAction::class)->handle($staff, 'order.test.one', 'One live', 'واحد لايف'));

            return [$staff];
        },
        function (Staff $staff) {
            $publish = fn () => ac019AsStaff($staff->staff_id, fn () => app(PublishTextsAction::class)->handle($staff));

            DB::beginTransaction();
            $publish();
            $waited = ac019BlockedWhileAHolds(fn () => DB::transaction($publish));
            DB::commit();
            DatabaseActor::reapply();

            $after = ac019OnConnection('pgsql_b', function () use ($publish) {
                try {
                    $publish();

                    return 'ran';
                } catch (DomainApiException $e) {
                    return 'refused:'.$e->errorCode;
                }
            });

            expect($waited)->toBeTrue()
                ->and($after)->toBe('refused:content_nothing_to_publish')
                ->and(DatabaseActor::elevate('maintenance', fn () => AppTextBundle::query()->count()))->toBe(1);
        },
    );
});
