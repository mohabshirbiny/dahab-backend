<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\TopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 US2, FR-006, FR-015: GET /dashboard/topups, /{topup}, /export.

const INCOMING_URL = '/api/v1/dashboard/topups';

beforeEach(function () {
    $this->finance = TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->mona = Customer::factory()->verified()->create(['display_ref' => '004417', 'full_name' => 'Mona Hassan Ibrahim', 'phone' => '+201004417000']);
    $this->other = Customer::factory()->verified()->create(['display_ref' => '008842', 'full_name' => 'Karim Adel']);
});

it('lists pending and on-hold notices newest first by default, with totals', function () {
    $old = TopUp::factory()->create(['customer_id' => $this->mona->customer_id, 'claimed_amount' => '1000.00']);
    $held = TopUp::factory()->onHold()->create(['customer_id' => $this->other->customer_id, 'claimed_amount' => '500.50']);
    TopUp::factory()->credited()->create(['customer_id' => $this->mona->customer_id]);
    TopUp::factory()->cancelled()->create(['customer_id' => $this->mona->customer_id]);

    $res = $this->getJson(INCOMING_URL)->assertOk()->assertJsonCount(2, 'data');

    $res->assertJsonPath('data.0.id', $held->topup_id)
        ->assertJsonPath('data.0.hold_note', 'Checking the bank statement.')
        ->assertJsonPath('data.0.customer.display_ref', '008842')
        ->assertJsonPath('data.1.id', $old->topup_id)
        ->assertJsonPath('data.1.customer.full_name', 'Mona Hassan Ibrahim')
        ->assertJsonPath('data.1.origin', 'notice')
        ->assertJsonPath('data.1.allowed_actions', ['match', 'hold', 'reject'])
        ->assertJsonPath('meta.totals.count', 2)
        ->assertJsonPath('meta.totals.claimed', '1500.5000');
});

it('filters by status and Cairo dates', function () {
    TopUp::factory()->credited()->create(['customer_id' => $this->mona->customer_id]);
    TopUp::factory()->create(['customer_id' => $this->mona->customer_id]);

    $this->getJson(INCOMING_URL.'?status=credited')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(INCOMING_URL.'?status=credited,pending')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(INCOMING_URL.'?from='.now('Africa/Cairo')->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(INCOMING_URL.'?from='.now('Africa/Cairo')->toDateString().'&to='.now('Africa/Cairo')->toDateString())->assertOk()->assertJsonCount(1, 'data');
});

it('finds notices by reference, phone or name', function (string $q) {
    $mine = TopUp::factory()->create(['customer_id' => $this->mona->customer_id]);
    TopUp::factory()->create(['customer_id' => $this->other->customer_id]);

    $this->getJson(INCOMING_URL.'?q='.urlencode($q))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->topup_id);
})->with(['DAHAB-004417', 'dahab 004417', '004417', '+201004417000', 'mona hassan']);

it('pages with a cursor', function () {
    TopUp::factory()->count(3)->create(['customer_id' => $this->mona->customer_id]);

    $first = $this->getJson(INCOMING_URL.'?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    $second = $this->getJson(INCOMING_URL.'?per_page=2&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk()->assertJsonCount(1, 'data');

    expect($second->json('meta.next_cursor'))->toBeNull();
});

it('validates the filters', function (string $query) {
    $this->getJson(INCOMING_URL.'?'.$query)->assertStatus(422);
})->with(['status=paid', 'from=2026-10-02&to=2026-10-01', 'from=yesterday', 'per_page=51', 'cursor=nope', 'q='.str_repeat('x', 65)]);

it('shows one notice with its staff fields', function () {
    $topUp = TopUp::factory()->credited('19900.00')->create(['customer_id' => $this->mona->customer_id, 'arrival_reference' => 'IPN-1']);

    $this->getJson(INCOMING_URL.'/'.$topUp->topup_id)->assertOk()
        ->assertJsonPath('data.id', $topUp->topup_id)
        ->assertJsonPath('data.credit_note', 'Provider fee taken.')
        ->assertJsonPath('data.arrival_reference', 'IPN-1')
        ->assertJsonPath('data.ledger_txn_id', $topUp->ledger_txn_id)
        ->assertJsonPath('data.receiving_account.method', 'instapay')
        ->assertJsonPath('data.allowed_actions', []);
});

it('exports the filtered list as CSV and records the export', function () {
    TopUp::factory()->create(['customer_id' => $this->mona->customer_id, 'claimed_amount' => '1000.00']);
    TopUp::factory()->credited()->create(['customer_id' => $this->other->customer_id]);

    $res = $this->get(INCOMING_URL.'/export?status=pending')->assertOk();
    $csv = $res->getContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($res->headers->get('content-type'))->toContain('text/csv')
        ->and(str_starts_with($csv, "\u{FEFF}"))->toBeTrue()
        ->and($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('Number')->toContain('Arrival reference')
        ->and($lines[1])->toContain('004417')->toContain('1000.0000');

    $audit = AuditLog::query()->where('action', 'topup.list_exported')->sole();
    expect($audit->actor_staff_id)->toBe($this->finance->staff_id)
        ->and($audit->after_json)->toMatchArray(['rows' => 1]);
});
