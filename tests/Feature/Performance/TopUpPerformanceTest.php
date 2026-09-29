<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TopUps;

uses(RefreshDatabase::class)->group('perf');

// Spec 009 plan "Performance Goals" (task T068), opt-in: `php vendor/bin/pest
// --group=perf` (excluded from the default run in phpunit.xml). 100,000
// notices across 10,000 customers over the last 30 days; the Incoming
// transfers list answers in < 500 ms and a customer's own list in < 300 ms,
// using the (status, topup_no) and (customer_id, topup_no) indexes. The seed
// is bulk SQL inside the test's transaction (rolled back); no money moves.

function seedHundredThousandNotices(Staff $staff): Customer
{
    DB::statement("
        INSERT INTO customer (display_ref, phone, full_name, preferred_lang, status, is_verified, is_suspended)
        SELECT 't'||g, '+2018'||lpad(g::text, 8, '0'), 'Perf '||g, 'ar', 'active', true, false
        FROM generate_series(1, 10000) g
    ");
    $account = (int) DB::table('receiving_account')->insertGetId(
        ['method' => 'instapay', 'label' => 'Perf', 'instapay_address' => 'perf@instapay', 'updated_by' => $staff->staff_id],
        'receiving_account_id',
    );

    // 70% pending, 10% on hold, 10% rejected, 10% cancelled; newest last.
    DB::statement("
        WITH c AS (
            SELECT row_number() OVER () AS n, customer_id, display_ref FROM customer WHERE display_ref LIKE 't%'
        )
        INSERT INTO topup (customer_id, origin, method, reference, claimed_amount, notice_account_id, status, submitted_at,
                           hold_note, held_by, held_at, reject_reason, reject_note, rejected_by, rejected_at, cancelled_at)
        SELECT c.customer_id, 'notice', 'instapay', 'DAHAB-'||c.display_ref, (g % 50000) + 1, ?,
               s.status::topup_status, now() - ((100000 - g) * interval '25 seconds'),
               CASE WHEN s.status = 'on_hold' THEN 'checking' END,
               CASE WHEN s.status = 'on_hold' THEN ?::uuid END,
               CASE WHEN s.status = 'on_hold' THEN now() END,
               CASE WHEN s.status = 'rejected' THEN 'money_not_received'::topup_reject_reason END,
               CASE WHEN s.status = 'rejected' THEN 'nothing arrived' END,
               CASE WHEN s.status = 'rejected' THEN ?::uuid END,
               CASE WHEN s.status = 'rejected' THEN now() END,
               CASE WHEN s.status = 'cancelled' THEN now() END
        FROM generate_series(1, 100000) g
        JOIN c ON c.n = (g % 10000) + 1
        CROSS JOIN LATERAL (SELECT CASE g % 10 WHEN 0 THEN 'on_hold' WHEN 1 THEN 'rejected' WHEN 2 THEN 'cancelled' ELSE 'pending' END AS status) s
    ", [$account, $staff->staff_id, $staff->staff_id]);

    DB::statement('ANALYZE topup');
    DB::statement('ANALYZE customer');

    return Customer::query()->where('display_ref', 't42')->firstOrFail();
}

/** @return array{ms: float, status: int} */
function topUpTimed(Closure $call): array
{
    $start = hrtime(true);
    $status = $call()->status();

    return ['ms' => (hrtime(true) - $start) / 1e6, 'status' => $status];
}

it('lists 100k notices for staff and for one customer within the targets', function () {
    $finance = TopUps::actAsStaff($this, SeedRole::FINANCE);
    $customer = seedHundredThousandNotices($finance);

    expect(DB::table('topup')->count())->toBe(100000);

    // Warm up the app and the plan cache, then measure.
    $this->getJson('/api/v1/dashboard/topups')->assertOk();
    $default = topUpTimed(fn () => $this->getJson('/api/v1/dashboard/topups'));
    $search = topUpTimed(fn () => $this->getJson('/api/v1/dashboard/topups?q=DAHAB-t42'));
    $range = topUpTimed(fn () => $this->getJson('/api/v1/dashboard/topups?status=pending,on_hold,rejected,cancelled&from='.now('Africa/Cairo')->subDays(29)->toDateString()));

    $token = TopUps::customerToken($customer);
    $this->withToken($token)->getJson('/api/v1/customer/me/wallet/topups')->assertOk();
    $mine = topUpTimed(fn () => $this->withToken($token)->getJson('/api/v1/customer/me/wallet/topups'));

    fwrite(STDERR, sprintf("\n[perf] staff list %.0f ms · search %.0f ms · 30-day all %.0f ms · customer list %.0f ms\n", $default['ms'], $search['ms'], $range['ms'], $mine['ms']));

    expect([$default['status'], $search['status'], $range['status'], $mine['status']])->toBe([200, 200, 200, 200])
        ->and($default['ms'])->toBeLessThan(500)
        ->and($search['ms'])->toBeLessThan(500)
        ->and($range['ms'])->toBeLessThan(500)
        ->and($mine['ms'])->toBeLessThan(300);
});

it('uses the list indexes', function () {
    $staff = Staff::factory()->create();
    $customer = seedHundredThousandNotices($staff);

    $plan = fn (string $sql, array $b) => implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, DB::select('EXPLAIN '.$sql, $b)));

    // The busy default (70% of rows are pending) walks the topup_no order and
    // stops after one page; a selective status goes through the status index.
    $busyPlan = $plan("SELECT * FROM topup WHERE status IN ('pending','on_hold') AND submitted_at >= now() - interval '30 days' ORDER BY topup_no DESC LIMIT 26", []);
    $rarePlan = $plan("SELECT * FROM topup WHERE status IN ('credited') AND submitted_at >= now() - interval '30 days' ORDER BY topup_no DESC LIMIT 26", []);
    $customerPlan = $plan('SELECT * FROM topup WHERE customer_id = ? ORDER BY topup_no DESC LIMIT 21', [$customer->customer_id]);

    // Measured 2026-09-29: PostgreSQL walks the topup_no order for both staff
    // filters (one page, then stop); no staff list ever reads the whole table.
    expect($busyPlan)->toContain('Index Scan')->not->toContain('Seq Scan')
        ->and($rarePlan)->toContain('Index Scan')->not->toContain('Seq Scan')
        ->and($customerPlan)->toContain('idx_topup_customer_list');
});
