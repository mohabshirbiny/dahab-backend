<?php

use App\Enums\SeedRole;
use App\Models\Compensation;
use App\Models\WalletAdjustment;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Finance;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 015 FR-022 / Constitution II: a customer reads only their own wallet
// adjustments and compensation (forced row-level security), never a bank
// movement or a daily close, and can write none of them.

beforeEach(fn () => Notification::fake());

it('lets a customer read only their own adjustments and compensation', function () {
    $mine = Finance::customer('100');
    $theirs = Finance::customer('100');
    Finance::staff($this, SeedRole::CEO);
    Finance::adjust($this, $mine, 'credit', '1')->assertCreated();
    Finance::adjust($this, $theirs, 'credit', '2')->assertCreated();
    Finance::pay($this, $mine, '3')->assertCreated();
    Finance::pay($this, $theirs, '4')->assertCreated();

    DatabaseActor::push('customer', customerId: $mine->customer_id);
    try {
        expect(WalletAdjustment::query()->pluck('amount')->map(fn ($a) => (string) $a)->all())->toBe(['1.0000'])
            ->and(Compensation::query()->pluck('amount')->map(fn ($a) => (string) $a)->all())->toBe(['3.0000'])
            ->and(fn () => DB::transaction(fn () => DB::table('wallet_adjustment')->insert(['customer_id' => $mine->customer_id, 'direction' => 'credit', 'amount' => 9,
                'reason' => 'I would like more money.', 'customer_status' => 'active', 'adjusted_by' => DB::raw('NULL'), 'ledger_txn_id' => DB::raw('gen_random_uuid()')])))
            ->toThrow(QueryException::class);
    } finally {
        DatabaseActor::pop();
    }
});

it('gives a customer nothing of the staff-only finance data through the API', function () {
    $customer = Finance::customer('100');
    Finance::staff($this, SeedRole::FINANCE);
    Finance::record($this, 'capital_in', 'in', '1000')->assertCreated();

    app('auth')->forgetGuards();
    $token = TopUps::customerToken($customer);
    foreach (['/api/v1/dashboard/bank-movements', '/api/v1/dashboard/bank-book', '/api/v1/dashboard/daily-closes', '/api/v1/dashboard/compensation',
        '/api/v1/dashboard/overview'] as $path) {
        expect($this->withToken($token)->getJson($path)->status())->toBeIn([401, 403]);
    }
});
