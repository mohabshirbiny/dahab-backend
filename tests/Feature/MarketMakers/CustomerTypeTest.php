<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Promo;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Making a customer a market maker, and an ordinary customer again, from the Customer file.

function postCustomerType(Customer $c, array $body): TestResponse
{
    return test()->postJson("/api/v1/dashboard/customers/{$c->customer_id}/type", $body, Promo::key());
}

it('makes a customer a market maker, audits it, and lets a dealer code be tied to them', function () {
    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $c = Customer::factory()->verified()->create();

    $this->postJson('/api/v1/dashboard/promo-codes', ['code' => 'MM-NEW', 'tied_customer_id' => $c->customer_id], Promo::key())
        ->assertUnprocessable()->assertJsonPath('code', 'promo_customer_not_market_maker');

    postCustomerType($c, ['type' => 'market_maker', 'note' => 'Known dealer in Khan el-Khalili'])
        ->assertOk()
        ->assertJsonPath('data.customer_type', 'market_maker');

    expect($c->refresh()->customer_type->value)->toBe('market_maker');

    $this->postJson('/api/v1/dashboard/promo-codes', ['code' => 'MM-NEW', 'tied_customer_id' => $c->customer_id], Promo::key())
        ->assertCreated()->assertJsonPath('data.kind', 'market_maker');

    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'customer.type_changed')->sole());
    expect($audit->reason)->toBe('Known dealer in Khan el-Khalili')
        ->and($audit->before_json['customer_type'])->toBe('ordinary')
        ->and($audit->after_json['customer_type'])->toBe('market_maker');
});

it('refuses to remove the type while a dealer code is on, and allows it once the code is off', function () {
    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $c = Customer::factory()->verified()->create();
    $code = Promo::dealerCode($c);

    postCustomerType($c, ['type' => 'ordinary', 'note' => 'No longer trading'])
        ->assertStatus(409)->assertJsonPath('code', 'customer_has_active_dealer_code');
    expect($c->refresh()->customer_type->value)->toBe('market_maker');

    $this->patchJson("/api/v1/dashboard/promo-codes/{$code->code}", ['active' => false], Promo::key())->assertOk();

    postCustomerType($c, ['type' => 'ordinary', 'note' => 'No longer trading'])
        ->assertOk()->assertJsonPath('data.customer_type', 'ordinary');
});

it('refuses a type the customer already has', function () {
    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $c = Customer::factory()->verified()->create();

    postCustomerType($c, ['type' => 'ordinary', 'note' => 'x'])
        ->assertStatus(409)->assertJsonPath('code', 'customer_type_unchanged');
});

it('needs a valid type and a note', function () {
    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $c = Customer::factory()->verified()->create();

    postCustomerType($c, ['type' => 'dealer', 'note' => 'x'])->assertUnprocessable();
    postCustomerType($c, ['type' => 'market_maker'])->assertUnprocessable();
    postCustomerType($c, ['type' => 'market_maker', 'note' => ''])->assertUnprocessable();
});

it('is refused to staff without promo.manage', function () {
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);
    $c = Customer::factory()->verified()->create();

    postCustomerType($c, ['type' => 'market_maker', 'note' => 'x'])->assertForbidden();
    expect($c->refresh()->customer_type->value)->toBe('ordinary');
});
