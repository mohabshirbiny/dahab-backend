<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\LedgerEventKind;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\TopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 US3 (FR-013, FR-014): GET /customer/me/wallet/transactions.

function historyToken(Customer $customer): string
{
    return app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;
}

beforeEach(function () {
    $this->customer = Customer::factory()->verified()->create();
    $this->token = historyToken($this->customer);
});

it('lists one row per entry, newest first, with changes and balances after', function () {
    Ledger::topUp($this->customer, '1000');
    Ledger::hold($this->customer, '400');

    $response = $this->bearer($this->token)->getJson('/api/v1/customer/me/wallet/transactions')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0'))->toMatchArray([
            'kind' => 'deposit_hold',
            'available_change' => '-400.0000',
            'held_change' => '400.0000',
            'available_after' => '600.0000',
            'held_after' => '400.0000',
            'reference' => null,
        ])
        ->and($response->json('data.1'))->toMatchArray([
            'kind' => 'topup',
            'available_change' => '1000.0000',
            'held_change' => '0.0000',
            'available_after' => '1000.0000',
            'held_after' => '0.0000',
        ])
        ->and($response->json('data.0'))->not->toHaveKey('memo')
        ->and($response->json('meta'))->toBe(['per_page' => 25, 'next_cursor' => null]);
});

it('pages without gaps or duplicates and keeps balances right across pages', function () {
    foreach (['10', '20', '30', '40', '50'] as $amount) {
        Ledger::topUp($this->customer, $amount);
    }

    $seen = [];
    $url = '/api/v1/customer/me/wallet/transactions?per_page=2';
    do {
        $page = $this->bearer($this->token)->getJson($url)->assertOk();
        array_push($seen, ...$page->json('data'));
        $next = $page->json('meta.next_cursor');
        $url = '/api/v1/customer/me/wallet/transactions?per_page=2&cursor='.$next;
    } while ($next !== null);

    expect(collect($seen)->pluck('available_after')->all())->toBe(['150.0000', '100.0000', '60.0000', '30.0000', '10.0000'])
        ->and(collect($seen)->pluck('id')->unique())->toHaveCount(5);
});

it('refuses a malformed cursor and an out-of-range page size', function (string $query) {
    $this->bearer($this->token)->getJson('/api/v1/customer/me/wallet/transactions?'.$query)
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
})->with(['cursor=not-a-cursor', 'per_page=0', 'per_page=101']);

it('shows only the customer\'s own side of an entry that touches another customer', function () {
    $other = Customer::factory()->verified()->create();
    Ledger::topUp($other, '500');
    $staff = Staff::factory()->create();

    // A (made-up) transfer between two wallets: the other customer's line is not ours.
    Ledger::post(LedgerEventKind::COMPENSATION, null, [
        [Ledger::available($other), '-200'],
        [Ledger::available($this->customer), '200'],
    ], staffId: $staff->staff_id);

    $data = $this->bearer($this->token)->getJson('/api/v1/customer/me/wallet/transactions')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->toMatchArray(['kind' => 'compensation', 'available_change' => '200.0000', 'available_after' => '200.0000']);
});

it('never lists another customer\'s entries', function () {
    Ledger::topUp(Customer::factory()->verified()->create(), '999');

    $this->bearer($this->token)->getJson('/api/v1/customer/me/wallet/transactions')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('refuses customers who are not verified', function () {
    $pending = Customer::factory()->pendingVerification()->create();

    $this->bearer(historyToken($pending))->getJson('/api/v1/customer/me/wallet/transactions')
        ->assertForbidden()->assertJsonPath('code', 'verification_required');
});

it('shows a credited top-up with its number as the reference (spec 009 R12)', function () {
    $topUp = TopUp::factory()->credited()->create(['customer_id' => $this->customer->customer_id]);
    Ledger::hold($this->customer, '100');

    $this->bearer($this->token)->getJson('/api/v1/customer/me/wallet/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'deposit_hold')
        ->assertJsonPath('data.0.reference', null)
        ->assertJsonPath('data.1.kind', 'topup')
        ->assertJsonPath('data.1.reference', 'TOP-'.$topUp->topup_no);
});
