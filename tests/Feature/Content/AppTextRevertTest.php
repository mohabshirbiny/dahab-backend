<?php

use App\Enums\SeedRole;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AppTexts;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 FR-002 and the review fix: revert to default ends the override and never pins an
// old default; restore publishes an earlier value as a new version.

beforeEach(function () {
    $this->ops = TopUps::actAsStaff($this, SeedRole::OPERATIONS);
});

/** Move the code default, as a new app release followed by `app-texts:sync` would. */
function moveDefault(string $key, string $en, string $ar): void
{
    AppTextKey::query()->whereKey($key)->update(['default_en' => $en, 'default_ar' => $ar, 'default_changed_at' => DB::raw('clock_timestamp()')]);
}

it('reverts to the code default with a marker draft, and customers keep the override until it is published', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'Override', 'تعديل');

    AppTexts::revert($this, 'order.test.a', 'default')->assertOk()
        ->assertJsonPath('data.is_default_marker', true)
        ->assertJsonPath('data.en', '')
        ->assertJsonPath('data.draft_version', 1);

    AppTexts::bundle($this)->assertJsonPath('data.texts', ['order.test.a' => ['en' => 'Override', 'ar' => 'تعديل']]);

    $bundle = AppTexts::publish($this)->assertCreated()->json('data.bundle_version');

    // The key leaves the bundle: the app shows its own default.
    expect($bundle)->toBe(2);
    AppTexts::bundle($this)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.texts', []);

    $live = AppTexts::live('order.test.a');
    expect($live->is_default_marker)->toBeTrue()->and($live->version)->toBe(2);
    $row = collect($this->getJson(AppTexts::URL)->json('data'))->firstWhere('key', 'order.test.a');
    expect($row['published'])->toBeNull()->and($row['is_overridden'])->toBeFalse();
});

it('does not pin the default of the day: a later default change is what the app shows', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'Override', 'تعديل');
    AppTexts::revert($this, 'order.test.a', 'default')->assertOk();
    AppTexts::publish($this)->assertCreated();

    moveDefault('order.test.a', 'A newer default', 'افتراضي أحدث');

    // Still no override in the bundle, and the registry's default is the new one.
    AppTexts::bundle($this)->assertJsonPath('data.texts', []);
    $row = collect($this->getJson(AppTexts::URL)->json('data'))->firstWhere('key', 'order.test.a');
    expect($row['default']['en'])->toBe('A newer default')
        ->and($row['default_changed_since_override'])->toBeFalse();
});

it('flags a key whose default changed after its override was published', function () {
    AppTexts::key('order.test.a');
    AppTexts::key('order.test.b');
    AppTexts::override($this, 'order.test.a', 'Override', 'تعديل');
    moveDefault('order.test.a', 'A newer default', 'افتراضي أحدث');
    moveDefault('order.test.b', 'Another newer default', 'افتراضي تاني');

    $rows = collect($this->getJson(AppTexts::URL)->json('data'))->keyBy('key');
    expect($rows['order.test.a']['default_changed_since_override'])->toBeTrue()
        ->and($rows['order.test.b']['default_changed_since_override'])->toBeFalse();

    // An override written after the change is not flagged.
    AppTexts::override($this, 'order.test.a', 'Reviewed', 'اتراجع');
    $again = collect($this->getJson(AppTexts::URL)->json('data'))->firstWhere('key', 'order.test.a');
    expect($again['default_changed_since_override'])->toBeFalse();
});

it('restores an earlier published value as a new version', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'First', 'الأول');
    AppTexts::override($this, 'order.test.a', 'Second', 'التاني');

    AppTexts::revert($this, 'order.test.a', 1)->assertOk()
        ->assertJsonPath('data.en', 'First')
        ->assertJsonPath('data.is_default_marker', false)
        ->assertJsonPath('data.version', 3);

    // A draft is not live: customers still read the second version.
    expect(AppTexts::bundle($this)->json('data.texts')['order.test.a']['en'])->toBe('Second')
        ->and(AppTexts::live('order.test.a')->text_en)->toBe('Second');

    AppTexts::publish($this)->assertCreated()->assertJsonPath('data.bundle_version', 3);

    expect(AppTexts::live('order.test.a')->text_en)->toBe('First')
        ->and(AppTexts::live('order.test.a')->version)->toBe(3)
        ->and(AppTextVersion::query()->where('text_key', 'order.test.a')->where('version', 1)->sole()->state)->toBe('superseded')
        ->and(AppTextVersion::query()->where('text_key', 'order.test.a')->count())->toBe(3);
});

it('records the version a revert replaces', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'Override', 'تعديل');

    AppTexts::revert($this, 'order.test.a', 'default')->assertOk();

    $audit = AuditLog::query()->where('action', 'content.reverted')->sole();
    expect($audit->actor_staff_id)->toBe($this->ops->staff_id)
        ->and($audit->after_json)->toMatchArray(['text_key' => 'order.test.a', 'to' => 'default', 'replaces_version' => 1])
        ->and($audit->before_json)->toMatchArray(['text_en' => 'Override', 'version' => 1]);
});

it('replaces an existing draft with the revert', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'Override', 'تعديل');
    AppTexts::save($this, 'order.test.a', ['text_en' => 'Half written', 'text_ar' => 'نص'])->assertOk();

    AppTexts::revert($this, 'order.test.a', 'default')->assertOk()->assertJsonPath('data.draft_version', 2);

    expect(AppTextVersion::query()->where('text_key', 'order.test.a')->where('state', 'draft')->count())->toBe(1)
        ->and(AppTexts::draft('order.test.a')->is_default_marker)->toBeTrue();
});

it('refuses a revert that would change nothing, or to a version that does not exist', function () {
    AppTexts::key('order.test.a');

    // Nothing is overridden: already the default.
    AppTexts::revert($this, 'order.test.a', 'default')->assertStatus(422)->assertJsonPath('code', 'content_unchanged');

    AppTexts::override($this, 'order.test.a', 'First', 'الأول');
    // Restoring the live version is no change.
    AppTexts::revert($this, 'order.test.a', 1)->assertStatus(422)->assertJsonPath('code', 'content_unchanged');
    AppTexts::revert($this, 'order.test.a', 99)->assertNotFound();

    $this->postJson(AppTexts::URL.'/order.test.a/revert', ['to' => 'yesterday'], TopUps::key())->assertStatus(422)->assertJsonValidationErrors(['to']);
    $this->postJson(AppTexts::URL.'/order.test.a/revert', [], TopUps::key())->assertStatus(422)->assertJsonValidationErrors(['to']);
});

it('refuses to restore a version whose placeholders are no longer allowed', function () {
    AppTexts::key('order.test.a', ['placeholders' => ['hours']]);
    AppTexts::override($this, 'order.test.a', 'In {hours} h', 'بعد {hours} ساعة');
    AppTexts::override($this, 'order.test.a', 'Soon', 'قريبا');
    AppTextKey::query()->whereKey('order.test.a')->update(['placeholders' => '{}']);

    AppTexts::revert($this, 'order.test.a', 1)->assertStatus(422)->assertJsonPath('code', 'content_invalid_placeholder');
});
