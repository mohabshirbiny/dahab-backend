<?php

use App\Enums\SeedRole;
use App\Models\CategoryControl;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US7: category controls and what customers can see.

const CONTROLS_URL = '/api/v1/dashboard/category-controls';

function ctlSet($test, array $body)
{
    return $test->postJson(CONTROLS_URL, $body, ['Idempotency-Key' => (string) Str::uuid()]);
}

function ctlClear($test, array $body)
{
    return $test->postJson(CONTROLS_URL.'/clear', $body, ['Idempotency-Key' => (string) Str::uuid()]);
}

it('stops new listings in one category with a reason, and a seller is refused with the message', function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $seller = Customer::factory()->verified()->create();
    $body = Listings::goldBody($this, $seller);

    TopUps::actAsStaff($this, SeedRole::OPERATIONS);
    ctlSet($this, ['level' => 'stop_new_listings', 'category' => 'gold', 'reason' => 'Gold stock is being counted', 'message_en' => 'Back on Monday.', 'message_ar' => 'راجعين الاتنين.'])
        ->assertCreated();

    Listings::as($this, $seller)->postJson(Listings::SELLER_URL, $body, Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'category_stopped')->assertJsonPath('details.message.en', 'Back on Monday.');

    TopUps::actAsStaff($this, SeedRole::OPERATIONS);
    ctlSet($this, ['level' => 'stop_new_listings', 'category' => 'gold', 'reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'control_already_active');

    ctlClear($this, ['level' => 'stop_new_listings', 'category' => 'gold'])->assertOk();
    expect(CategoryControl::query()->where('is_active', false)->whereNotNull('cleared_by')->count())->toBe(1);

    Listings::as($this, $seller)->postJson(Listings::SELLER_URL, Listings::goldBody($this, $seller), Listings::key())->assertCreated();
});

it('gives each level its own permission: Operations may stop new listings, only the COO pauses or stops everything', function () {
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    ctlSet($this, ['level' => 'pause_category', 'category' => 'diamond', 'reason' => 'x'])->assertForbidden();
    ctlSet($this, ['level' => 'stop_everything', 'reason' => 'x'])->assertForbidden();
    ctlSet($this, ['level' => 'stop_new_listings', 'category' => 'diamond', 'reason' => 'x'])->assertCreated();
    $this->patchJson('/api/v1/dashboard/visibility/sharing', ['shown' => false], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

    TopUps::actAsStaff($this, SeedRole::COO);
    ctlSet($this, ['level' => 'pause_category', 'category' => 'diamond', 'reason' => 'Cleaning the cases'])->assertCreated();
    ctlSet($this, ['level' => 'pause_category', 'reason' => 'no category'])->assertStatus(422)->assertJsonPath('code', 'control_category_required');
});

it('stop everything writes one row per category and the public read says so', function () {
    TopUps::actAsStaff($this, SeedRole::COO);
    ctlSet($this, ['level' => 'stop_everything', 'reason' => 'Bank outage'])->assertCreated();

    expect(CategoryControl::query()->where('level', 'stop_everything')->where('is_active', true)->count())->toBe(3);

    $this->getJson('/api/v1/reference/controls')->assertOk()
        ->assertJsonPath('data.stop_everything.active', true)
        ->assertJsonPath('data.stop_everything.message.en', 'Dahab is paused for a short while. Your money and pieces are safe.');

    ctlClear($this, ['level' => 'stop_everything'])->assertOk();
    $this->getJson('/api/v1/reference/controls')->assertJsonPath('data.stop_everything.active', false);
});

it('changes what customers can see from Switches only, and keeps history', function () {
    TopUps::actAsStaff($this, SeedRole::COO);

    $this->patchJson('/api/v1/dashboard/visibility/sharing', ['shown' => false], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('data.sharing', false);
    $this->getJson('/api/v1/reference/controls')->assertJsonPath('data.visibility.sharing', false)
        ->assertJsonPath('data.visibility.payout_averages', false);

    // The generic settings PATCH refuses the group.
    $this->patchJson('/api/v1/dashboard/settings/visibility.sharing', ['value' => true, 'reason' => 'x'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonPath('code', 'setting_not_editable_here');
});

it('never edits or deletes a control row, only clears it', function () {
    TopUps::actAsStaff($this, SeedRole::COO);
    ctlSet($this, ['level' => 'pause_category', 'category' => 'gold', 'reason' => 'x'])->assertCreated();
    $row = CategoryControl::query()->firstOrFail();

    $state = null;
    try {
        DB::transaction(fn () => DB::table('category_control')->where('control_id', $row->control_id)->update(['reason' => 'changed']));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0];
    }
    expect($state)->toBe('DH019');

    $state = null;
    try {
        DB::transaction(fn () => DB::table('category_control')->where('control_id', $row->control_id)->delete());
    } catch (QueryException $e) {
        $state = $e->errorInfo[0];
    }
    expect($state)->toBe('DH019');
});
