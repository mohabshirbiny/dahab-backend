<?php

use App\Models\Customer;
use App\Models\Staff;
use App\Models\TopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 US1 scenario 6, FR-012, FR-018a, FR-025a: GET /customer/me/wallet/topups.

const TOPUP_LIST_URL = '/api/v1/customer/me/wallet/topups';

it('lists the customer\'s own notices newest first with the contract fields', function () {
    $customer = Customer::factory()->verified()->create();
    $old = TopUp::factory()->create(['customer_id' => $customer->customer_id, 'claimed_amount' => '100.00']);
    $rejected = TopUp::factory()->rejected('duplicate_notice')->create(['customer_id' => $customer->customer_id]);
    $credited = TopUp::factory()->credited('19900.00')->create(['customer_id' => $customer->customer_id, 'arrival_reference' => 'IPN-1']);
    TopUp::factory()->create(); // someone else's

    $res = $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_LIST_URL)->assertOk()->assertJsonCount(3, 'data');

    $res->assertJsonPath('data.0.id', $credited->topup_id)
        ->assertJsonPath('data.0.status', 'credited')
        ->assertJsonPath('data.0.credited_amount', '19900.0000')
        ->assertJsonPath('data.0.claimed_amount', '20000.0000')
        ->assertJsonPath('data.0.can_cancel', false)
        ->assertJsonPath('data.1.id', $rejected->topup_id)
        ->assertJsonPath('data.1.reject_reason', 'duplicate_notice')
        ->assertJsonPath('data.2.id', $old->topup_id)
        ->assertJsonPath('data.2.can_cancel', true)
        ->assertJsonPath('data.2.reject_reason', null)
        ->assertJsonPath('meta.next_cursor', null);

    foreach ($res->json('data') as $row) {
        expect(array_keys($row))->toEqualCanonicalizing([
            'id', 'number', 'method', 'reference', 'status', 'claimed_amount', 'expected_amount', 'credited_amount',
            'has_receipt', 'submitted_at', 'credited_at', 'reject_reason', 'can_cancel',
        ]);
    }
    // Staff notes, the arrival reference and receiving details never reach the customer.
    expect(json_encode($res->json()))->not->toContain('Nothing arrived')
        ->not->toContain('IPN-1')
        ->not->toContain('instapay_address')
        ->not->toContain('dahab.test@instapay');
});

it('filters by status and pages with a cursor', function () {
    $customer = Customer::factory()->verified()->create();
    TopUp::factory()->count(3)->create(['customer_id' => $customer->customer_id]);
    TopUp::factory()->cancelled()->create(['customer_id' => $customer->customer_id]);

    $token = TopUps::customerToken($customer);
    $this->bearer($token)->getJson(TOPUP_LIST_URL.'?status=cancelled')->assertOk()->assertJsonCount(1, 'data');

    $first = $this->bearer($token)->getJson(TOPUP_LIST_URL.'?status=pending&per_page=2')->assertOk()->assertJsonCount(2, 'data');
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $second = $this->bearer($token)->getJson(TOPUP_LIST_URL.'?status=pending&per_page=2&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data');
    expect($second->json('meta.next_cursor'))->toBeNull()
        ->and(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')))->toBe([]);
});

it('refuses a malformed cursor or filter', function () {
    $customer = Customer::factory()->verified()->create();

    $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_LIST_URL.'?cursor=nope')->assertStatus(422);
    $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_LIST_URL.'?status=paid')->assertStatus(422);
    $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_LIST_URL.'?per_page=51')->assertStatus(422);
});

it('lets a suspended customer see their notices', function () {
    $customer = Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id)->create();
    TopUp::factory()->create(['customer_id' => $customer->customer_id]);

    $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_LIST_URL)->assertOk()->assertJsonCount(1, 'data');
});

it('refuses customers who are not verified', function (string $state) {
    $customer = Customer::factory()->{$state}()->create();

    $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_LIST_URL)
        ->assertForbidden()->assertJsonPath('code', 'verification_required');
})->with(['pendingVerification', 'rejected']);
