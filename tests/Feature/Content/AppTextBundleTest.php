<?php

use App\Enums\SeedRole;
use App\Enums\SettingKey;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AppTexts;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US2, FR-006, R3: the public bundle — overrides of app and faq only, a tag that
// moves with a publish and with a setting, and 304 when nothing moved.

beforeEach(function () {
    $this->ops = TopUps::actAsStaff($this, SeedRole::OPERATIONS);
});

function setSetting(SettingKey $key, string $value): void
{
    Setting::query()->whereKey($key->value)->update(['value_numeric' => $value]);
}

it('serves an empty bundle before the first publish', function () {
    $res = AppTexts::bundle($this)->assertOk();

    expect($res->json('data.version'))->toBe(0)
        ->and($res->json('data.published_at'))->toBeNull()
        ->and($res->json('data.texts'))->toBe([])
        ->and($res->json('data.faq'))->toBe([])
        ->and($res->json('data.settings'))->toHaveKeys(['commission_gold_pct', 'commission_minimum_egp', 'free_relist_hours', 'saved_max'])
        ->and($res->headers->get('ETag'))->toBe($res->json('data.tag'))
        ->and($res->json('data.tag'))->toMatch('/^"0-[0-9a-f]{40}"$/');
});

it('carries the live value of every setting placeholder, as people write the number', function () {
    setSetting(SettingKey::COMMISSION_GOLD_PCT, '17.5000');
    setSetting(SettingKey::COMMISSION_MINIMUM_EGP, '200.0000');

    $settings = AppTexts::bundle($this)->json('data.settings');

    expect($settings['commission_gold_pct'])->toBe('17.5')
        ->and($settings['commission_minimum_egp'])->toBe('200')
        ->and($settings)->toHaveCount(18);
});

it('never serves a draft, a notification template, a retired key or a reverted key', function () {
    AppTexts::key('order.test.live');
    AppTexts::key('order.test.draft');
    AppTexts::key('order.test.notif', ['kind' => 'notification']);
    AppTexts::key('order.test.retired');
    AppTexts::key('order.test.reverted');

    AppTexts::override($this, 'order.test.live', 'Live', 'لايف');
    AppTexts::override($this, 'order.test.retired', 'Gone soon', 'هيمشي');
    AppTexts::override($this, 'order.test.reverted', 'Reverted soon', 'هيترجع');
    AppTexts::revert($this, 'order.test.reverted', 'default')->assertOk();
    AppTexts::publish($this)->assertCreated();
    AppTextKey::query()->whereKey('order.test.retired')->update(['retired_at' => now()]);

    // A notification override that exists (written past the API, which refuses read-only and
    // never saves into a bundle of the app) is still not served.
    $v = AppTextVersion::query()->create([
        'text_key' => 'order.test.notif', 'version' => 1, 'state' => 'draft', 'text_en' => 'SMS', 'text_ar' => 'رسالة',
        'edited_by' => $this->ops->staff_id, 'edited_at' => now(),
    ]);
    AppTexts::publish($this)->assertCreated();
    expect($v->refresh()->state)->toBe('published');
    // A draft saved after the last publish is not live.
    AppTexts::save($this, 'order.test.draft', ['text_en' => 'Draft', 'text_ar' => 'مسودة'])->assertOk();

    $texts = AppTexts::bundle($this)->assertOk()->json('data.texts');

    expect(array_keys($texts))->toBe(['order.test.live']);
});

