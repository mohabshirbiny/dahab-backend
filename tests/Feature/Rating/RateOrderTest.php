<?php

use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Disputes;
use Tests\Support\FreeRelists;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 018 US4, FR-030–FR-036, research R12–R13: one immutable rating per
// party and order, 1–5 stars and an optional note; no effect on anything.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

function ac018Ratings(): array
{
    return DatabaseActor::elevate('maintenance', fn () => DB::table('order_rating')->orderBy('created_at')->get()->map(fn ($r) => (array) $r)->all());
}

it('lets the seller rate once the piece is ready to collect, and the buyer only when completed', function () {
    Bus::fake([NotifyCustomerJob::class]);
    Orders::workedPrices();
    $order = Orders::inspected($this, Orders::accepted($this, Orders::ring(), '200000'), '10.000');
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    $order->refresh();

    // The buyer cannot rate before collecting; the seller can already.
    FreeRelists::ac018Rate($this, Orders::buyer($order), $order, ['stars' => 5])->assertStatus(409)->assertJsonPath('code', 'rating_not_available');
    FreeRelists::ac018Rate($this, Orders::seller($order), $order, ['stars' => 4, 'note' => 'Quick and clear.'])
        ->assertCreated()
        ->assertJsonPath('data.rating.stars', 4)
        ->assertJsonPath('data.rating.note', 'Quick and clear.')
        ->assertJsonPath('data.order.rating.given.stars', 4);

    expect(ac018Ratings())->toHaveCount(1)
        ->and(ac018Ratings()[0]['party_role'])->toBe('seller')
        ->and(ac018Ratings()[0]['customer_id'])->toBe($order->seller_id);

    // Handover: now the buyer may.
    $code = Orders::show($this, Orders::buyer($order), $order)->json('data.collection_code');
    Orders::staff($this, SeedRole::IGI_BRANCH, $order->branch_id);
    $this->postJson(Orders::STAFF_URL."/{$order->order_id}/handover", ['code' => $code], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    FreeRelists::ac018Rate($this, Orders::buyer($order), $order, ['stars' => 2])->assertCreated();

    // No notification and no audit note text; nothing else changed.
    Bus::assertNotDispatched(NotifyCustomerJob::class, fn ($job) => str_contains($job->notification::class, 'Rating'));
    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'order.rated')->get());
    expect($audit)->toHaveCount(2)
        ->and(json_encode($audit->pluck('context')))->not->toContain('Quick and clear');
});

it('validates the stars and the note, storing an empty note as none', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);

    FreeRelists::ac018Rate($this, $buyer, $order, [])->assertStatus(422)->assertJsonValidationErrors(['stars']);
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 0])->assertStatus(422);
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 6])->assertStatus(422);
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 'five'])->assertStatus(422);
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 5, 'note' => str_repeat('a', 501)])->assertStatus(422)->assertJsonValidationErrors(['note']);

    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 5, 'note' => '   '])->assertCreated()->assertJsonPath('data.rating.note', null);
});

it('replays the same key and refuses a second rating by another key', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);

    $k1 = (string) Str::uuid();
    $k2 = (string) Str::uuid();
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 5], $k1)->assertCreated();
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 5], $k1)->assertCreated();
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 1], $k2)->assertStatus(409)->assertJsonPath('code', 'already_rated');

    expect(ac018Ratings())->toHaveCount(1)->and(ac018Ratings()[0]['stars'])->toBe(5);
});

it('closes thirty days after the window opens', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);

    $view = Orders::show($this, $buyer, $order)->assertOk()->assertJsonPath('data.rating.can_rate', true);
    expect($view->json('data.rating.closes_at'))->not->toBeNull();

    $this->travel(31)->days();
    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 5])->assertStatus(409)->assertJsonPath('code', 'rating_closed');
    Orders::show($this, $buyer, $order)->assertJsonPath('data.rating.can_rate', false);
});

it('gives nothing to rate on a cancelled order, and keeps an earlier rating', function () {
    Orders::workedPrices();
    $order = Disputes::readyToCollect($this);
    $seller = Orders::seller($order);
    FreeRelists::ac018Rate($this, $seller, $order, ['stars' => 3])->assertCreated();

    // Cancelled before payment: no settlement, nothing to rate.
    $unpaid = Orders::accepted($this, Orders::ring());
    Orders::cancel($this, Orders::seller($unpaid), $unpaid)->assertOk();
    FreeRelists::ac018Rate($this, Orders::seller($unpaid), $unpaid, ['stars' => 5])->assertStatus(409)->assertJsonPath('code', 'rating_not_available');
    FreeRelists::ac018Rate($this, Orders::buyer($unpaid), $unpaid, ['stars' => 5])->assertStatus(409)->assertJsonPath('code', 'rating_not_available');

    expect(ac018Ratings())->toHaveCount(1);
});

it('lets a suspended customer rate, refuses a closed one, hides the order from strangers', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);
    $seller = Orders::seller($order);

    FreeRelists::ac018Suspend($buyer);
    FreeRelists::ac018Rate($this, $buyer->refresh(), $order, ['stars' => 4])->assertCreated();

    FreeRelists::ac018Rate($this, Customer::factory()->verified()->create(), $order, ['stars' => 4])->assertNotFound();

    FreeRelists::ac018Close($seller);
    FreeRelists::ac018Rate($this, $seller->refresh(), $order, ['stars' => 4])->assertForbidden()->assertJsonPath('code', 'account_closed');
});

it('shows each customer only their own rating', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    FreeRelists::ac018Rate($this, Orders::buyer($order), $order, ['stars' => 5, 'note' => 'Great.'])->assertCreated();
    FreeRelists::ac018Rate($this, Orders::seller($order), $order, ['stars' => 2, 'note' => 'Slow.'])->assertCreated();

    $buyerView = Orders::show($this, Orders::buyer($order), $order)->assertOk();
    $sellerView = Orders::show($this, Orders::seller($order), $order)->assertOk();

    expect($buyerView->json('data.rating.given.stars'))->toBe(5)
        ->and($sellerView->json('data.rating.given.stars'))->toBe(2)
        ->and(json_encode($buyerView->json()))->not->toContain('Slow.')
        ->and(json_encode($sellerView->json()))->not->toContain('Great.');
});

it('limits a customer to ten rating requests a minute', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);

    for ($i = 0; $i < 10; $i++) {
        FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 5], (string) Str::uuid());
    }

    FreeRelists::ac018Rate($this, $buyer, $order, ['stars' => 5], (string) Str::uuid())->assertStatus(429);
});
