<?php

use App\Enums\SeedRole;
use App\Enums\TopUpStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\TopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 US2 scenarios 7–9, FR-025, FR-025a: hold, un-hold and reject.

beforeEach(function () {
    Notification::fake();
    $this->finance = TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->customer = Customer::factory()->verified()->create();
    $this->topUp = TopUp::factory()->create(['customer_id' => $this->customer->customer_id]);
});

function topUpAction($test, TopUp $topUp, string $action, array $body = [], bool $withKey = true)
{
    return $test->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/{$action}", $body, $withKey ? TopUps::key() : []);
}

function topUpAudit(TopUp $topUp, string $action): ?AuditLog
{
    return AuditLog::query()->where('action', $action)->where('entity_id', $topUp->topup_id)->first();
}

it('puts a notice on hold with a note and takes it off again', function () {
    topUpAction($this, $this->topUp, 'hold', ['note' => 'Checking the CIB statement.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'on_hold')
        ->assertJsonPath('data.hold_note', 'Checking the CIB statement.')
        ->assertJsonPath('data.held_by.id', $this->finance->staff_id)
        ->assertJsonPath('data.allowed_actions', ['match', 'unhold', 'reject']);

    $hold = topUpAudit($this->topUp, 'topup.held');
    expect($hold->actor_staff_id)->toBe($this->finance->staff_id)
        ->and($hold->reason)->toBe('Checking the CIB statement.')
        ->and($hold->before_json)->toMatchArray(['status' => 'pending'])
        ->and($hold->after_json)->toMatchArray(['status' => 'on_hold']);

    topUpAction($this, $this->topUp, 'unhold')
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.allowed_actions', ['match', 'hold', 'reject']);

    expect(topUpAudit($this->topUp, 'topup.unheld')->after_json)->toMatchArray(['status' => 'pending']);
});

it('requires a note to hold', function () {
    topUpAction($this, $this->topUp, 'hold', ['note' => '  '])->assertStatus(422)->assertJsonValidationErrors(['note']);

    expect($this->topUp->fresh()->status)->toBe(TopUpStatus::PENDING);
});

it('rejects a notice with a reason and a note, telling the customer only the reason', function () {
    $ledgerRows = DB::table('ledger_transaction')->count();

    topUpAction($this, $this->topUp, 'reject', ['reason' => 'money_not_received', 'note' => 'Nothing on the statement after 3 days.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.reject_reason', 'money_not_received')
        ->assertJsonPath('data.reject_note', 'Nothing on the statement after 3 days.')
        ->assertJsonPath('data.rejected_by.id', $this->finance->staff_id);

    $audit = topUpAudit($this->topUp, 'topup.rejected');
    expect($audit->reason)->toBe('Nothing on the statement after 3 days.')
        ->and($audit->after_json)->toMatchArray(['status' => 'rejected', 'reject_reason' => 'money_not_received'])
        ->and(DB::table('ledger_transaction')->count())->toBe($ledgerRows);

    $customerView = $this->withToken(TopUps::customerToken($this->customer))->getJson('/api/v1/customer/me/wallet/topups')->assertOk();
    $customerView->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.0.reject_reason', 'money_not_received');
    expect(json_encode($customerView->json()))->not->toContain('Nothing on the statement');
});

it('rejects a notice that is on hold', function () {
    $held = TopUp::factory()->onHold()->create(['customer_id' => $this->customer->customer_id]);

    topUpAction($this, $held, 'reject', ['reason' => 'sender_not_accepted', 'note' => 'Sender is a third party with no receipt.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('validates the reject reason and note', function (array $body, string $field) {
    topUpAction($this, $this->topUp, 'reject', $body)->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect($this->topUp->fresh()->status)->toBe(TopUpStatus::PENDING);
})->with([
    'no reason' => [['note' => 'x'], 'reason'],
    'unknown reason' => [['reason' => 'fraud', 'note' => 'x'], 'reason'],
    'no note' => [['reason' => 'other'], 'note'],
    'long note' => [['reason' => 'other', 'note' => str_repeat('x', 1001)], 'note'],
]);

it('refuses moves outside the state machine', function (string $state, string $action, array $body) {
    $topUp = TopUp::factory()->{$state}()->create(['customer_id' => $this->customer->customer_id]);

    topUpAction($this, $topUp, $action, $body)->assertStatus(409)->assertJsonPath('code', 'illegal_topup_transition');

    expect($topUp->fresh()->status->value)->toBe($topUp->status->value);
})->with([
    'hold on hold' => ['onHold', 'hold', ['note' => 'x']],
    'unhold pending' => ['pending', 'unhold', []],
    'reject credited' => ['credited', 'reject', ['reason' => 'other', 'note' => 'x']],
    'hold cancelled' => ['cancelled', 'hold', ['note' => 'x']],
    'unhold cancelled' => ['cancelled', 'unhold', []],
    'reject cancelled' => ['cancelled', 'reject', ['reason' => 'other', 'note' => 'x']],
    'hold rejected' => ['rejected', 'hold', ['note' => 'x']],
]);

it('requires an idempotency key on every action', function (string $action, array $body) {
    topUpAction($this, $this->topUp, $action, $body, withKey: false)
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
})->with([
    ['hold', ['note' => 'x']],
    ['unhold', []],
    ['reject', ['reason' => 'other', 'note' => 'x']],
]);