it('serves published FAQ entries in order, without hidden ones or half entries', function () {
    foreach (['one', 'two', 'three', 'four'] as $i => $slug) {
        AppTexts::key("help.faq.{$slug}.question", ['kind' => 'faq', 'area' => 'Help', 'position' => $i + 1]);
        AppTexts::key("help.faq.{$slug}.answer", ['kind' => 'faq', 'area' => 'Help', 'position' => $i + 1]);
    }
    // Entry "two" before "one" on the page.
    AppTextKey::query()->whereIn('text_key', ['help.faq.one.question', 'help.faq.one.answer'])->update(['position' => 3]);
    AppTextKey::query()->whereIn('text_key', ['help.faq.two.question', 'help.faq.two.answer'])->update(['position' => 1]);

    foreach (['one', 'two', 'three'] as $slug) {
        AppTexts::save($this, "help.faq.{$slug}.question", ['text_en' => "Q {$slug}", 'text_ar' => "س {$slug}"])->assertOk();
        AppTexts::save($this, "help.faq.{$slug}.answer", ['text_en' => "A {$slug} {commission_gold_pct}%", 'text_ar' => "ج {$slug} {commission_gold_pct}", 'is_hidden' => $slug === 'three'])->assertOk();
    }
    // "four" has only its question published.
    AppTexts::save($this, 'help.faq.four.question', ['text_en' => 'Q four', 'text_ar' => 'س four'])->assertOk();
    AppTexts::publish($this)->assertCreated();

    $res = AppTexts::bundle($this)->assertOk();

    expect(array_column($res->json('data.faq'), 'key'))->toBe(['help.faq.two', 'help.faq.one'])
        ->and($res->json('data.faq.0.question'))->toBe(['en' => 'Q two', 'ar' => 'س two'])
        ->and($res->json('data.faq.0.position'))->toBe(1)
        ->and($res->json('data.texts'))->toBe([]);
});

it('changes the tag on a publish', function () {
    AppTexts::key('order.test.a');
    $before = AppTexts::bundle($this)->headers->get('ETag');

    AppTexts::override($this, 'order.test.a', 'Live', 'لايف');

    $after = AppTexts::bundle($this)->headers->get('ETag');
    expect($after)->not->toBe($before)->and($after)->toStartWith('"1-');
});

it('changes the tag when a setting behind a placeholder changes, with no text republished', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'We take {commission_gold_pct}%', 'بناخد {commission_gold_pct}٪');
    $before = AppTexts::bundle($this)->headers->get('ETag');

    setSetting(SettingKey::COMMISSION_GOLD_PCT, '18.0000');

    $res = AppTexts::bundle($this)->assertOk();
    expect($res->headers->get('ETag'))->not->toBe($before)
        ->and($res->json('data.version'))->toBe(1)
        ->and($res->json('data.settings.commission_gold_pct'))->toBe('18');

    // A setting that no text may state does not move it.
    $settled = $res->headers->get('ETag');
    setSetting(SettingKey::SUSPENSION_CANCELLATIONS_THRESHOLD, '9');
    expect(AppTexts::bundle($this)->headers->get('ETag'))->toBe($settled);
});

it('answers 304 to the tag it gave, and a full bundle to any other', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'Live', 'لايف');
    $tag = AppTexts::bundle($this)->headers->get('ETag');

    $same = AppTexts::bundle($this, ['If-None-Match' => $tag]);
    $same->assertStatus(304);
    expect($same->getContent())->toBe('')->and($same->headers->get('ETag'))->toBe($tag);

    AppTexts::bundle($this, ['If-None-Match' => 'W/'.$tag])->assertStatus(304);
    AppTexts::bundle($this, ['If-None-Match' => '"9-abc", '.$tag])->assertStatus(304);
    AppTexts::bundle($this, ['If-None-Match' => '"0-'.str_repeat('0', 40).'"'])->assertOk()->assertJsonPath('data.version', 1);

    // After a publish the old tag no longer matches.
    AppTexts::override($this, 'order.test.a', 'Live again', 'لايف تاني');
    AppTexts::bundle($this, ['If-None-Match' => $tag])->assertOk()->assertJsonPath('data.version', 2);
});

it('reads the bundle in three queries or fewer, and one fewer when nothing changed', function () {
    for ($i = 1; $i <= 5; $i++) {
        AppTexts::key("order.test.k{$i}");
        AppTexts::save($this, "order.test.k{$i}", ['text_en' => "Live {$i}", 'text_ar' => "لايف {$i}"])->assertOk();
    }
    AppTexts::publish($this)->assertCreated();
    $tag = AppTexts::bundle($this)->headers->get('ETag');

    $count = function (array $headers): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        AppTexts::bundle($this, $headers);
        $queries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $q) => str_contains($q, 'app_text_') || str_contains($q, '"setting"'))->count();
        DB::disableQueryLog();

        return $queries;
    };

    expect($count([]))->toBeLessThanOrEqual(3)
        ->and($count(['If-None-Match' => $tag]))->toBeLessThanOrEqual(2);
});

it('is rate limited like every public reference read', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'api.v1.reference.app-texts');

    expect($route->gatherMiddleware())->toContain('throttle:public.market')
        ->and($route->gatherMiddleware())->not->toContain('auth:staff');
});
