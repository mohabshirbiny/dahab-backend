<?php

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Models\AccountFreeze;
use App\Models\Customer;
use App\Models\GoldPrice;
use App\Models\Staff;
use App\Models\StaffNotice;
use App\Models\StaffRoleModel;
use App\Notifications\FounderSecurityNotification;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Finance;

uses(RefreshDatabase::class);

// Spec 021 Story 3 (T053, research R11): both founders are told — email and a Dashboard notice,
// SMS too for a freeze — of the Story 3 actions done on a founder account and of every founder
// security event. A notice never carries the secret itself.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    config(['services.gold_feed' => ['base_url' => null, 'username' => null, 'password' => null, 'timeout' => 10]]);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create(['full_name' => 'Founder CEO']);
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->create(['full_name' => 'Founder COO']);
});

function faAs($test, Staff $staff)
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($staff))->withHeaders(['Idempotency-Key' => (string) Str::uuid()]);
}

/** What a founder would read: the mail lines and the notice, as one string. */
function faRendered(FounderSecurityNotification $n, Staff $to): string
{
    $mail = $n->toMail($to);

    return json_encode([$mail->subject, $mail->introLines, $mail->outroLines, $mail->actionUrl, $n->toStaffNotice($to), $n->toSms($to)->content]);
}

/**
 * Each Story 3 action done by the CEO (a founder) through its real endpoint. Returns the values
 * that must never appear in what the founders are sent.
 *
 * @return array{event: string, secrets: list<string>}
 */
function faDo($test, string $case): array
{
    $ceo = $test->ceo;

    return match ($case) {
        'wallet adjusted' => (function () use ($test, $ceo) {
            $customer = Finance::customer('1000');
            faAs($test, $ceo)->postJson("/api/v1/dashboard/customers/{$customer->customer_id}/wallet-adjustments",
                ['direction' => 'credit', 'amount' => '777.25', 'reason' => 'Correcting a top-up entered twice.'])->assertCreated();

            return ['event' => 'wallet.adjusted', 'secrets' => ['777.25', '777', 'Correcting a top-up']];
        })(),
        'setting changed' => (function () use ($test, $ceo) {
            faAs($test, $ceo)->patchJson('/api/v1/dashboard/settings/commission.gold_pct', ['value' => '17.5', 'reason' => 'Autumn promotion'])->assertOk();

            return ['event' => 'pricing.setting.changed', 'secrets' => ['17.5', 'Autumn promotion']];
        })(),
        'adjustment changed' => (function () use ($test, $ceo) {
            GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id()]);
            faAs($test, $ceo)->putJson('/api/v1/dashboard/karats/21/adjustments', [
                'buy' => ['kind' => 'percent', 'value' => '-1.25'], 'sell' => ['kind' => 'fixed', 'value' => '13'], 'reason' => 'Market moved',
            ])->assertOk();

            return ['event' => 'pricing.adjustment.changed', 'secrets' => ['-1.25', '1.25', 'Market moved']];
        })(),
        'promo created', 'promo updated' => (function () use ($test, $ceo, $case) {
            $dealer = Customer::factory()->verified()->create(['customer_type' => 'market_maker']);
            faAs($test, $ceo)->postJson('/api/v1/dashboard/promo-codes', ['code' => 'GOLDDEALER', 'tied_customer_id' => $dealer->customer_id, 'monthly_cap_egp' => 432100])->assertCreated();
            if ($case === 'promo updated') {
                faAs($test, $ceo)->patchJson('/api/v1/dashboard/promo-codes/GOLDDEALER', ['active' => false, 'monthly_cap_egp' => 123400])->assertOk();
            }

            return ['event' => $case === 'promo created' ? 'promo.created' : 'promo.updated', 'secrets' => ['432100', '123400', 'GOLDDEALER']];
        })(),
        'manual price entered' => (function () use ($test, $ceo) {
            faAs($test, $ceo)->postJson('/api/v1/dashboard/gold-prices/manual', ['bid_24k' => '7911', 'ask_24k' => '7955', 'reason' => 'Feed down since 11:00, price by phone'])->assertSuccessful();

            return ['event' => 'pricing.manual_price.entered', 'secrets' => ['7911', '7955']];
        })(),
        'manual price confirmed' => (function () use ($test, $ceo) {
            GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id(), 'effective_at' => now()->subHour()]);
            $finance = Staff::factory()->role(SeedRole::FINANCE)->create();
            $id = faAs($test, $finance)->postJson('/api/v1/dashboard/gold-prices/manual', ['bid_24k' => '9511', 'ask_24k' => '9555', 'reason' => 'Feed down, big move by phone'])
                ->assertSuccessful()->json('data.id');
            faAs($test, $ceo)->postJson("/api/v1/dashboard/gold-prices/manual/{$id}/confirm")->assertOk();

            return ['event' => 'pricing.manual_price.confirmed', 'secrets' => ['9511', '9555']];
        })(),
        'staff created' => (function () use ($test, $ceo) {
            faAs($test, $ceo)->postJson('/api/v1/dashboard/staff', [
                'full_name' => 'Amr Salah', 'email' => 'amr@dahab.test', 'phone' => '+201000000009', 'roles' => ['operations'], 'reason' => 'New hire, Cairo branch',
            ])->assertCreated();

            return ['event' => 'authz.staff.created', 'secrets' => ['New hire', 'token=']];
        })(),
        'staff disabled' => (function () use ($test, $ceo) {
            $member = Staff::factory()->role(SeedRole::OPERATIONS)->create();
            faAs($test, $ceo)->postJson("/api/v1/dashboard/staff/{$member->staff_id}/disable", ['reason' => 'Left the company'])->assertOk();

            return ['event' => 'authz.staff.disabled', 'secrets' => ['Left the company']];
        })(),
    };
}

