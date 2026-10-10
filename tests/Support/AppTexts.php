<?php

namespace Tests\Support;

use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fixtures for the spec 019 app-text tests: registry rows, and the staff calls that save,
 * revert and publish them through the HTTP boundary.
 */
final class AppTexts
{
    public const URL = '/api/v1/dashboard/app-texts';

    public const BUNDLE_URL = '/api/v1/reference/app-texts';

    /** @param  array<string, mixed>  $overrides */
    public static function key(string $key = 'order.test.title', array $overrides = []): AppTextKey
    {
        return AppTextKey::query()->create(array_merge([
            'text_key' => $key,
            'kind' => AppTextKey::KIND_APP,
            'area' => 'Orders',
            'description' => 'Order screen, a test title',
            'default_en' => 'Default title',
            'default_ar' => 'العنوان الافتراضي',
            'placeholders' => [],
            'max_length' => 80,
        ], $overrides))->refresh();
    }

    /** @param  array<string, mixed>  $body */
    public static function save(TestCase $test, string $key, array $body = [], ?string $idempotencyKey = null): TestResponse
    {
        return $test->putJson(
            self::URL.'/'.$key.'/draft',
            $body + ['text_en' => 'New title', 'text_ar' => 'عنوان جديد'],
            ['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid()],
        );
    }

    public static function discard(TestCase $test, string $key): TestResponse
    {
        return $test->deleteJson(self::URL.'/'.$key.'/draft', [], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public static function revert(TestCase $test, string $key, string|int $to): TestResponse
    {
        return $test->postJson(self::URL.'/'.$key.'/revert', ['to' => $to], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public static function publish(TestCase $test): TestResponse
    {
        return $test->postJson(self::URL.'/publish', [], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public static function bundle(TestCase $test, array $headers = []): TestResponse
    {
        return $test->getJson(self::BUNDLE_URL, $headers);
    }

    /** Save a draft and publish it: the key now has a live override. */
    public static function override(TestCase $test, string $key, string $en, string $ar): int
    {
        self::save($test, $key, ['text_en' => $en, 'text_ar' => $ar])->assertOk();

        return (int) self::publish($test)->assertCreated()->json('data.bundle_version');
    }

    public static function live(string $key): ?AppTextVersion
    {
        return AppTextVersion::query()->where('text_key', $key)->where('state', 'published')->first();
    }

    public static function draft(string $key): ?AppTextVersion
    {
        return AppTextVersion::query()->where('text_key', $key)->where('state', 'draft')->first();
    }
}
