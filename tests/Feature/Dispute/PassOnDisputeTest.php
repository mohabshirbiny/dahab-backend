<?php

use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\DisputeChange;
use App\Models\Staff;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-009: pass a dispute to a named colleague; nothing to the customer.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Disputes::awaitingBalance($this);
    $this->dispute = Disputes::opened($this, $this->order);
});

it('passes a dispute on, and again, with the note kept for staff only and nothing sent', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $coo = Orders::staff($this, SeedRole::COO);
    $finance = Orders::staff($this, SeedRole::FINANCE);
    $me = Orders::staff($this, SeedRole::OPERATIONS);

    Disputes::passOn($this, $this->dispute, ['assignee_id' => $coo->staff_id, 'note' => 'Please call the seller about the scale.'])
        ->assertOk()->assertJsonPath('data.state', 'passed_on')->assertJsonPath('data.assigned_to.staff_id', $coo->staff_id)
        ->assertJsonPath('data.history.1.kind', 'passed_on')->assertJsonPath('data.history.1.note', 'Please call the seller about the scale.');

    Disputes::actAs($coo);
    Disputes::passOn($this, $this->dispute, ['assignee_id' => $finance->staff_id, 'note' => 'Over to Finance for the money side.'])
        ->assertOk()->assertJsonPath('data.assigned_to.staff_id', $finance->staff_id);

    $changes = DatabaseActor::elevate('maintenance', fn () => DisputeChange::query()->where('dispute_id', $this->dispute->dispute_id)->orderBy('change_id')->get());
    expect($changes->pluck('kind')->map->value->all())->toBe(['opened', 'passed_on', 'passed_on'])
        ->and($changes[1]->actor_staff_id)->toBe($me->staff_id)
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'dispute.passed_on')->count()))->toBe(2);
    Bus::assertNotDispatched(NotifyCustomerJob::class);
});

it('refuses an assignee who is the caller, inactive, or does not handle disputes', function () {
    $igi = Orders::staff($this, SeedRole::IGI_BRANCH);
    $inactive = Orders::staff($this, SeedRole::COO);
    DatabaseActor::elevate('maintenance', fn () => Staff::query()->whereKey($inactive->staff_id)->update(['is_active' => false]));
    $me = Orders::staff($this, SeedRole::OPERATIONS);

    foreach ([$me->staff_id, $inactive->staff_id, $igi->staff_id, (string) Str::uuid()] as $id) {
        Disputes::passOn($this, $this->dispute, ['assignee_id' => $id, 'note' => 'Please take this one over.'])
            ->assertStatus(422)->assertJsonPath('code', 'assignee_not_eligible');
    }
    Disputes::passOn($this, $this->dispute, ['assignee_id' => $igi->staff_id, 'note' => 'short'])->assertStatus(422)->assertJsonValidationErrors('note');
});

it('cannot pass on a resolved dispute', function () {
    $coo = Orders::staff($this, SeedRole::COO);
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $this->dispute)->assertOk();

    Disputes::passOn($this, $this->dispute, ['assignee_id' => $coo->staff_id, 'note' => 'Please take this one over.'])
        ->assertStatus(409)->assertJsonPath('code', 'illegal_dispute_transition');
});
