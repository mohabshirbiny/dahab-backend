<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\BuyRequest;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US10, FR-023: every buy request across listings, soonest reply
// deadline first, the ones close to expiry flagged; read-only; buyers and
// sellers by display reference only.

const STAFF_BUY_REQUESTS_URL = '/api/v1/dashboard/buy-requests';

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->first = Orders::ring();
    $this->second = Orders::ring();
    $this->a = BuyRequests::queued($this, BuyRequests::funded('20000'), $this->first);
    $this->b = BuyRequests::queued($this, BuyRequests::funded('20000'), $this->first);
    $this->travel(2)->hours();
    $this->c = BuyRequests::queued($this, BuyRequests::funded('20000'), $this->second);
});

it('lists queued requests across listings, soonest deadline first, with the place in line', function () {
    Orders::staff($this, SeedRole::OPERATIONS);

    $res = $this->getJson(STAFF_BUY_REQUESTS_URL)->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())->toBe([$this->a->buy_request_id, $this->b->buy_request_id, $this->c->buy_request_id])
        ->and($res->json('data.1.place_in_line'))->toBe(2)
        ->and($res->json('data.1.queue_length'))->toBe(2)
        ->and($res->json('data.0.deposit_amount'))->toBe('11126.2500')
        ->and($res->json('meta.counts'))->toBe(['queued' => 3, 'near_expiry' => 0]);
});

it('flags and filters the ones close to expiry, and filters by listing and state', function () {
    Orders::staff($this, SeedRole::OPERATIONS);
    $this->travelTo($this->a->seller_reply_deadline->subHours(5));

    $near = $this->getJson(STAFF_BUY_REQUESTS_URL.'?near_expiry=1')->assertOk();
    expect(collect($near->json('data'))->pluck('id')->all())->toBe([$this->a->buy_request_id, $this->b->buy_request_id])
        ->and($near->json('data.0.near_expiry'))->toBeTrue()
        ->and($near->json('meta.counts.near_expiry'))->toBe(2);

    expect(collect($this->getJson(STAFF_BUY_REQUESTS_URL.'?listing_id='.$this->second->listing_id)->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$this->c->buy_request_id]);

    $seller = Customer::query()->find($this->first->seller_id);
    BuyRequests::accept($this, $seller, $this->first, $this->a, BuyRequests::branchOf($this->first))->assertCreated();
    Orders::staff($this, SeedRole::OPERATIONS);
    $accepted = $this->getJson(STAFF_BUY_REQUESTS_URL.'?state=accepted')->assertOk();
    expect($accepted->json('data.0.id'))->toBe($this->a->buy_request_id)
        ->and($accepted->json('data.0.order_ref'))->not->toBeNull()
        ->and(collect($this->getJson(STAFF_BUY_REQUESTS_URL.'?state=ended')->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$this->b->buy_request_id]);
});

it('never shows a name, phone or email', function () {
    Orders::staff($this, SeedRole::OPERATIONS);
    $body = $this->getJson(STAFF_BUY_REQUESTS_URL)->assertOk()->getContent();

    foreach (BuyRequest::query()->with('buyer')->get() as $r) {
        expect($body)->not->toContain($r->buyer->phone)->and($body)->toContain('"'.$r->buyer->display_ref.'"');
    }
    expect($body)->not->toContain('"phone"')->not->toContain('"email"')->not->toContain('"full_name"');
});

it('needs buy_request.view, audited', function () {
    $staff = Orders::staff($this, SeedRole::FINANCE);

    $this->getJson(STAFF_BUY_REQUESTS_URL)->assertForbidden();
    expect(AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $staff->staff_id)->exists())->toBeTrue();
});
