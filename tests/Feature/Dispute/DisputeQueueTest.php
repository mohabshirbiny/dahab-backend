<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-008, research R17: the Disputes queue, one dispute with its
// order, its photos (audited), the assignees.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('lists unresolved disputes oldest first with counts, filters and keyset pages', function () {
    $a = Disputes::awaitingBalance($this);
    $first = Disputes::opened($this, $a);
    $this->travel(5)->minutes();
    $b = Disputes::readyToCollect($this);
    $second = Disputes::opened($this, $b, Orders::seller($b));

    $me = Orders::staff($this, SeedRole::OPERATIONS);
    $res = $this->getJson(Disputes::STAFF_URL)->assertOk()
        ->assertJsonPath('data.0.ref', $first->dispute_ref)
        ->assertJsonPath('data.0.order.ref', $a->order_ref)
        ->assertJsonPath('data.0.raised_as', 'buyer')
        ->assertJsonPath('data.1.ref', $second->dispute_ref)
        ->assertJsonPath('data.1.raised_as', 'seller')
        ->assertJsonPath('meta.counts.open', 2)
        ->assertJsonPath('meta.counts.passed_on', 0);
    expect($res->json('data.0.age_seconds'))->toBeGreaterThanOrEqual(300);

    $this->getJson(Disputes::STAFF_URL.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ref', $first->dispute_ref);
    $next = $this->getJson(Disputes::STAFF_URL.'?per_page=1')->json('meta.next_cursor');
    $this->getJson(Disputes::STAFF_URL.'?per_page=1&cursor='.$next)->assertJsonPath('data.0.ref', $second->dispute_ref);

    $this->getJson(Disputes::STAFF_URL.'?q='.$b->order_ref)->assertJsonCount(1, 'data')->assertJsonPath('data.0.ref', $second->dispute_ref);
    $this->getJson(Disputes::STAFF_URL.'?q='.strtolower($first->dispute_ref))->assertJsonCount(1, 'data');

    $colleague = Orders::staff($this, SeedRole::COO);
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::passOn($this, $first, ['assignee_id' => $colleague->staff_id, 'note' => 'Please call the seller about it.'])->assertOk();
    $this->getJson(Disputes::STAFF_URL.'?state=passed_on')->assertJsonCount(1, 'data')->assertJsonPath('data.0.assigned_to.staff_id', $colleague->staff_id);
    $this->getJson(Disputes::STAFF_URL.'?state=resolved')->assertJsonCount(0, 'data');
    $this->getJson(Disputes::STAFF_URL)->assertJsonPath('meta.counts.open', 1)->assertJsonPath('meta.counts.passed_on', 1);

    Disputes::actAs($colleague);
    $this->getJson(Disputes::STAFF_URL.'?assigned=me')->assertJsonCount(1, 'data')->assertJsonPath('data.0.ref', $first->dispute_ref);
});

it('shows one dispute with the order, the history and what the caller may do', function () {
    $order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($order);
    Disputes::open($this, $buyer, $order, ['photo_tokens' => [Disputes::photoToken($this, $buyer)]])->assertCreated();
    $dispute = Disputes::of($order);

    Orders::staff($this, SeedRole::OPERATIONS);
    $res = $this->getJson(Disputes::STAFF_URL."/{$dispute->dispute_id}")->assertOk()
        ->assertJsonPath('data.detail', Disputes::DETAIL)
        ->assertJsonCount(1, 'data.photos')
        ->assertJsonPath('data.history.0.kind', 'opened')
        ->assertJsonPath('data.history.0.by.type', 'customer')
        ->assertJsonPath('data.order.order_ref', $order->order_ref)
        ->assertJsonPath('data.order.frozen_from', 'awaiting_balance')
        ->assertJsonPath('data.order.held', $order->buyRequest->deposit_amount)
        ->assertJsonPath('data.can.pass_on', true)
        ->assertJsonPath('data.can.resolve_resume', true)
        ->assertJsonPath('data.can.resolve_against_sale', false)
        ->assertJsonPath('data.can.compensate', false)
        ->assertJsonPath('data.can.compensation_caps', null);
    expect($res->json('data.order.ledger'))->not->toBeEmpty();

    Orders::staff($this, SeedRole::FINANCE);
    $this->getJson(Disputes::STAFF_URL."/{$dispute->dispute_id}")->assertOk()
        ->assertJsonPath('data.can.resolve_against_sale', true)
        ->assertJsonPath('data.can.compensate', true)
        ->assertJsonPath('data.can.compensation_caps.per_payment', '2000.0000')
        ->assertJsonPath('data.can.compensation_caps.left_today', '5000.0000');

    Orders::staff($this, SeedRole::CEO);
    $this->getJson(Disputes::STAFF_URL."/{$dispute->dispute_id}")->assertJsonPath('data.can.compensation_caps', null);
});

it('serves a photo with no-store and audits every view', function () {
    $order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($order);
    Disputes::open($this, $buyer, $order, ['photo_tokens' => [Disputes::photoToken($this, $buyer)]])->assertCreated();
    $dispute = Disputes::of($order);
    $photo = DatabaseActor::elevate('maintenance', fn () => $dispute->photos()->sole());

    Orders::staff($this, SeedRole::OPERATIONS);
    $res = $this->get(Disputes::STAFF_URL."/{$dispute->dispute_id}/photos/{$photo->photo_id}")->assertOk();
    expect($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($res->headers->get('Content-Type'))->toBe('image/png');
    $this->get(Disputes::STAFF_URL."/{$dispute->dispute_id}/photos/{$photo->photo_id}")->assertOk();

    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'dispute.photo_viewed')->count()))->toBe(2);
    $this->get(Disputes::STAFF_URL.'/'.$dispute->dispute_id.'/photos/'.Str::uuid())->assertNotFound();
});

it('lists the colleagues a dispute can be passed to', function () {
    $coo = Orders::staff($this, SeedRole::COO);
    $finance = Orders::staff($this, SeedRole::FINANCE);
    Orders::staff($this, SeedRole::IGI_BRANCH);
    $me = Orders::staff($this, SeedRole::OPERATIONS);

    $ids = collect($this->getJson('/api/v1/dashboard/dispute-assignees')->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($coo->staff_id)->toContain($finance->staff_id)->not->toContain($me->staff_id);
});

it('refuses staff without dispute.handle, and audits it', function (SeedRole $role) {
    Orders::staff($this, $role);
    $this->getJson(Disputes::STAFF_URL)->assertForbidden()->assertJsonPath('code', 'permission_denied');
    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'auth.staff.permission_denied')->count()))->toBe(1);
})->with([SeedRole::VERIFICATION, SeedRole::IGI_BRANCH]);