it('tells every active founder, the frozen one too, of a Story 3 action done by a founder, without the secret', function (string $case) {
    Notification::fake();
    AccountFreeze::factory()->create(['frozen_staff_id' => $this->coo->staff_id, 'frozen_by' => $this->ceo->staff_id]);
    Notification::fake(); // forget the freeze's own alert

    $done = faDo($this, $case);

    foreach ([$this->ceo, $this->coo] as $founder) {
        Notification::assertSentTo($founder, FounderSecurityNotification::class, function (FounderSecurityNotification $n, array $channels) use ($done, $founder) {
            if ($n->facts['event'] !== $done['event']) {
                return false;
            }
            $rendered = faRendered($n, $founder);
            foreach ($done['secrets'] as $secret) {
                expect($rendered)->not->toContain($secret);
            }

            return $channels === ['mail', 'App\\Notifications\\Channels\\StaffNoticeChannel']
                && $n->facts['actor'] === 'Founder CEO'
                && str_starts_with($n->link, '/dashboard/audit?entry=');
        });
    }
})->with(['wallet adjusted', 'setting changed', 'adjustment changed', 'promo created', 'promo updated',
    'manual price entered', 'manual price confirmed', 'staff created', 'staff disabled']);

it('stays quiet when the actor is not a founder', function () {
    Notification::fake();
    $finance = Staff::factory()->role(SeedRole::FINANCE)->create();

    faAs($this, $finance)->patchJson('/api/v1/dashboard/settings/commission.gold_pct', ['value' => '17.5', 'reason' => 'Autumn promotion'])->assertOk();

    Notification::assertNotSentTo([$this->ceo, $this->coo], FounderSecurityNotification::class);
});

it('always tells of a founder security event, whoever the actor', function () {
    Notification::fake();
    $freeze = AccountFreeze::factory()->create(['frozen_staff_id' => $this->coo->staff_id, 'frozen_by' => $this->ceo->staff_id]);
    $role = StaffRoleModel::query()->firstOrCreate(['name' => 'stand_in', 'guard_name' => Staff::GUARD], ['display_name' => 'Stand-in', 'requires_mfa' => false]);
    $role->givePermissionTo(StaffPermission::FOUNDER_UNFREEZE_SECOND->value);
    $standIn = Staff::factory()->withRole('stand_in')->create(['full_name' => 'Stand In']);

    faAs($this, $standIn)->postJson('/api/v1/dashboard/founder/unfreeze', ['freeze_id' => $freeze->freeze_id])->assertOk();

    Notification::assertSentTo([$this->ceo, $this->coo], FounderSecurityNotification::class,
        fn (FounderSecurityNotification $n, array $channels) => $n->facts['event'] === 'founder.unfreeze_confirmed'
            && $n->facts['actor'] === 'Stand In' && ! in_array('sms', $channels, true));
});

it('adds SMS for a freeze only, and skips it with a log line for a founder without a phone', function () {
    Notification::fake();
    Log::spy();
    $this->ceo->forceFill(['phone' => null])->save();

    faAs($this, $this->ceo)->postJson('/api/v1/dashboard/founder/freeze', ['staff_id' => $this->coo->staff_id, 'reason' => 'Lost phone, possible compromise'])->assertCreated();

    Notification::assertSentTo([$this->ceo, $this->coo], FounderSecurityNotification::class,
        fn (FounderSecurityNotification $n, array $channels) => $n->facts['event'] === 'founder.frozen' && in_array('sms', $channels, true)
            && ! str_contains(faRendered($n, $this->coo), 'Lost phone'));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'founder_alert.sms_skipped'
        && $context['staff_id'] === $this->ceo->staff_id)->once();
});

it('sends nothing when the action rolls back', function () {
    Notification::fake();

    try {
        DB::transaction(function () {
            app(RecordAuditLogAction::class)->execute(AuditEvent::STAFF_DISABLED, 'success', [], 'staff', $this->coo->staff_id, actorStaffId: $this->ceo->staff_id);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Notification::assertNothingSent();
});

it('writes a Dashboard notice for every founder through the real channel, with no secret', function () {
    $member = Staff::factory()->role(SeedRole::OPERATIONS)->create();

    faAs($this, $this->ceo)->postJson("/api/v1/dashboard/staff/{$member->staff_id}/disable", ['reason' => 'Left the company'])->assertOk();

    $notices = StaffNotice::query()->where('kind', 'founder_alert')->get();
    expect($notices->pluck('staff_id')->sort()->values()->all())->toBe(collect([$this->ceo->staff_id, $this->coo->staff_id])->sort()->values()->all())
        ->and($notices->first()->title)->toBe('Staff member disabled')
        ->and($notices->first()->audit_id)->not->toBeNull()
        ->and($notices->first()->link)->toBe('/dashboard/audit?entry='.$notices->first()->audit_id)
        ->and(json_encode($notices->toArray()))->not->toContain('Left the company');
});
