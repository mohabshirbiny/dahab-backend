<?php

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Wallet\BuildWalletStatementAction;
use App\Actions\Wallet\ExportWalletStatementAction;
use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\LedgerEventKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\Ledger\StatementQuery;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 FR-016 / FR-019: the statement as CSV, audited on every view.

function exportStatement($test, Staff $staff, array $query)
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($staff))->get('/api/v1/dashboard/wallet-statement/export?'.http_build_query($query));
}

/** @return list<list<string>> */
function statementCsv(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

    return array_map(fn ($line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", $csv), fn ($l) => $l !== '')));
}

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create();
    $this->mona = Customer::factory()->verified()->create(['full_name' => 'منى حسن']);
    $bank = Account::internal(AccountKind::BANK);
    Ledger::postAt('2026-08-02 10:00', LedgerEventKind::TOPUP, $this->mona, [[$bank, '-1000'], [Ledger::available($this->mona), '1000']]);
    Ledger::postAt('2026-08-03 10:00', LedgerEventKind::DEPOSIT_HOLD, $this->mona, [[Ledger::available($this->mona), '-400'], [Ledger::held($this->mona), '400']]);
    $this->query = ['view' => 'customer', 'customer_id' => $this->mona->customer_id, 'from' => '2026-08-01', 'to' => '2026-08-31'];
});

it('exports the same figures as the statement, with a BOM', function () {
    $response = exportStatement($this, $this->finance, $this->query)->assertOk();
    $json = $this->withToken(staffAccessToken($this->finance))->getJson('/api/v1/dashboard/wallet-statement?'.http_build_query($this->query))->json('data');

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('X-Export-Truncated'))->toBe('false')
        ->and(str_starts_with($response->getContent(), "\xEF\xBB\xBF"))->toBeTrue();

    $rows = statementCsv($response->getContent());

    expect($rows[0][1])->toContain('منى حسن')
        ->and($rows[1])->toBe(['Opening', $json['summary']['opening'], 'In', $json['summary']['in'], 'Out', $json['summary']['out'], 'Closing', $json['summary']['closing']])
        ->and($rows[2][0])->toBe('When')
        ->and(array_slice($rows, 3))->toHaveCount(2)
        ->and(array_map(fn ($r) => array_slice($r, 7), array_slice($rows, 3)))
        ->toBe(array_map(fn ($r) => [$r['before'], $r['in'], $r['out'], $r['after']], $json['rows']));
});

it('exports a grain by period', function () {
    $rows = statementCsv(exportStatement($this, $this->finance, $this->query + ['grain' => 'month'])->assertOk()->getContent());

    expect($rows[2])->toBe(['Period', 'Movements', 'Before', 'In', 'Out', 'After'])
        ->and($rows[3])->toBe(['2026-08', '2', '0.0000', '1000.0000', '400.0000', '600.0000']);
});

it('audits every export, whatever the view', function () {
    exportStatement($this, $this->finance, $this->query)->assertOk();
    exportStatement($this, $this->finance, ['view' => 'customers', 'from' => '2026-08-01', 'to' => '2026-08-31'])->assertOk();
    exportStatement($this, $this->finance, ['view' => 'dahab', 'from' => '2026-08-01', 'to' => '2026-08-31'])->assertOk();

    $audits = AuditLog::query()->where('action', AuditEvent::LEDGER_STATEMENT_EXPORTED->value)->orderBy('audit_id')->get();

    expect($audits)->toHaveCount(3)
        ->and($audits->pluck('after_json.view')->all())->toBe(['customer', 'customers', 'dahab'])
        ->and($audits[0]->entity_id)->toBe($this->mona->customer_id)
        ->and($audits->every(fn ($a) => $a->actor_staff_id === $this->finance->staff_id))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditEvent::LEDGER_STATEMENT_VIEWED->value)->exists())->toBeFalse();
});

it('stops at the cap with a closing note', function () {
    $export = new ExportWalletStatementAction(
        app(BuildWalletStatementAction::class),
        app(RecordAuditLogAction::class),
        cap: 1,
    );

    $result = $export->handle($this->finance, new StatementQuery('customer', '2026-08-01', '2026-08-31', 'each', $this->mona->customer_id));
    $rows = statementCsv($result['csv']);

    expect($result)->toMatchArray(['rows' => 1, 'truncated' => true])
        ->and(end($rows)[0])->toBe('Export stopped at 1 rows. Narrow the period to get the rest.')
        ->and(AuditLog::query()->where('action', AuditEvent::LEDGER_STATEMENT_EXPORTED->value)->sole()->after_json['truncated'])->toBeTrue();
});
