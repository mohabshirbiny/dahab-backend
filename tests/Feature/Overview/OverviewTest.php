<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Finance;
use Tests\Support\Orders;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 015 FR-016–FR-017: the Overview's figures from the database, each
// section only for the codes that may see it; nothing for features not built.

const OVERVIEW_URL = '/api/v1/dashboard/overview';

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('gives the CEO every section, matching the database', function () {
    $waiting = Orders::accepted($this);
    $paid = Disputes::readyToCollect($this);
    Withdrawals::requested($this, '1000', '1000');
    Disputes::opened($this, Disputes::awaitingBalance($this));
    IdentityDocument::factory()->create(['customer_id' => Customer::factory()->pendingVerification()->create()->customer_id]);

    Finance::staff($this, SeedRole::CEO);
    $res = $this->getJson(OVERVIEW_URL)->assertOk();

    $orders = collect($res->json('data.orders'))->keyBy('state');
    $deposit = DB::table('buy_request')->where('buy_request_id', $waiting->buy_request_id)->value('deposit_amount');
    expect($orders['awaiting_delivery']['count'])->toBe(1)
        ->and($orders['awaiting_delivery']['value'])->toBe(bcadd((string) $waiting->locked_total_price, '0', 4))
        ->and($orders['awaiting_delivery']['held_now'])->toBe(bcadd((string) $deposit, '0', 4))
        ->and($orders['ready_to_collect']['held_now'])->toBe('0.0000')
        ->and($orders['disputed']['count'])->toBe(1)
        ->and($res->json('data.earnings.total'))->toBe(bcadd($res->json('data.earnings.commission'), $res->json('data.earnings.spread'), 4))
        ->and(bccomp($res->json('data.earnings.total'), '0', 4))->toBe(1)
        ->and($res->json('data.this_month.sold'))->toBe(0)
        ->and($res->json('data.this_month.avg_days_to_pay_sellers'))->not->toBeNull();

    $kinds = collect($res->json('data.needs_decision'))->pluck('kind')->unique()->sort()->values()->all();
    expect($kinds)->toBe(['dispute', 'identity_document', 'withdrawal'])
        ->and($res->json('data'))->not->toHaveKey('paid_out_ahead')
        ->and($paid->refresh()->state->value)->toBe('ready_to_collect');
});

it('gives each role only its sections', function () {
    Withdrawals::requested($this, '1000', '1000');
    IdentityDocument::factory()->create(['customer_id' => Customer::factory()->pendingVerification()->create()->customer_id]);

    Finance::staff($this, SeedRole::VERIFICATION);
    $res = $this->getJson(OVERVIEW_URL)->assertOk();
    expect(array_keys($res->json('data')))->toBe(['needs_decision'])
        ->and(collect($res->json('data.needs_decision'))->pluck('kind')->unique()->values()->all())->toBe(['identity_document']);

    Finance::staff($this, SeedRole::OPERATIONS);
    $res = $this->getJson(OVERVIEW_URL)->assertOk();
    expect($res->json('data'))->toHaveKeys(['orders', 'this_month'])->not->toHaveKey('earnings')
        ->and(collect($res->json('data.needs_decision', []))->pluck('kind')->all())->not->toContain('withdrawal');

    Finance::staff($this, SeedRole::FINANCE);
    expect($this->getJson(OVERVIEW_URL)->assertOk()->json('data'))->toHaveKey('earnings');

    Finance::staff($this, SeedRole::IGI_BRANCH);
    expect($this->getJson(OVERVIEW_URL)->assertOk()->json('data'))->toBe([]);
});

it('lists the oldest waiting items first, at most ten', function () {
    foreach (range(1, 12) as $i) {
        IdentityDocument::factory()->create(['customer_id' => Customer::factory()->pendingVerification()->create()->customer_id,
            'created_at' => now()->subHours(20 - $i)]);
    }
    Finance::staff($this, SeedRole::VERIFICATION);
    $rows = $this->getJson(OVERVIEW_URL)->assertOk()->json('data.needs_decision');
    expect($rows)->toHaveCount(10)
        ->and($rows[0]['waiting_of_kind'])->toBe(12)
        ->and($rows[0]['waiting_since'] <= $rows[9]['waiting_since'])->toBeTrue();
});

it('needs a signed-in staff member', function () {
    $this->getJson(OVERVIEW_URL)->assertUnauthorized();
    expect(Order::query()->count())->toBe(0);
});
