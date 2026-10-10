<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AppTexts;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 FR-011: Export lists key, kind, area, English, Arabic, version and published date.

beforeEach(function () {
    $this->ops = TopUps::actAsStaff($this, SeedRole::OPERATIONS);
});

/** @return list<list<string>> */
function exportRows(string $csv): array
{
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();

    $lines = array_values(array_filter(preg_split('/\r?\n/', substr($csv, 3)) ?: []));

    return array_map(fn (string $l) => str_getcsv($l, escape: ''), $lines);
}

it('exports every key with what customers see, the version and the date', function () {
    AppTexts::key('order.test.a');
    AppTexts::key('order.test.b', ['default_en' => 'Second', 'default_ar' => 'التاني', 'area' => 'Wallet']);
    AppTexts::override($this, 'order.test.a', 'Override', 'تعديل');

    $res = $this->get(AppTexts::URL.'/export')->assertOk();
    $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($res->headers->get('Content-Disposition'))->toContain('attachment; filename="app-texts-');

    $rows = exportRows($res->getContent());
    expect($rows[0])->toBe(['Key', 'Kind', 'Area', 'English', 'Arabic', 'Version', 'Published at'])
        ->and($rows[1][0])->toBe('order.test.a')
        ->and($rows[1][3])->toBe('Override')
        ->and($rows[1][4])->toBe('تعديل')
        ->and($rows[1][5])->toBe('1')
        ->and($rows[1][6])->not->toBe('')
        ->and($rows[2])->toBe(['order.test.b', 'app', 'Wallet', 'Second', 'التاني', '', '']);
});

it('applies the list filters and neutralises formulas', function () {
    AppTexts::key('order.test.a', ['default_en' => '=SUM(A1)', 'default_ar' => 'ب']);
    AppTexts::key('wallet.test.b', ['area' => 'Wallet']);

    $rows = exportRows($this->get(AppTexts::URL.'/export?area=Orders')->assertOk()->getContent());

    expect($rows)->toHaveCount(2)->and($rows[1][3])->toBe("'=SUM(A1)");
});

it('audits every export', function () {
    AppTexts::key('order.test.a');

    $this->get(AppTexts::URL.'/export?area=Orders')->assertOk();

    $audit = AuditLog::query()->where('action', 'content.exported')->sole();
    expect($audit->actor_staff_id)->toBe($this->ops->staff_id)
        ->and($audit->after_json['rows'])->toBe(1)
        ->and($audit->after_json['filters'])->toBe(['area' => 'Orders']);
});
