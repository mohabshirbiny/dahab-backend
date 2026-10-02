<?php

use App\Enums\SeedRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-010, research R18, Part 1 §3.4: the branch work list shows what
// a counter needs — never a price, an amount or a name — and an inspector
// never reads money anywhere.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->here = Orders::accepted($this);
    $this->there = Orders::accepted($this);
    $this->elsewhere = Branch::factory()->create();
    DatabaseActor::elevate('maintenance', function () {
        DB::table('listing_branch_option')->insert(['listing_id' => $this->there->listing_id, 'branch_id' => $this->elsewhere->branch_id]);
        DB::table('order')->where('order_id', $this->there->order_id)->update(['branch_id' => $this->elsewhere->branch_id]);
    });
});

it('lists the pieces at my branch only, with their task', function () {
    Orders::staff($this, SeedRole::IGI_BRANCH, $this->here->branch_id);

    $list = $this->getJson(Orders::WORK_LIST_URL)->assertOk();

    expect(collect($list->json('data'))->pluck('order_id')->all())->toBe([$this->here->order_id])
        ->and($list->json('data.0.task'))->toBe('receive')
        ->and($list->json('data.0.stated_weight_g'))->toBe('10.000');
});

it('lists every branch for staff with none, optionally narrowed', function () {
    Orders::staff($this, SeedRole::OPERATIONS);

    expect($this->getJson(Orders::WORK_LIST_URL)->assertOk()->json('data'))->toHaveCount(2)
        ->and($this->getJson(Orders::WORK_LIST_URL.'?branch_id='.$this->elsewhere->branch_id)->assertOk()->json('data.0.order_id'))
        ->toBe($this->there->order_id);
});

it('needs a branch permission', function () {
    Orders::staff($this, SeedRole::FINANCE);
    $this->getJson(Orders::WORK_LIST_URL)->assertForbidden();
});

it('never shows an inspector money or names, on any answer they can reach', function () {
    // The seeded igi_branch role holds order.receive and inspection.enter, not order.view:
    // every answer it gets is the money-free work-list shape.
    Orders::staff($this, SeedRole::IGI_BRANCH);

    $answers = [
        Orders::receive($this, $this->here)->assertOk()->getContent(),
        Orders::result($this, $this->here, ['measured_karat' => 21, 'measured_weight_g' => '9.900'])->assertCreated()->getContent(),
        $this->getJson(Orders::WORK_LIST_URL)->assertOk()->getContent(),
    ];
    $seller = Customer::query()->find($this->here->seller_id);
    $buyer = Customer::query()->find($this->here->buyer_id);

    foreach ($answers as $body) {
        foreach (['price', 'total', 'deposit', 'amount', 'balance', 'proceeds', 'commission', 'vat', 'spread', 'held', 'paid', 'value', 'display_ref', 'customer_id', 'phone', 'email', 'name'] as $key) {
            expect($body)->not->toContain("\"{$key}\":")->not->toContain("_{$key}\":");
        }
        foreach ([$seller, $buyer] as $c) {
            expect($body)->not->toContain($c->phone)->not->toContain($c->display_ref === '' ? '§' : '"'.$c->display_ref.'"');
        }
        expect($body)->not->toContain('55631')->not->toContain('11126');
    }
});
