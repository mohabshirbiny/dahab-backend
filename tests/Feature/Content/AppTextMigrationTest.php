<?php

use App\Models\AppTextBundle;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AppTexts;

uses(RefreshDatabase::class);

// Spec 019 FR-104, R16: a rollback refuses while it would destroy published history.

function appContentMigration(): object
{
    return require base_path('database/migrations/2026_10_12_000010_app_content.php');
}

it('has the tables, the guard triggers and the two partial unique indexes', function () {
    expect(Schema::hasTable('app_text_key'))->toBeTrue()
        ->and(Schema::hasTable('app_text_version'))->toBeTrue()
        ->and(Schema::hasTable('app_text_bundle'))->toBeTrue();

    $triggers = DB::table('pg_trigger')->where('tgisinternal', false)
        ->whereIn('tgname', ['trg_app_text_version_guard', 'trg_app_text_bundle_immutable'])->pluck('tgname')->sort()->values()->all();
    $indexes = DB::table('pg_indexes')->where('tablename', 'app_text_version')->pluck('indexname')->all();

    expect($triggers)->toBe(['trg_app_text_bundle_immutable', 'trg_app_text_version_guard'])
        ->and($indexes)->toContain('uq_app_text_one_draft', 'uq_app_text_one_published');
});

it('rolls back on an empty registry, even with drafts', function () {
    $staff = Staff::factory()->create();
    AppTexts::key('order.test.a');
    DB::table('app_text_version')->insert([
        'text_key' => 'order.test.a', 'version' => 1, 'state' => 'draft', 'text_en' => 'x', 'text_ar' => 'y',
        'edited_by' => $staff->staff_id, 'edited_at' => now(),
    ]);

    appContentMigration()->down();

    expect(Schema::hasTable('app_text_version'))->toBeFalse()
        ->and(Schema::hasTable('app_text_key'))->toBeFalse()
        ->and(Schema::hasTable('app_text_bundle'))->toBeFalse();

    // And it can be put back.
    appContentMigration()->up();
    expect(Schema::hasTable('app_text_key'))->toBeTrue();
});

it('refuses to roll back while a published version exists', function () {
    $staff = Staff::factory()->create();
    AppTexts::key('order.test.a');
    $bundle = AppTextBundle::query()->create(['bundle_version' => 1, 'published_by' => $staff->staff_id, 'key_count' => 1, 'kinds' => ['app']]);
    DB::table('app_text_version')->insert([
        'text_key' => 'order.test.a', 'version' => 1, 'state' => 'draft', 'text_en' => 'x', 'text_ar' => 'y',
        'edited_by' => $staff->staff_id, 'edited_at' => now(),
    ]);
    DB::table('app_text_version')->where('text_key', 'order.test.a')->update([
        'state' => 'published', 'published_by' => $staff->staff_id, 'published_at' => now(), 'bundle_version' => $bundle->bundle_version,
    ]);

    expect(fn () => appContentMigration()->down())->toThrow(RuntimeException::class, 'cannot be rolled back')
        ->and(Schema::hasTable('app_text_version'))->toBeTrue()
        ->and(DB::table('app_text_version')->where('state', 'published')->count())->toBe(1);
});
