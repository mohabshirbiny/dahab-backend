<?php

use App\Enums\SeedRole;
use App\Enums\StaffNoticeKind;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\StaffNotice;
use App\Notifications\FounderSecurityNotification;
use App\Support\DatabaseActor;
use App\Support\Notices\WorkNotices;
use Database\Factories\StaffFactory;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\Account;
use Tests\Support\BuyRequests;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\TopUps;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 026: work notices on the Dashboard bell — who is told, grouping, links, no queue worker,
// rollback, retention, and each of the nine customer events E1–E9.

/** A staff member holding exactly one permission through a role of their own (optionally at a branch). */
function wnHolder(string $permission, ?int $branchId = null, array $state = []): Staff
{
    $role = StaffFactory::ensureRole('wn_'.str_replace('.', '_', $permission));
    $role->givePermissionTo(wnPermission($permission));
    $staff = Staff::factory()->withRole($role->name)->create($state);

    if ($branchId !== null) {
        DatabaseActor::elevate('maintenance', fn () => DB::table('staff')->where('staff_id', $staff->staff_id)->update(['branch_id' => $branchId]));
        $staff->refresh();
    }

    return $staff;
}

/** The permission row (some catalogue entries are written only by the catalogue sync, not by migrations). */
function wnPermission(string $name): Permission
{
    return Permission::findOrCreate($name, Staff::GUARD);
}

/** @return Collection<int, StaffNotice> */
function wnNotices(Staff $staff, ?StaffNoticeKind $kind = null)
{
    return StaffNotice::query()->where('staff_id', $staff->staff_id)
        ->when($kind !== null, fn ($q) => $q->where('kind', $kind->value))
        ->orderByDesc('created_at')->get();
}

function wnRecord(StaffNoticeKind $kind, ?string $itemId = null, ?string $ref = null, ?int $branchId = null): void
{
    DB::transaction(fn () => WorkNotices::record($kind, $itemId, $ref, $branchId));
}

beforeEach(function () {
    // The seed roles first (as the other helpers do), so the catalogue's permissions exist.
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    Orders::workedPrices();
});

