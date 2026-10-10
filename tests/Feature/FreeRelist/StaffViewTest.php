<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FreeRelists;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 018 US5, FR-033–FR-036, FR-050–FR-055: staff read the offer, the
// relisted piece and the ratings; they never create or change any of them.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

it('shows the offer, the relisted listing and the origin link to order.view', function () {
    $origin = FreeRelists::ac018CollectedOrder($this);
    Orders::staff($this, SeedRole::OPERATIONS);

    $this->getJson(Orders::STAFF_URL."/{$origin->order_id}")->assertOk()
        ->assertJsonPath('data.free_relist.status', 'open')
        ->assertJsonPath('data.free_relist.listing', null)
        ->assertJsonPath('data.relisted_from_order', null);

    FreeRelists::ac018Relist($this, Orders::buyer($origin), $origin)->assertCreated();
    $relisted = Listing::query()->where('relisted_from_order_id', $origin->order_id)->sole();
    $second = FreeRelists::ac018SettleRelisted($this, $relisted);

    Orders::staff($this, SeedRole::OPERATIONS);
    $this->getJson(Orders::STAFF_URL."/{$origin->order_id}")->assertOk()
        ->assertJsonPath('data.free_relist.status', 'used')
        ->assertJsonPath('data.free_relist.listing.id', $relisted->listing_id);
    $this->getJson(Orders::STAFF_URL."/{$second->order_id}")->assertOk()
        ->assertJsonPath('data.relisted_from_order.id', $origin->order_id)
        ->assertJsonPath('data.relisted_from_order.order_ref', $origin->order_ref)
        ->assertJsonPath('data.free_relist.status', 'none');
});

it('gives ratings only to rating.view, omitting the key otherwise', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    FreeRelists::ac018Rate($this, Orders::buyer($order), $order, ['stars' => 5, 'note' => 'Quick.'])->assertCreated();
    FreeRelists::ac018Rate($this, Orders::seller($order), $order, ['stars' => 3])->assertCreated();

    Orders::staff($this, SeedRole::OPERATIONS);
    $without = $this->getJson(Orders::STAFF_URL."/{$order->order_id}")->assertOk();
    expect($without->json('data'))->not->toHaveKey('ratings');

    foreach ([SeedRole::CEO, SeedRole::COO] as $role) {
        Orders::staff($this, $role);
        $with = $this->getJson(Orders::STAFF_URL."/{$order->order_id}")->assertOk();
        expect($with->json('data.ratings'))->toHaveCount(2)
            ->and(collect($with->json('data.ratings'))->pluck('stars', 'party_role')->all())->toBe(['buyer' => 5, 'seller' => 3]);
    }
});

it('filters the list and the export by the offer state', function () {
    $open = FreeRelists::ac018CollectedOrder($this);
    $used = FreeRelists::ac018CollectedOrder($this);
    FreeRelists::ac018Relist($this, Orders::buyer($used), $used)->assertCreated();

    Orders::staff($this, SeedRole::OPERATIONS);
    $refs = fn (string $status) => collect($this->getJson(Orders::STAFF_URL."?free_relist={$status}")->assertOk()->json('data'))->pluck('order_ref')->all();

    expect($refs('open'))->toBe([$open->order_ref])
        ->and($refs('used'))->toBe([$used->order_ref])
        ->and($refs('expired'))->toBe([]);

    $csv = $this->get(Orders::STAFF_URL.'/export?free_relist=used')->assertOk()->getContent();
    expect($csv)->toContain($used->order_ref)->not->toContain($open->order_ref);

    $this->travel(30)->days();
    expect($refs('open'))->toBe([])->and($refs('expired'))->toBe([$open->order_ref]);

    $this->getJson(Orders::STAFF_URL.'?free_relist=bogus')->assertStatus(422);
});

it('shows the relisted listing its origin to staff and writes the event to the customer history', function () {
    $origin = FreeRelists::ac018CollectedOrder($this);
    FreeRelists::ac018Relist($this, Orders::buyer($origin), $origin)->assertCreated();
    $relisted = Listing::query()->where('relisted_from_order_id', $origin->order_id)->sole();

    Orders::staff($this, SeedRole::COO);
    $this->getJson(Listings::STAFF_URL."/{$relisted->listing_id}")->assertOk()
        ->assertJsonPath('data.relisted_from_order.order_ref', $origin->order_ref)
        ->assertJsonPath('data.state', 'live');

    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'order.free_relisted')->count()))->toBe(1);
});

it('offers staff no way to create, change or delete an offer or a rating', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    Orders::staff($this, SeedRole::CEO);

    foreach (['free-relist', 'rating', 'ratings'] as $suffix) {
        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $verb) {
            expect($this->{$verb}(Orders::STAFF_URL."/{$order->order_id}/{$suffix}", [], Listings::key())->status())->toBeIn([404, 405]);
        }
    }
});

it('puts the relist in the customer history and shows the rating there only with rating.view', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 4, 'note' => 'Fine.'])->assertCreated();
    FreeRelists::ac018Relist($this, $buyer, $order)->assertCreated();

    $url = '/api/v1/dashboard/customers/'.$buyer->customer_id.'/activity';
    $actions = fn () => collect($this->getJson($url)->assertOk()->json('data'))->pluck('action')->all();

    // Operations holds neither audit.view_all nor rating.view; give it the first and the customer file.
    $staff = Orders::staff($this, SeedRole::OPERATIONS);
    DatabaseActor::elevate('maintenance', fn () => $staff->givePermissionTo(['audit.view_all', 'customer.view']));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $without = $actions();
    expect($without)->toContain('order.free_relisted')->not->toContain('order.rated');

    Orders::staff($this, SeedRole::CEO);
    expect($actions())->toContain('order.free_relisted')->toContain('order.rated');
});
