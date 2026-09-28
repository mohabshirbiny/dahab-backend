<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AuditLogTestHelpers.php';

uses(RefreshDatabase::class);

// Spec 006 FR-009 / FR-011: CSV export under the same rules, recorded once.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create(['full_name' => 'أحمد CEO']);
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create();
});

function exportCsv($test, Staff $staff, string $query = '')
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($staff))->get('/api/v1/dashboard/audit-log/export'.($query ? '?'.$query : ''));
}

/** @return list<list<string>> */
function csvRows(string $csv): array
{
    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");

    return array_map(fn ($line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
}

it('downloads every matching entry as CSV with a BOM, Arabic intact, and records the export', function () {
    foreach (range(1, 3) as $i) {
        auditRow('pricing.setting.changed', ['actor_staff_id' => $this->ceo->staff_id, 'reason' => 'سبب '.$i,
            'before_json' => ['key' => 'vat.pct', 'old' => '14'], 'after_json' => ['outcome' => 'success', 'key' => 'vat.pct', 'new' => '15']]);
    }
    auditRow('reference.karat.toggled', ['actor_staff_id' => $this->ceo->staff_id]);

    $response = exportCsv($this, $this->ceo, 'category=pricing')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Export-Truncated', 'false');
    expect($response->headers->get('Content-Disposition'))->toMatch('/attachment; filename="audit-log-\d{8}-\d{4}\.csv"/');

    $rows = csvRows($response->getContent());
    expect($rows[0])->toBe(['When', 'Who', 'What', 'Subject', 'Before', 'After', 'Reason', 'IP', 'Device fingerprint'])
        ->and(count($rows))->toBe(4)
        ->and($rows[1][1])->toBe('أحمد CEO')
        ->and($rows[1][2])->toBe('Setting changed')
        ->and($rows[1][4])->toBe('14')
        ->and($rows[1][6])->toStartWith('سبب');

    $record = AuditLog::query()->where('action', 'audit.log.exported')->sole();
    expect($record->actor_staff_id)->toBe($this->ceo->staff_id)
        ->and($record->after_json['rows'])->toBe(3)
        ->and($record->after_json['truncated'])->toBeFalse()
        ->and($record->after_json['filters']['category'])->toBe('pricing');
});

it('exports only your own actions without view_all', function () {
    auditRow('pricing.setting.changed', ['actor_staff_id' => $this->ceo->staff_id]);
    auditRow('pricing.setting.changed', ['actor_staff_id' => $this->finance->staff_id]);

    expect(count(csvRows(exportCsv($this, $this->finance)->assertOk()->getContent())))->toBe(2); // header + 1
});

it('neutralises cells Excel would run as formulas', function () {
    auditRow('pricing.setting.changed', ['actor_staff_id' => $this->ceo->staff_id, 'reason' => '=HYPERLINK("http://evil")']);

    expect(csvRows(exportCsv($this, $this->ceo)->getContent())[1][6])->toBe("'=HYPERLINK(\"http://evil\")");
});

it('stops at the cap, says so, and records it', function () {
    config(['dahab-audit.export_max_rows' => 2]);
    foreach (range(1, 5) as $i) {
        auditRow('pricing.setting.changed', ['actor_staff_id' => $this->ceo->staff_id, 'created_at' => now()->subSeconds($i)]);
    }

    $response = exportCsv($this, $this->ceo, 'category=pricing')->assertHeader('X-Export-Truncated', 'true');
    $rows = csvRows($response->getContent());

    expect(count($rows))->toBe(4) // header + 2 + the note
        ->and($rows[3][0])->toContain('Export stopped at 2 entries')
        ->and(AuditLog::query()->where('action', 'audit.log.exported')->sole()->after_json['truncated'])->toBeTrue();
});

it('is refused without an audit permission', function () {
    exportCsv($this, Staff::factory()->role(SeedRole::IGI_BRANCH)->create())->assertForbidden();
});
