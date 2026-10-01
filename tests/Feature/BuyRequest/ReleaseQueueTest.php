<?php

use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\BuyRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 US5; FR-019, FR-020: take-down and withdrawal from reserved, and
// the seller's suspension, release the whole line with refunds.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->buyers = collect([BuyRequests::funded('20000'), BuyRequests::funded('20000')]);
    $this->requests = $this->buyers->map(fn (Customer $b) => BuyRequests::queued($this, $b, $this->listing));
});

function assertLineReleased($test, BuyRequestEvent $why, ?string $staffId, ?string $customerId): void
{
    foreach ($test->requests as $i => $request) {
        $release = DB::table('ledger_transaction')->where('buy_request_id', $request->buy_request_id)->where('event_kind', 'deposit_release')->first();

        expect($request->fresh()->state)->toBe(BuyRequestState::RELEASED_DECLINED)
            ->and(BuyRequests::balances($test->buyers[$i]))->toBe(['available' => '20000.0000', 'held' => '0.0000'])
            ->and($release->staff_id)->toBe($staffId)
            ->and($release->customer_id)->toBe($customerId);

        Notification::assertSentTo($test->buyers[$i], BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === $why);
    }
}

it('lets staff take a reserved piece down, releasing and refunding the line', function () {
    $staff = Listings::actAsStaff($this, SeedRole::OPERATIONS);

    $this->getJson(Listings::STAFF_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.can_take_down', true)
        ->assertJsonCount(2, 'data.queue');

    $this->postJson(Listings::STAFF_URL."/{$this->listing->listing_id}/takedown", ['reason' => 'The photos are taken from another website.'], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'withdrawn')->assertJsonCount(0, 'data.queue');

    $audit = AuditLog::query()->where('action', 'listing.taken_down')->sole();
    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN)
        ->and($audit->before_json)->toBe(['state' => 'reserved'])
        ->and($audit->after_json['released_count'] ?? null)->toBe(2);

    assertLineReleased($this, BuyRequestEvent::PIECE_WITHDRAWN, $staff->staff_id, null);
    BuyRequests::checkNow();
});

it('lets the seller withdraw a reserved piece, releasing and refunding the line', function () {
    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.can_withdraw', true)->assertJsonPath('data.queue_count', 2);

    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$this->listing->listing_id}/withdraw", [], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'withdrawn')->assertJsonPath('data.queue_count', 0);

    assertLineReleased($this, BuyRequestEvent::PIECE_WITHDRAWN, null, $this->seller->customer_id);
    expect(AuditLog::query()->where('entity_type', 'listing')->count())->toBe(0);
    BuyRequests::checkNow();
});

it('holds a suspended seller\'s reserved piece and releases its line; reinstating relists it empty', function () {
    $staff = Listings::actAsStaff($this, SeedRole::CEO);
    $url = "/api/v1/dashboard/customers/{$this->seller->customer_id}";

    $this->postJson("{$url}/suspend", ['reason' => 'reported_by_users', 'note' => 'Several buyers reported this seller.'], Listings::key())->assertOk();

    expect($this->listing->fresh()->state)->toBe(ListingState::SUSPENDED_HOLD);
    assertLineReleased($this, BuyRequestEvent::SELLER_SUSPENDED, $staff->staff_id, null);

    $this->postJson("{$url}/reinstate", ['note' => 'The reports were checked and closed.'], Listings::key())->assertOk();

    expect($this->listing->fresh()->state)->toBe(ListingState::LIVE)
        ->and($this->listing->fresh()->active_queue_count)->toBe(0);
    BuyRequests::checkNow();
});

it('leaves a suspended buyer\'s requests in line', function () {
    Listings::actAsStaff($this, SeedRole::CEO);

    $this->postJson("/api/v1/dashboard/customers/{$this->buyers[0]->customer_id}/suspend", ['reason' => 'other', 'note' => 'Checking an identity report.'], Listings::key())->assertOk();

    expect($this->requests[0]->fresh()->state)->toBe(BuyRequestState::QUEUED)
        ->and($this->listing->fresh()->state)->toBe(ListingState::RESERVED)
        ->and(BuyRequests::netHeld($this->requests[0]))->toBe(bcadd((string) $this->requests[0]->deposit_amount, '0', 4));
});
