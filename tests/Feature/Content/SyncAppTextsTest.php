<?php

use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 019 T015, R1: `app-texts:sync` makes the registry match the app's manifest.

/** @param  list<array<string, mixed>>  $keys */
function syncManifest(array $keys, array $retired = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'texts');
    file_put_contents($path, json_encode(['version' => 1, 'keys' => $keys, 'retired' => $retired], JSON_UNESCAPED_UNICODE));
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

/** @return array<string, mixed> */
function syncEntry(string $key, string $en = 'Hello', string $ar = 'أهلا', array $extra = []): array
{
    return $extra + [
        'key' => $key, 'kind' => 'app', 'area' => 'Orders', 'where' => 'Order screen',
        'default_en' => $en, 'default_ar' => $ar, 'placeholders' => [], 'max_length' => 40,
    ];
}

it('adds the keys of the manifest with their defaults, area and placeholders', function () {
    $file = syncManifest([
        syncEntry('order.test.one'),
        syncEntry('order.test.two', '{hours} h left', 'فاضل {hours} ساعة', ['placeholders' => ['hours'], 'max_length' => 64]),
    ]);

    $this->artisan('app-texts:sync', ['--file' => $file])->assertSuccessful();

    $two = AppTextKey::query()->findOrFail('order.test.two');
    expect(AppTextKey::query()->count())->toBe(2)
        ->and($two->kind)->toBe('app')
        ->and($two->area)->toBe('Orders')
        ->and($two->description)->toBe('Order screen')
        ->and($two->default_en)->toBe('{hours} h left')
        ->and($two->default_ar)->toBe('فاضل {hours} ساعة')
        ->and($two->placeholders)->toBe(['hours'])
        ->and($two->required_placeholders)->toBe([])
        ->and($two->max_length)->toBe(64)
        ->and($two->read_only)->toBeFalse()
        ->and($two->retired_at)->toBeNull()
        ->and($two->default_changed_at)->toBeNull();
});

it('stamps default_changed_at only when a default changed', function () {
    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.one'), syncEntry('order.test.two')])])->assertSuccessful();
    expect(AppTextKey::query()->whereNotNull('default_changed_at')->count())->toBe(0);

    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.one'), syncEntry('order.test.two', 'Hello again')])])->assertSuccessful();

    expect(AppTextKey::query()->findOrFail('order.test.one')->default_changed_at)->toBeNull()
        ->and(AppTextKey::query()->findOrFail('order.test.two')->default_changed_at)->not->toBeNull()
        ->and(AppTextKey::query()->findOrFail('order.test.two')->default_en)->toBe('Hello again');
});

it('retires a key that left the manifest, never deletes it, and brings it back', function () {
    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.one'), syncEntry('order.test.two')])])->assertSuccessful();
    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.one')])])->assertSuccessful();

    expect(AppTextKey::query()->count())->toBe(2)
        ->and(AppTextKey::query()->findOrFail('order.test.two')->retired_at)->not->toBeNull()
        ->and(AppTextKey::query()->findOrFail('order.test.one')->retired_at)->toBeNull();

    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.one'), syncEntry('order.test.two')])])->assertSuccessful();

    expect(AppTextKey::query()->findOrFail('order.test.two')->retired_at)->toBeNull();
});

it('retires the keys the manifest lists as retired even if they are still declared', function () {
    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.one'), syncEntry('order.test.two')])])->assertSuccessful();
    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.one'), syncEntry('order.test.two')], ['order.test.two'])])->assertSuccessful();

    expect(AppTextKey::query()->findOrFail('order.test.two')->retired_at)->not->toBeNull();
});

it('leaves faq and notification keys alone, and every version, draft and published text', function () {
    $staff = Staff::factory()->create();
    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.one')])])->assertSuccessful();
    AppTextKey::query()->create([
        'text_key' => 'help.faq.how.question', 'kind' => 'faq', 'area' => 'Help', 'description' => 'FAQ',
        'default_en' => 'How?', 'default_ar' => 'ازاي؟', 'max_length' => 80,
    ]);
    AppTextVersion::query()->create([
        'text_key' => 'order.test.one', 'version' => 1, 'state' => 'draft', 'text_en' => 'Draft', 'text_ar' => 'مسودة',
        'edited_by' => $staff->staff_id, 'edited_at' => now(),
    ]);

    // The default moves and the key leaves: the draft stays, the faq key is not retired.
    $this->artisan('app-texts:sync', ['--file' => syncManifest([syncEntry('order.test.zero')])])->assertSuccessful();

    expect(AppTextKey::query()->findOrFail('help.faq.how.question')->retired_at)->toBeNull()
        ->and(AppTextKey::query()->findOrFail('order.test.one')->retired_at)->not->toBeNull()
        ->and(AppTextVersion::query()->where('text_key', 'order.test.one')->sole()->text_en)->toBe('Draft');
});

it('fails clearly on a missing or broken manifest', function () {
    $this->artisan('app-texts:sync', ['--file' => '/no/such/file.json'])->assertFailed();

    $broken = tempnam(sys_get_temp_dir(), 'texts');
    file_put_contents($broken, '{not json');
    $this->artisan('app-texts:sync', ['--file' => $broken])->assertFailed();
    @unlink($broken);

    expect(AppTextKey::query()->count())->toBe(0);
});

it('registers the manifest the app ships with, every key readable and unique', function () {
    $manifest = json_decode((string) file_get_contents(base_path('database/data/app_text_keys.json')), true);

    $this->artisan('app-texts:sync')->assertSuccessful();

    $keys = AppTextKey::query()->pluck('text_key')->all();
    expect($keys)->toHaveCount(count($manifest['keys']))
        ->and($keys)->toContain('order.free_relist.card_title', 'order.rating.send_button', 'order.no_fee.seller_note');
    foreach ($keys as $key) {
        expect($key)->toMatch('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,5}$/');
    }

    // Spec 018 FR-038: no invite or referral text in the Stage 1 catalogue.
    expect(AppTextKey::query()->where('default_en', 'ilike', '%invite%')->orWhere('default_en', 'ilike', '%referral%')->count())->toBe(0);

    // A second run changes nothing.
    $this->artisan('app-texts:sync')->assertSuccessful();
    expect(AppTextKey::query()->whereNotNull('default_changed_at')->count())->toBe(0);
});
