<?php

use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Finance;

uses(RefreshDatabase::class);

// Spec 015 SC-007, FR-005/FR-009/FR-012: every new staff route against the six
// seed roles; the COO on none of the money; a frozen staff member refused;
// every POST needs an Idempotency-Key.

/** @return list<array{0: string, 1: string, 2: array<string, mixed>}> method, path, body */
function financeRoutes(Customer $customer): array
{
    $day = now('Africa/Cairo')->subDay()->toDateString();

    return [
        ['GET', '/api/v1/dashboard/compensation', []],
        ['GET', '/api/v1/dashboard/compensation/export', []],
        ['POST', '/api/v1/dashboard/compensation', ['customer_id' => $customer->customer_id, 'amount' => '5', 'reason' => 'goodwill', 'note' => 'A small goodwill gesture.']],
        ['POST', "/api/v1/dashboard/customers/{$customer->customer_id}/wallet-adjustments", ['direction' => 'credit', 'amount' => '5', 'reason' => 'Correcting a wrong amount.']],
        ['GET', '/api/v1/dashboard/wallet-adjustments', []],
        ['POST', '/api/v1/dashboard/bank-movements', ['kind' => 'bank_charge', 'direction' => 'out', 'amount' => '5', 'occurred_on' => $day, 'reason' => 'Monthly account fee.']],
        ['GET', '/api/v1/dashboard/bank-movements', []],
        ['GET', '/api/v1/dashboard/bank-book', []],
        ['GET', '/api/v1/dashboard/daily-close?date='.$day, []],
        ['GET', '/api/v1/dashboard/daily-closes', []],
        ['POST', '/api/v1/dashboard/daily-close', ['date' => $day, 'bank_balance' => '0']],
    ];
}

it('follows the seed: CEO everything, Finance all but the adjustment, the others none', function (SeedRole $role, array $allowed) {
    $customer = Finance::customer('100');
    Finance::staff($this, $role);

    foreach (financeRoutes($customer) as $i => [$method, $path, $body]) {
        $status = $this->json($method, $path, $body, $method === 'POST' ? Finance::key() : [])->status();
        expect(in_array($i, $allowed, true) ? $status !== 403 : $status === 403)->toBeTrue("{$role->value} {$method} {$path} → {$status}");
    }
})->with([
    'ceo' => [SeedRole::CEO, range(0, 10)],
    'finance' => [SeedRole::FINANCE, [0, 1, 2, 4, 5, 6, 7, 8, 9, 10]],
    'coo' => [SeedRole::COO, []],
    'operations' => [SeedRole::OPERATIONS, []],
    'verification' => [SeedRole::VERIFICATION, []],
    'igi' => [SeedRole::IGI_BRANCH, []],
]);

it('refuses a frozen staff member and any POST without an Idempotency-Key', function () {
    $customer = Finance::customer('100');
    $ceo = Finance::staff($this, SeedRole::CEO);

    foreach (financeRoutes($customer) as [$method, $path, $body]) {
        if ($method === 'POST') {
            $this->json('POST', $path, $body)->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
        }
    }

    $finance = Finance::staff($this, SeedRole::FINANCE);
    AccountFreeze::query()->create(['frozen_staff_id' => $finance->staff_id, 'frozen_by' => $ceo->staff_id, 'frozen_at' => now()]);
    $this->getJson('/api/v1/dashboard/compensation')->assertForbidden()->assertJsonPath('code', 'account_frozen');
    $this->postJson('/api/v1/dashboard/daily-close', ['date' => now('Africa/Cairo')->subDay()->toDateString(), 'bank_balance' => '0'], Finance::key())
        ->assertForbidden()->assertJsonPath('code', 'account_frozen');
});
