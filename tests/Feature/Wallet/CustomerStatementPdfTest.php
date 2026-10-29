<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Support\DatabaseActor;
use App\Support\Wallet\StatementPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Wallet statement PDF (item 10): GET /customer/me/wallet/statement.pdf — the caller's own rows only,
// the same figures as /customer/me/wallet/transactions, audited, validated.

const STATEMENT_URL = '/api/v1/customer/me/wallet/statement.pdf';

/** Records what would be printed instead of running mPDF (one test renders for real). */
class RecordingStatementRenderer extends StatementPdfRenderer
{
    /** @var list<array<string, mixed>> */
    public array $printed = [];

    public function render(array $statement, ?array $issuer): string
    {
        $this->printed[] = $statement;

        return '%PDF-1.4 fake';
    }
}

function statementToken(Customer $customer): string
{
    return app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 12:00:00');
    $this->mona = Customer::factory()->verified()->create(['full_name' => 'Mona Real Name']);
    $this->omar = Customer::factory()->verified()->create(['full_name' => 'Omar Other']);
    $bank = Account::internal(AccountKind::BANK);

    // Before the period: the opening balance.
    Ledger::postAt('2026-08-01 10:00', LedgerEventKind::TOPUP, $this->mona, [[$bank, '-1000'], [Ledger::available($this->mona), '1000']]);
    // In the period.
    Ledger::postAt('2026-09-10 10:00', LedgerEventKind::TOPUP, $this->mona, [[$bank, '-500'], [Ledger::available($this->mona), '500']]);
    Ledger::postAt('2026-09-12 10:00', LedgerEventKind::DEPOSIT_HOLD, $this->mona, [[Ledger::available($this->mona), '-300'], [Ledger::held($this->mona), '300']]);
    // Someone else's money, the same days.
    Ledger::postAt('2026-09-11 10:00', LedgerEventKind::TOPUP, $this->omar, [[$bank, '-777'], [Ledger::available($this->omar), '777']]);

    $this->renderer = new RecordingStatementRenderer;
    app()->instance(StatementPdfRenderer::class, $this->renderer);
});

afterEach(fn () => Carbon::setTestNow());

it('sends the caller\'s own statement as a PDF with their real name and balances', function () {
    $res = $this->bearer(statementToken($this->mona))->get(STATEMENT_URL.'?from=2026-09-01&to=2026-09-30')->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($res->headers->get('Content-Disposition'))->toContain('dahab-statement-2026-09-01-to-2026-09-30.pdf')
        ->and($res->getContent())->toStartWith('%PDF');

    $s = $this->renderer->printed[0];
    expect($s['customer'])->toBe(['full_name' => 'Mona Real Name', 'display_ref' => (string) $this->mona->display_ref])
        ->and($s['opening'])->toBe(['available' => '1000.0000', 'held' => '0.0000'])
        ->and($s['closing'])->toBe(['available' => '1200.0000', 'held' => '300.0000'])
        ->and($s['money_in'])->toBe('500.0000')
        ->and($s['money_out'])->toBe('300.0000')
        ->and(collect($s['rows'])->map(fn ($r) => [$r['kind'], $r['label_en'], $r['amount'], $r['available_after']])->all())->toBe([
            ['deposit_hold', 'Deposit held on a purchase', '-300.0000', '1200.0000'],
            ['topup', 'Added by bank transfer', '500.0000', '1500.0000'],
        ])
        ->and($s['rows'][1]['label_ar'])->toBe('شحن بتحويل بنكي');
});

it('never shows another customer\'s rows', function () {
    $this->bearer(statementToken($this->mona))->get(STATEMENT_URL.'?from=2026-09-01&to=2026-09-30')->assertOk();
    $this->bearer(statementToken($this->omar))->get(STATEMENT_URL.'?from=2026-09-01&to=2026-09-30')->assertOk();

    [$mine, $theirs] = $this->renderer->printed;
    expect(collect($mine['rows'])->pluck('amount')->all())->not->toContain('777.0000')
        ->and(collect($theirs['rows'])->pluck('amount')->all())->toBe(['777.0000'])
        ->and($theirs['customer']['full_name'])->toBe('Omar Other');
});

it('agrees with the transactions endpoint row for row', function () {
    $token = statementToken($this->mona);
    $this->bearer($token)->get(STATEMENT_URL.'?from=2026-08-01&to=2026-10-14')->assertOk();
    $history = $this->bearer($token)->getJson('/api/v1/customer/me/wallet/transactions')->assertOk()->json('data');

    $pdfRows = $this->renderer->printed[0]['rows'];
    expect(array_map(fn ($r) => [$r['id'], $r['available_change'], $r['available_after'], $r['held_after']], $pdfRows))
        ->toBe(array_map(fn ($r) => [$r['id'], $r['available_change'], $r['available_after'], $r['held_after']], $history));
});

it('defaults to the last 90 days and audits the download', function () {
    $this->bearer(statementToken($this->mona))->get(STATEMENT_URL)->assertOk();

    $s = $this->renderer->printed[0];
    expect([$s['from'], $s['to']])->toBe(['2026-07-17', '2026-10-14']);

    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'wallet.statement.downloaded')->sole());
    expect($audit->actor_customer_id)->toBe($this->mona->customer_id)
        ->and($audit->after_json)->toMatchArray(['from' => '2026-07-17', 'to' => '2026-10-14', 'rows' => 3]);
});

it('refuses a customer who is not verified yet', function () {
    $pending = Customer::factory()->pendingVerification()->create();

    $this->bearer(statementToken($pending))->get(STATEMENT_URL, ['Accept' => 'application/json'])
        ->assertForbidden()->assertJsonPath('code', 'verification_required');
});

it('validates the period', function (array $query) {
    $this->bearer(statementToken($this->mona))->getJson(STATEMENT_URL.'?'.http_build_query($query))
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
})->with([
    'in the future' => [['to' => '2026-10-15']],
    'end before start' => [['from' => '2026-09-10', 'to' => '2026-09-01']],
    'more than 366 days' => [['from' => '2025-01-01', 'to' => '2026-10-01']],
    'not a date' => [['from' => 'last-week']],
]);

it('renders a real bilingual PDF', function () {
    app()->forgetInstance(StatementPdfRenderer::class);

    $res = $this->bearer(statementToken($this->mona))->get(STATEMENT_URL.'?from=2026-09-01&to=2026-09-30')->assertOk();

    expect($res->getContent())->toStartWith('%PDF')
        ->and(strlen($res->getContent()))->toBeGreaterThan(5000);
});
