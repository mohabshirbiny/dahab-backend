<?php

use App\Enums\CustomerStatus;
use App\Enums\SuspendedReason;
use App\Models\Customer;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 007 data-model: the database keeps the suspension state coherent.

/** Runs $write in a savepoint and reports whether the database refused it. */
function refusedByDatabase(Closure $write): bool
{
    try {
        DB::transaction($write);

        return false;
    } catch (QueryException) {
        return true;
    }
}

beforeEach(function () {
    $this->staff = Staff::factory()->create();
});

it('refuses a suspended customer without the state it interrupted', function () {
    $customer = Customer::factory()->suspended(byStaffId: $this->staff->staff_id)->create();
    $row = fn () => DB::table('customer')->where('customer_id', $customer->customer_id);

    // Control: the column exists and takes a valid value.
    expect(refusedByDatabase(fn () => $row()->update(['status_before_suspension' => 'rejected'])))->toBeFalse()
        ->and(refusedByDatabase(fn () => $row()->update(['status_before_suspension' => 'suspended'])))->toBeTrue()
        ->and(refusedByDatabase(fn () => $row()->update(['status_before_suspension' => null])))->toBeTrue();
});

it('refuses a pre-suspension state on a customer who is not suspended', function () {
    $customer = Customer::factory()->verified()->create();
    $row = fn () => DB::table('customer')->where('customer_id', $customer->customer_id);

    expect(refusedByDatabase(fn () => $row()->update(['suspended_note' => 'kept', 'status_before_suspension' => null])))->toBeFalse()
        ->and(refusedByDatabase(fn () => $row()->update(['status_before_suspension' => 'active'])))->toBeTrue();
});

it('refuses a suspension reason outside the fixed list', function (string $reason, bool $refused) {
    $customer = Customer::factory()->suspended(SuspendedReason::OTHER, byStaffId: $this->staff->staff_id)->create();

    expect(refusedByDatabase(fn () => DB::table('customer')
        ->where('customer_id', $customer->customer_id)
        ->update(['suspended_reason' => $reason])))->toBe($refused);
})->with([
    'an old code' => ['policy_violation', true],
    'made up' => ['because', true],
    'a listed code' => ['repeated_disputes', false],
]);

it('lists the seven reasons of the design, each with a label', function () {
    expect(array_map(fn (SuspendedReason $r) => $r->value, SuspendedReason::staffChoices()))->toBe([
        'piece_misrepresented', 'off_platform_dealing', 'repeated_disputes',
        'reported_by_users', 'identity_unconfirmed', 'customer_request', 'other',
    ]);
    // Spec 012: one more, set only by the system when cancellations reach the threshold.
    expect(SuspendedReason::REPEATED_CANCELLATIONS->value)->toBe('repeated_cancellations');

    foreach (SuspendedReason::cases() as $reason) {
        expect($reason->label())->toBeString()->not->toBe('');
    }
});

it('only enters suspension through suspend()', function () {
    $customer = Customer::factory()->verified()->create();

    expect(fn () => $customer->transitionTo(CustomerStatus::SUSPENDED))->toThrow(LogicException::class);
});

it('suspends and reinstates back to exactly the interrupted state', function (string $state, bool $verified) {
    $customer = Customer::factory()->{$state}()->create();
    $before = $customer->status;

    $customer->suspend(SuspendedReason::REPEATED_DISPUTES, 'Three disputes this month.', $this->staff);
    $customer->save();
    $customer->refresh();

    expect($customer->status)->toBe(CustomerStatus::SUSPENDED)
        ->and($customer->status_before_suspension)->toBe($before)
        ->and($customer->is_suspended)->toBeTrue()
        ->and($customer->is_verified)->toBe($verified)
        ->and($customer->suspended_reason)->toBe(SuspendedReason::REPEATED_DISPUTES)
        ->and($customer->suspended_note)->toBe('Three disputes this month.')
        ->and($customer->suspended_by)->toBe($this->staff->staff_id)
        ->and($customer->suspended_at)->not->toBeNull();

    $customer->reinstate();
    $customer->save();
    $customer->refresh();

    expect($customer->status)->toBe($before)
        ->and($customer->is_suspended)->toBeFalse()
        ->and($customer->is_verified)->toBe($verified)
        ->and($customer->status_before_suspension)->toBeNull()
        ->and($customer->suspended_reason)->toBeNull()
        ->and($customer->suspended_note)->toBeNull()
        ->and($customer->suspended_by)->toBeNull()
        ->and($customer->suspended_at)->toBeNull();
})->with([
    'verified' => ['verified', true],
    'waiting' => ['pendingVerification', false],
    'rejected' => ['rejected', false],
]);
