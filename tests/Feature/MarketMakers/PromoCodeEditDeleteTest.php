<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PromoCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Promo;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Owner request 2026-10-26: edit and delete a promo code. Every term of a code nobody has used yet
// can change, and it can be deleted; a used code keeps its terms (only cap, end date, on/off) and stays.

const EDIT_CODES = '/api/v1/dashboard/promo-codes';

beforeEach(function () {
    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->postJson(EDIT_CODES, ['code' => 'SELL50', 'side' => 'seller', 'product_type' => 'gold', 'commission_mode' => 'percent', 'commission_percent' => 50], Promo::key())
        ->assertCreated();
});

function markUsed(string $code): void
{
    DB::table('promo_code_use')->insert([
        'code' => $code, 'customer_id' => Customer::factory()->verified()->create()->customer_id,
        'allowed' => false, 'block_reason' => 'wrong_product', 'id_key' => 'account:y',
    ]);
}

it('says on the list whether a code is in use', function () {
    expect(collect($this->getJson(EDIT_CODES)->assertOk()->json('data'))->firstWhere('code', 'SELL50')['in_use'])->toBeFalse();

    markUsed('SELL50');

    expect(collect($this->getJson(EDIT_CODES)->json('data'))->firstWhere('code', 'SELL50')['in_use'])->toBeTrue();
});

it('replaces every term of an unused code, with the same rules as creating it, and audits the change', function () {
    $this->putJson(EDIT_CODES.'/sell50', ['side' => 'buyer', 'product_type' => 'diamond', 'deposit_percent' => 20, 'use_limit' => 'once'], Promo::key())
        ->assertOk()
        ->assertJsonPath('data.code', 'SELL50')
        ->assertJsonPath('data.kind', 'buyer')
        ->assertJsonPath('data.product_type', 'diamond')
        ->assertJsonPath('data.what_it_does.deposit.percent', '20.0000')
        ->assertJsonPath('data.what_it_does.commission.mode', 'none')
        ->assertJsonPath('data.conditions.use_limit', 'once')
        ->assertJsonPath('data.is_active', true);

    $this->putJson(EDIT_CODES.'/SELL50', ['side' => 'buyer', 'product_type' => 'diamond'], Promo::key())
        ->assertStatus(422)->assertJsonValidationErrors('deposit_percent');

    $audit = AuditLog::query()->where('action', 'promo.updated')->latest('created_at')->first();
    expect($audit->after_json['terms_replaced'])->toBeTrue()->and($audit->after_json['kind'])->toBe('buyer')
        ->and($audit->before_json['commission_mode'])->toBe('percent');
});

it('refuses to edit or delete a used code, but still changes its end date and switch', function () {
    markUsed('SELL50');

    $this->putJson(EDIT_CODES.'/SELL50', ['side' => 'seller', 'product_type' => 'gold', 'commission_mode' => 'waive'], Promo::key())
        ->assertStatus(409)->assertJsonPath('code', 'promo_code_in_use');
    $this->deleteJson(EDIT_CODES.'/SELL50', [], Promo::key())
        ->assertStatus(409)->assertJsonPath('code', 'promo_code_in_use');

    $this->patchJson(EDIT_CODES.'/SELL50', ['ends_on' => '2027-01-31', 'active' => false], Promo::key())
        ->assertOk()->assertJsonPath('data.ends_on', '2027-01-31')->assertJsonPath('data.is_active', false);

    expect(PromoCode::query()->find('SELL50')->commission_percent)->toBe('50.0000')
        ->and(fn () => DB::table('promo_code')->where('code', 'SELL50')->delete())->toThrow(QueryException::class);
});

it('deletes an unused code and audits every term it had', function () {
    $this->deleteJson(EDIT_CODES.'/SELL50', [], Promo::key())->assertNoContent();

    expect(PromoCode::query()->find('SELL50'))->toBeNull();
    $audit = AuditLog::query()->where('action', 'promo.deleted')->sole();
    expect($audit->before_json['commission_percent'])->toBe('50.0000');

    $this->deleteJson(EDIT_CODES.'/SELL50', [], Promo::key())->assertNotFound();
});

it('keeps the name and the maker of a code, used or not', function () {
    expect(fn () => DB::table('promo_code')->where('code', 'SELL50')->update(['code' => 'OTHER']))->toThrow(QueryException::class);
});
