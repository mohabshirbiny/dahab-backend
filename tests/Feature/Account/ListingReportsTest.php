<?php

use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Enums\ReportState;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\StaffRoleModel;
use App\Notifications\ListingDecisionNotification;
use App\Notifications\ListingReportNotification;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Account;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 017 US8, FR-052, FR-053: report a listing; staff dismiss or take down.

const ACC017_REPORTS = '/api/v1/dashboard/listing-reports';

beforeEach(function () {
    Orders::workedPrices();
    $this->piece = Orders::ring();
    $this->reporter = Customer::factory()->verified()->create();
});

function acc017Report($test, Customer $who, string $listingId, array $extra = [])
{
    return Account::post($test, Listings::token($who), '/listing-reports', ['listing_id' => $listingId, 'reason' => 'photos_not_genuine', ...$extra]);
}

function acc017Reports(): Collection
{
    return DatabaseActor::elevate('system', fn () => ListingReport::query()->orderBy('report_no')->get());
}

it('takes a report on another seller piece, once while open', function () {
    $res = acc017Report($this, $this->reporter, $this->piece->listing_id, ['note' => 'The same photo is on another site'])
        ->assertCreated();

    expect($res->json('data.reference'))->toStartWith('RPT-')
        ->and(acc017Reports()->sole()->state)->toBe(ReportState::OPEN)
        ->and(AuditLog::query()->where('action', AuditEvent::LISTING_REPORT_CREATED->value)->count())->toBe(1);
    acc017Report($this, $this->reporter, $this->piece->listing_id)->assertConflict()->assertJsonPath('code', 'report_already_open');
    acc017Report($this, Customer::factory()->verified()->create(), $this->piece->listing_id)->assertCreated();
});

it('refuses the seller own piece, a piece off the market, an unverified or suspended reporter', function () {
    $seller = Customer::query()->findOrFail($this->piece->seller_id);
    acc017Report($this, $seller, $this->piece->listing_id)->assertUnprocessable()->assertJsonPath('code', 'listing_not_reportable');

    $draft = Listing::factory()->create(['seller_id' => $seller->customer_id]);
    acc017Report($this, $this->reporter, $draft->listing_id)->assertUnprocessable()->assertJsonPath('code', 'listing_not_reportable');

    acc017Report($this, Customer::factory()->pendingVerification()->create(), $this->piece->listing_id)->assertForbidden()->assertJsonPath('code', 'verification_required');
    acc017Report($this, Customer::factory()->suspended(byStaffId: SystemActor::id())->create(), $this->piece->listing_id)
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
    Account::post($this, Listings::token($this->reporter), '/listing-reports', ['listing_id' => $this->piece->listing_id, 'reason' => 'nope'])->assertUnprocessable();
});

it('never shows the seller who reported', function () {
    acc017Report($this, $this->reporter, $this->piece->listing_id)->assertCreated();
    $seller = Customer::query()->findOrFail($this->piece->seller_id);

    $mine = Listings::as($this, $seller)->getJson(Listings::SELLER_URL.'/'.$this->piece->listing_id)->assertOk()->json();
    expect(json_encode($mine))->not->toContain($this->reporter->customer_id)->not->toContain($this->reporter->display_ref);
    expect(DatabaseActor::elevate('system', fn () => CustomerNotification::query()->where('customer_id', $seller->customer_id)->count()))->toBe(0);
});

it('lists reports for staff with listing_report.handle, open first, with counts', function () {
    acc017Report($this, $this->reporter, $this->piece->listing_id, ['reason' => 'off_platform_dealing'])->assertCreated();

    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->getJson(ACC017_REPORTS)->assertOk()
        ->assertJsonPath('meta.counts.open', 1)
        ->assertJsonPath('data.0.reason', 'off_platform_dealing')
        ->assertJsonPath('data.0.reporter_ref', $this->reporter->display_ref)
        ->assertJsonPath('data.0.listing.id', $this->piece->listing_id)
        ->assertJsonPath('data.0.listing.state', 'live');
    $this->getJson(ACC017_REPORTS.'?state=dismissed')->assertOk()->assertJsonCount(0, 'data');

    Listings::actAsStaff($this, SeedRole::FINANCE);
    $this->getJson(ACC017_REPORTS)->assertForbidden();
});

it('dismisses a report with a note and tells the reporter generically', function () {
    Notification::fake();
    acc017Report($this, $this->reporter, $this->piece->listing_id)->assertCreated();
    $report = acc017Reports()->sole();

    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->postJson(ACC017_REPORTS."/{$report->report_id}/dismiss", [], Listings::key())->assertUnprocessable();
    $this->postJson(ACC017_REPORTS."/{$report->report_id}/dismiss", ['note' => 'Photos are the seller own'], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'dismissed')->assertJsonPath('data.staff_note', 'Photos are the seller own');
    $this->postJson(ACC017_REPORTS."/{$report->report_id}/dismiss", ['note' => 'Again'], Listings::key())
        ->assertConflict()->assertJsonPath('code', 'report_not_open');

    Notification::assertSentTo($this->reporter, ListingReportNotification::class, fn ($n, $channels) => $channels === ['inbox']);
    expect(DatabaseActor::elevate('system', fn () => Listing::query()->findOrFail($this->piece->listing_id))->state)->toBe(ListingState::LIVE);
});

it('takes the piece down through the spec 010 take-down and actions every open report on it', function () {
    Notification::fake();
    $other = Customer::factory()->verified()->create();
    acc017Report($this, $this->reporter, $this->piece->listing_id)->assertCreated();
    acc017Report($this, $other, $this->piece->listing_id)->assertCreated();
    $first = acc017Reports()->first();

    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->postJson(ACC017_REPORTS."/{$first->report_id}/take-down", ['reason' => 'The photos are taken from another shop'], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'actioned')->assertJsonPath('data.listing.state', 'withdrawn');

    expect(acc017Reports()->pluck('state')->unique()->all())->toBe([ReportState::ACTIONED])
        ->and(AuditLog::query()->where('action', AuditEvent::LISTING_TAKEN_DOWN->value)->count())->toBe(1);
    Notification::assertSentTo(Customer::query()->findOrFail($this->piece->seller_id), ListingDecisionNotification::class);
    Notification::assertSentTo($this->reporter, ListingReportNotification::class);
    Notification::assertSentTo($other, ListingReportNotification::class);
});

it('needs listing.takedown as well to take a piece down', function () {
    acc017Report($this, $this->reporter, $this->piece->listing_id)->assertCreated();
    $report = acc017Reports()->sole();
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    StaffRoleModel::findByName('operations', 'staff')->revokePermissionTo('listing.takedown');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->postJson(ACC017_REPORTS."/{$report->report_id}/take-down", ['reason' => 'The photos are taken from another shop'], Listings::key())->assertForbidden();
    $this->getJson(ACC017_REPORTS)->assertOk();
});

it('closes the reports of a piece that left the market and tells the reporter', function () {
    Notification::fake();
    acc017Report($this, $this->reporter, $this->piece->listing_id)->assertCreated();
    $seller = Customer::query()->findOrFail($this->piece->seller_id);
    Listings::as($this, $seller)->postJson(Listings::SELLER_URL.'/'.$this->piece->listing_id.'/withdraw', [], Listings::key())->assertOk();

    Artisan::call('listing-reports:close-gone');

    expect(acc017Reports()->sole()->state)->toBe(ReportState::LISTING_GONE);
    Notification::assertSentTo($this->reporter, ListingReportNotification::class);
});