describe('recipients and grouping', function () {
    it('tells holders of the queue permission only, whether through a role or directly', function () {
        Queue::fake(); // no worker: the bell is written in the request
        $reviewer = wnHolder('listing.review');
        $direct = Staff::factory()->withRole()->create();
        $direct->givePermissionTo(wnPermission('listing.review'));
        $other = wnHolder('topup.match');

        wnRecord(StaffNoticeKind::WORK_LISTING, 'f6f8a7c4-0b9b-4c55-9d64-1f1e1c3a0001');

        $notice = wnNotices($reviewer)->sole();
        expect($notice->kind)->toBe('work_listing')
            ->and($notice->severity)->toBe('info')
            ->and($notice->title)->toBe('New listing to review')
            ->and($notice->item_count)->toBe(1)
            ->and($notice->link)->toBe('/dashboard/listings?id=f6f8a7c4-0b9b-4c55-9d64-1f1e1c3a0001')
            ->and($notice->read_at)->toBeNull()
            ->and(wnNotices($direct))->toHaveCount(1)
            ->and(wnNotices($other))->toBeEmpty();
        Queue::assertNothingPushed();
    });

    it('skips disabled, frozen and system staff', function () {
        $disabled = wnHolder('dispute.handle', state: ['is_active' => false]);
        $frozen = Staff::factory()->frozen()->create();
        $frozen->givePermissionTo(wnPermission('dispute.handle'));
        $active = wnHolder('dispute.handle');

        wnRecord(StaffNoticeKind::WORK_DISPUTE, 'd-1', 'DH-2026-000001');

        expect(wnNotices($active))->toHaveCount(1)
            ->and(wnNotices($disabled))->toBeEmpty()
            ->and(wnNotices($frozen))->toBeEmpty()
            ->and(StaffNotice::query()->whereIn('staff_id', Staff::query()->where('is_system', true)->pluck('staff_id'))->count())->toBe(0);
    });

    it('groups unread events of one queue into one counting notice, then starts again after it is read', function () {
        $reviewer = wnHolder('identity.review');

        wnRecord(StaffNoticeKind::WORK_IDENTITY, 'doc-1');
        $first = wnNotices($reviewer)->sole();
        $this->travel(2)->minutes();
        wnRecord(StaffNoticeKind::WORK_IDENTITY, 'doc-2');
        wnRecord(StaffNoticeKind::WORK_IDENTITY, 'doc-3');

        $group = wnNotices($reviewer)->sole();
        expect($group->notice_id)->toBe($first->notice_id)
            ->and($group->item_count)->toBe(3)
            ->and($group->title)->toBe('3 new ID documents to review')
            ->and($group->body)->toBe('3 arrived since you last read this notice.')
            ->and($group->link)->toBe('/dashboard/users')
            ->and($group->params)->toBe(['count' => 3])
            ->and($group->created_at->greaterThan($first->created_at))->toBeTrue();

        app('auth')->forgetGuards();
        $this->withToken(staffAccessToken($reviewer))->postJson("/api/v1/dashboard/notices/{$group->notice_id}/read")->assertOk();

        $this->travel(1)->minutes();
        wnRecord(StaffNoticeKind::WORK_IDENTITY, 'doc-4');
        $rows = wnNotices($reviewer);
        expect($rows)->toHaveCount(2)
            ->and($rows->first()->item_count)->toBe(1)
            ->and($rows->first()->read_at)->toBeNull()
            ->and($group->fresh()->item_count)->toBe(3)
            ->and($group->fresh()->read_at)->not->toBeNull();
    });

    it('keeps queues apart, and links a group to the queue page', function () {
        $both = wnHolder('listing.review');
        $both->givePermissionTo(wnPermission('listing_report.handle'));

        wnRecord(StaffNoticeKind::WORK_LISTING, 'l-1');
        wnRecord(StaffNoticeKind::WORK_LISTING, 'l-2');
        wnRecord(StaffNoticeKind::WORK_LISTING_REPORT, '7', 'RPT-7');

        $listing = wnNotices($both, StaffNoticeKind::WORK_LISTING)->sole();
        $report = wnNotices($both, StaffNoticeKind::WORK_LISTING_REPORT)->sole();
        expect($listing->item_count)->toBe(2)
            ->and($listing->link)->toBe('/dashboard/listings')
            ->and($report->item_count)->toBe(1)
            ->and($report->link)->toBe('/dashboard/disputes?view=reports&rid=7')
            ->and($report->body)->toBe('Report RPT-7 is waiting to be handled.');
    });

    it('tells only staff assigned to the order\'s branch, grouped per branch', function () {
        $a = BuyRequests::branchOf(Orders::ring());
        $b = DB::table('branch')->where('branch_id', '!=', $a)->value('branch_id') ?? Branch::factory()->create()->branch_id;
        $atA = wnHolder('order.receive', $a);
        $atB = wnHolder('order.receive', $b);
        $headOffice = wnHolder('order.receive');

        wnRecord(StaffNoticeKind::WORK_ORDER, 'o-1', 'DH-2026-000001', $a);
        wnRecord(StaffNoticeKind::WORK_ORDER, 'o-2', 'DH-2026-000002', $b);

        expect(wnNotices($atA)->sole()->body)->toBe('Order DH-2026-000001 — the seller will bring the piece to your branch.')
            ->and(wnNotices($atA)->sole()->link)->toBe('/dashboard/orders?id=o-1')
            ->and(wnNotices($atB)->sole()->params)->toEqual(['count' => 1, 'ref' => 'DH-2026-000002', 'item_id' => 'o-2'])
            ->and(wnNotices($headOffice))->toBeEmpty();
    });

    it('writes nothing when the customer\'s change rolls back', function () {
        $reviewer = wnHolder('topup.match');

        try {
            DB::transaction(function () {
                WorkNotices::record(StaffNoticeKind::WORK_TOPUP, '1', 'DAHAB-000001');
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        expect(wnNotices($reviewer))->toBeEmpty();
    });

    it('shows the count on the bell API', function () {
        $reviewer = wnHolder('withdrawal.release');
        wnRecord(StaffNoticeKind::WORK_WITHDRAWAL, 'w-1', 'WD-1');
        wnRecord(StaffNoticeKind::WORK_WITHDRAWAL, 'w-2', 'WD-2');

        app('auth')->forgetGuards();
        $this->withToken(staffAccessToken($reviewer))->getJson('/api/v1/dashboard/notices')->assertOk()
            ->assertJsonPath('data.0.kind', 'work_withdrawal')
            ->assertJsonPath('data.0.count', 2)
            ->assertJsonPath('data.0.title', '2 new withdrawals to release')
            ->assertJsonPath('data.0.link', '/dashboard/withdrawals')
            ->assertJsonPath('meta.unread_count', 1);
    });
});

describe('founder alerts without a worker (D26-4)', function () {
    it('writes the Dashboard notice at once and leaves only the email on the queue', function () {
        // A real queue with no worker: jobs wait in the `jobs` table.
        config(['queue.default' => 'database']);
        $founder = Staff::factory()->role(SeedRole::CEO)->founder()->create();

        $founder->notify(new FounderSecurityNotification([
            'event' => 'founder.frozen', 'label' => 'Founder account frozen', 'actor' => 'Test', 'at' => '18 Oct 2026, 10:00',
            'ip' => null, 'device_ref' => null, 'platform' => null, 'entity_type' => null, 'audit_id' => null,
        ], '/dashboard/security'));

        $notice = wnNotices($founder)->sole();
        expect($notice->kind)->toBe('founder_alert')
            ->and($notice->severity)->toBe('critical')
            ->and($notice->group_key)->toBeNull()
            ->and($notice->item_count)->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(1); // the email, waiting for a worker
    });
});

describe('retention', function () {
    it('deletes read work notices after 30 days, never unread ones or founder alerts', function () {
        $staff = Staff::factory()->withRole()->create();
        $old = StaffNotice::factory()->create(['staff_id' => $staff->staff_id, 'kind' => 'work_topup', 'read_at' => now()->subDays(31)]);
        $recent = StaffNotice::factory()->create(['staff_id' => $staff->staff_id, 'kind' => 'work_topup', 'read_at' => now()->subDays(29)]);
        $unread = StaffNotice::factory()->create(['staff_id' => $staff->staff_id, 'kind' => 'work_topup', 'created_at' => now()->subDays(90)]);
        $founder = StaffNotice::factory()->create(['staff_id' => $staff->staff_id, 'kind' => 'founder_alert', 'read_at' => now()->subDays(400)]);

        $this->artisan('staff-notices:prune')->assertSuccessful();

        expect(StaffNotice::query()->find($old->notice_id))->toBeNull()
            ->and(StaffNotice::query()->find($recent->notice_id))->not->toBeNull()
            ->and(StaffNotice::query()->find($unread->notice_id))->not->toBeNull()
            ->and(StaffNotice::query()->find($founder->notice_id))->not->toBeNull();
    });
});

describe('the nine customer events', function () {
    it('E1: an ID document', function () {
        Queue::fake(); // no worker: the bell is written in the request
        Storage::fake('identity_private');
        $reviewer = wnHolder('identity.review');
        $customer = Customer::factory()->pendingVerification()->create();
        Sanctum::actingAs($customer, ['customer:access'], 'customer');
        $token = $this->post('/api/v1/customer/me/uploads', ['purpose' => 'identity', 'file' => UploadedFile::fake()->image('id.jpg', 600, 400)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.upload_token');

        $this->postJson('/api/v1/customer/me/identity-documents', ['doc_kind' => 'passport', 'front_upload_token' => $token])->assertCreated();

        expect(wnNotices($reviewer, StaffNoticeKind::WORK_IDENTITY)->sole()->link)->toBe('/dashboard/users');
    });

    it('E2: a top-up notice', function () {
        Queue::fake(); // no worker: the bell is written in the request
        $matcher = wnHolder('topup.match');
        $customer = Customer::factory()->verified()->create(['display_ref' => '004417']);
        $account = ReceivingAccount::factory()->instapay()->create();

        $this->withToken(TopUps::customerToken($customer))
            ->postJson('/api/v1/customer/me/wallet/topups', ['amount' => '20000', 'receiving_account_id' => $account->receiving_account_id], TopUps::key())
            ->assertCreated();

        $notice = wnNotices($matcher, StaffNoticeKind::WORK_TOPUP)->sole();
        expect($notice->body)->toBe('A customer says they sent a transfer with the reference DAHAB-004417. Match it when it arrives.')
            ->and($notice->body)->not->toContain('20000')
            ->and($notice->link)->toBe('/dashboard/transfers');
    });

    it('E3: a listing sent for review', function () {
        Queue::fake(); // no worker: the bell is written in the request
        $reviewer = wnHolder('listing.review');
        $seller = Customer::factory()->verified()->create();
        $draft = Listing::factory()->withPhotos(2)->create(['seller_id' => $seller->customer_id]);

        Listings::as($this, $seller)->postJson(Listings::SELLER_URL."/{$draft->listing_id}/submit", [], Listings::key())->assertOk();

        expect(wnNotices($reviewer, StaffNoticeKind::WORK_LISTING)->sole()->link)->toBe("/dashboard/listings?id={$draft->listing_id}");
    });

    it('E4: a new order at the branch', function () {
        $listing = Orders::ring();
        $branch = BuyRequests::branchOf($listing);
        $receiver = wnHolder('order.receive', $branch);
        $headOffice = wnHolder('order.receive');

        $order = Orders::accepted($this, $listing);

        $notice = wnNotices($receiver, StaffNoticeKind::WORK_ORDER)->sole();
        expect($notice->link)->toBe("/dashboard/orders?id={$order->order_id}")
            ->and($notice->body)->toContain($order->order_ref)
            ->and(wnNotices($headOffice))->toBeEmpty();
    });

    it('E5: a dispute', function () {
        $handler = wnHolder('dispute.handle');
        $order = Disputes::atInspection($this);

        Disputes::open($this, Orders::buyer($order), $order)->assertCreated();

        $dispute = Disputes::of($order);
        expect(wnNotices($handler, StaffNoticeKind::WORK_DISPUTE)->sole()->link)->toBe("/dashboard/disputes?id={$dispute->dispute_id}");
    });

    it('E6: a withdrawal', function () {
        Notification::fake(); // the email second-check link is read from the fake
        $releaser = wnHolder('withdrawal.release');

        $withdrawal = Withdrawals::requested($this, customer: Withdrawals::funded('56760', ['email' => 'mona.h@email.com']));

        $notice = wnNotices($releaser, StaffNoticeKind::WORK_WITHDRAWAL)->sole();
        expect($notice->link)->toBe("/dashboard/withdrawals?id={$withdrawal->withdrawal_id}")
            ->and($notice->body)->toBe('Withdrawal WD-'.$withdrawal->withdrawal_no.' is waiting to be released.');
    });

    it('E7: a payout account', function () {
        $checker = wnHolder('payout_account.verify');

        Withdrawals::add($this, Withdrawals::funded())->assertCreated();

        expect(wnNotices($checker, StaffNoticeKind::WORK_PAYOUT_ACCOUNT)->sole()->link)->toBe('/dashboard/withdrawals?tab=accounts');
    });

    it('E8: a request for more time, at the order\'s branch', function () {
        $listing = Orders::ring();
        $answerer = wnHolder('order.extend_deadline', BuyRequests::branchOf($listing));
        $order = Orders::accepted($this, $listing);

        Disputes::askMoreTime($this, $order)->assertCreated();

        expect(wnNotices($answerer, StaffNoticeKind::WORK_EXTENSION)->sole()->link)->toBe("/dashboard/orders?id={$order->order_id}");
    });

    it('E9: a listing report', function () {
        $handler = wnHolder('listing_report.handle');
        $piece = Orders::ring();
        $reporter = Account::customer();

        Account::post($this, Listings::token($reporter), '/listing-reports', ['listing_id' => $piece->listing_id, 'reason' => 'photos_not_genuine'])->assertCreated();

        expect(wnNotices($handler, StaffNoticeKind::WORK_LISTING_REPORT)->sole()->link)->toStartWith('/dashboard/disputes?view=reports&rid=');
    });
});
