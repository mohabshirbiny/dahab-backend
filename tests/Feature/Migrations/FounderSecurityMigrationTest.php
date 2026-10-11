<?php

use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Models\StaffNotice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// Spec 021 T006 (data-model §1): the founder_device_approval and account_freeze columns, CHECKs,
// the DH021 / DH022 guards, staff_notice, and the refusing down(). Direct SQL; each attempt runs
// in a savepoint so a refusal does not abort the test's transaction.

/** The SQLSTATE a write is refused with, or null when it succeeds. */
function fsSqlState(Closure $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return $e->errorInfo[0];
    }

    return null;
}

function fsMigration(): object
{
    return require base_path('database/migrations/2026_10_14_000010_founder_security.php');
}

function fsFounder(SeedRole $role = SeedRole::CEO): Staff
{
    return Staff::factory()->role($role)->founder()->create();
}

it('adds the columns, the notices table and the permission', function () {
    // Run the migration again here: the concurrency tests truncate every table (permissions too)
    // earlier in a full run, so the row the first run inserted may be gone. DDL is transactional.
    fsMigration()->down();
    fsMigration()->up();

    expect(Schema::hasColumns('founder_device_approval', [
        'expires_at', 'ref_hash', 'platform', 'user_agent', 'ip_address', 'refused_by', 'refused_at', 'decision_note', 'consumed_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('account_freeze', ['reason', 'unfreeze_confirm_1_at', 'unfreeze_confirm_1_kind', 'unfreeze_confirm_2_at']))->toBeTrue()
        ->and(Schema::hasColumns('staff_notice', ['notice_id', 'staff_id', 'kind', 'severity', 'title', 'body', 'params', 'link', 'audit_id', 'created_at', 'read_at']))->toBeTrue()
        ->and(DB::table('permissions')->where('name', 'founder.unfreeze_second')->exists())->toBeTrue();
});

it('holds the device approval CHECKs', function () {
    $row = FounderDeviceApproval::factory()->create();

    expect(fsSqlState(fn () => DB::table('founder_device_approval')->where('approval_id', $row->approval_id)
        ->update(['approved_by' => $row->staff_id, 'approved_at' => now()])))->toBe('23514')
        ->and(fsSqlState(fn () => DB::table('founder_device_approval')->where('approval_id', $row->approval_id)
            ->update(['refused_by' => $row->staff_id, 'refused_at' => now()])))->toBe('23514')
        ->and(fsSqlState(fn () => DB::table('founder_device_approval')->where('approval_id', $row->approval_id)
            ->update(['approved_by' => fsFounder()->staff_id])))->toBe('23514');
});

it('guards device approvals with DH021', function () {
    $other = fsFounder();
    $pending = FounderDeviceApproval::factory()->create();
    $approved = FounderDeviceApproval::factory()->approved($other)->create();
    $expired = FounderDeviceApproval::factory()->expired()->create();
    $consumed = FounderDeviceApproval::factory()->consumed($other)->create();

    $update = fn (FounderDeviceApproval $r, array $set) => fn () => DB::table('founder_device_approval')->where('approval_id', $r->approval_id)->update($set);

    expect(fsSqlState(fn () => DB::table('founder_device_approval')->where('approval_id', $pending->approval_id)->delete()))->toBe('DH021')
        // re-decide
        ->and(fsSqlState($update($approved, ['approved_by' => null, 'approved_at' => null, 'refused_by' => $other->staff_id, 'refused_at' => now()])))->toBe('DH021')
        ->and(fsSqlState($update($approved, ['decision_note' => 'changed later'])))->toBe('DH021')
        // decide after expiry
        ->and(fsSqlState($update($expired, ['approved_by' => $other->staff_id, 'approved_at' => now()])))->toBe('DH021')
        // extend expires_at
        ->and(fsSqlState($update($pending, ['expires_at' => now()->addHour()])))->toBe('DH021')
        // consume an unapproved row
        ->and(fsSqlState($update($pending, ['consumed_at' => now()])))->toBe('DH021')
        // consume twice
        ->and(fsSqlState($update($consumed, ['consumed_at' => now()->addMinute()])))->toBe('DH021')
        // the request itself
        ->and(fsSqlState($update($pending, ['device_fingerprint' => 'other'])))->toBe('DH021')
        // what is allowed: end early, decide in time, collect once
        ->and(fsSqlState($update($pending, ['expires_at' => now()])))->toBeNull()
        ->and(fsSqlState($update($approved, ['consumed_at' => now()])))->toBeNull();

    $fresh = FounderDeviceApproval::factory()->create();
    expect(fsSqlState($update($fresh, ['refused_by' => $other->staff_id, 'refused_at' => now(), 'decision_note' => 'not me'])))->toBeNull();
});

it('holds the freeze CHECKs', function () {
    $freeze = AccountFreeze::factory()->create();
    $ceo = Staff::query()->find($freeze->frozen_by);
    $stand_in = Staff::factory()->create();

    $update = fn (array $set) => fn () => DB::table('account_freeze')->where('freeze_id', $freeze->freeze_id)->update($set);

    // slot 2 before slot 1
    expect(fsSqlState($update(['unfreeze_confirm_2' => $ceo->staff_id, 'unfreeze_confirm_2_at' => now(), 'unfrozen_at' => now()])))->toBe('23514')
        // a frozen founder kind given by someone else
        ->and(fsSqlState($update(['unfreeze_confirm_1' => $stand_in->staff_id, 'unfreeze_confirm_1_at' => now(), 'unfreeze_confirm_1_kind' => 'frozen_founder'])))->toBe('23514')
        // the frozen founder as second approver
        ->and(fsSqlState($update(['unfreeze_confirm_1' => $freeze->frozen_staff_id, 'unfreeze_confirm_1_at' => now(), 'unfreeze_confirm_1_kind' => 'second_approver'])))->toBe('23514')
        // half a confirmation
        ->and(fsSqlState($update(['unfreeze_confirm_1' => $freeze->frozen_staff_id])))->toBe('23514')
        // an empty reason, self-freeze
        ->and(fsSqlState(fn () => DB::table('account_freeze')->insert(['frozen_staff_id' => $stand_in->staff_id, 'frozen_by' => $ceo->staff_id, 'reason' => '  '])))->toBe('23514')
        ->and(fsSqlState(fn () => DB::table('account_freeze')->insert(['frozen_staff_id' => $ceo->staff_id, 'frozen_by' => $ceo->staff_id, 'reason' => 'x'])))->toBe('23514');

    // the same person twice
    $first = $update(['unfreeze_confirm_1' => $ceo->staff_id, 'unfreeze_confirm_1_at' => now(), 'unfreeze_confirm_1_kind' => 'second_approver']);
    expect(fsSqlState($first))->toBeNull()
        ->and(fsSqlState($update(['unfreeze_confirm_2' => $ceo->staff_id, 'unfreeze_confirm_2_at' => now(), 'unfrozen_at' => now()])))->toBe('23514');
});

it('guards freezes with DH022', function () {
    $freeze = AccountFreeze::factory()->firstConfirmed()->create();
    $plain = Staff::factory()->create();
    $founder = fsFounder();

    $update = fn (AccountFreeze $f, array $set) => fn () => DB::table('account_freeze')->where('freeze_id', $f->freeze_id)->update($set);

    expect(fsSqlState(fn () => DB::table('account_freeze')->where('freeze_id', $freeze->freeze_id)->delete()))->toBe('DH022')
        ->and(fsSqlState($update($freeze, ['reason' => 'another reason'])))->toBe('DH022')
        ->and(fsSqlState($update($freeze, ['unfreeze_confirm_1' => $plain->staff_id, 'unfreeze_confirm_1_kind' => 'second_approver'])))->toBe('DH022')
        // a non-founder in slot 2
        ->and(fsSqlState($update($freeze, ['unfreeze_confirm_2' => $plain->staff_id, 'unfreeze_confirm_2_at' => now(), 'unfrozen_at' => now()])))->toBe('DH022')
        // a non-founder freezer
        ->and(fsSqlState(fn () => DB::table('account_freeze')->insert(['frozen_staff_id' => $founder->staff_id, 'frozen_by' => $plain->staff_id, 'reason' => 'x'])))->toBe('DH022');

    $unfrozen = AccountFreeze::factory()->unfrozen()->create();
    expect(fsSqlState($update($unfrozen, ['unfrozen_at' => now()->addMinute()])))->toBe('DH022');

    // the database does not require the target to be a founder (StaffFactory::frozen())
    expect(fsSqlState(fn () => DB::table('account_freeze')->insert(['frozen_staff_id' => $plain->staff_id, 'frozen_by' => $founder->staff_id, 'reason' => 'x'])))->toBeNull();
});

it('allows one open freeze per staff member', function () {
    $freeze = AccountFreeze::factory()->create();
    $other = fsFounder(SeedRole::COO);

    expect(fsSqlState(fn () => DB::table('account_freeze')->insert([
        'frozen_staff_id' => $freeze->frozen_staff_id, 'frozen_by' => $other->staff_id, 'reason' => 'a second one',
    ])))->toBe('23505');
});

it('rolls back on an empty table set', function () {
    fsMigration()->down();

    expect(Schema::hasTable('staff_notice'))->toBeFalse()
        ->and(Schema::hasColumn('founder_device_approval', 'expires_at'))->toBeFalse()
        ->and(Schema::hasColumn('account_freeze', 'reason'))->toBeFalse()
        ->and(DB::table('permissions')->where('name', 'founder.unfreeze_second')->exists())->toBeFalse();
});

it('refuses to roll back once spec 021 data exists', function () {
    StaffNotice::factory()->create();

    expect(fn () => fsMigration()->down())->toThrow(RuntimeException::class, 'cannot be rolled back');
});

it('refuses to roll back once a held sign-in exists', function () {
    FounderDeviceApproval::factory()->create();

    expect(fn () => fsMigration()->down())->toThrow(RuntimeException::class, 'cannot be rolled back');
});
