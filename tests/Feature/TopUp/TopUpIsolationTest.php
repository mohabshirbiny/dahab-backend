<?php

use App\Models\Customer;
use App\Models\TopUp;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 FR-012, Constitution II: forced row-level security on `topup`.

beforeEach(function () {
    $this->a = Customer::factory()->verified()->create();
    $this->b = Customer::factory()->verified()->create();
    $this->mine = TopUp::factory()->create(['customer_id' => $this->a->customer_id]);
    $this->theirs = TopUp::factory()->create(['customer_id' => $this->b->customer_id]);
});

it('shows a customer only their own notices, whatever the query', function () {
    DatabaseActor::push('customer', customerId: $this->a->customer_id);

    try {
        $visible = collect(DB::select('SELECT topup_id FROM topup'))->pluck('topup_id')->all();
        $updated = DB::update('UPDATE topup SET updated_at = now() WHERE customer_id = ?', [$this->b->customer_id]);
    } finally {
        DatabaseActor::pop();
    }

    expect($visible)->toBe([$this->mine->topup_id])->and($updated)->toBe(0);
});

it('refuses to create a notice owned by another customer', function () {
    DatabaseActor::push('customer', customerId: $this->a->customer_id);

    try {
        $refused = false;
        try {
            DB::transaction(fn () => DB::table('topup')->insert([
                'customer_id' => $this->b->customer_id, 'origin' => 'notice', 'method' => 'instapay',
                'reference' => 'DAHAB-X', 'claimed_amount' => '1.00',
            ]));
        } catch (QueryException) {
            $refused = true;
        }
    } finally {
        DatabaseActor::pop();
    }

    expect($refused)->toBeTrue();
});

it('answers 404 for another customer\'s notice id', function () {
    $this->bearer(TopUps::customerToken($this->a))
        ->postJson("/api/v1/customer/me/wallet/topups/{$this->theirs->topup_id}/cancel", [], TopUps::key())
        ->assertNotFound();
});
