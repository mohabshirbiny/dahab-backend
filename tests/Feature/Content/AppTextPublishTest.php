<?php

use App\Enums\SeedRole;
use App\Models\AppTextBundle;
use App\Models\AppTextVersion;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AppTexts;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 FR-003, Q2: *Publish changes* puts every pending draft live together, as one bundle.

beforeEach(function () {
    $this->ops = TopUps::actAsStaff($this, SeedRole::OPERATIONS);
});

function publishSqlState(Closure $work): ?string
{
    try {
        DB::transaction($work);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

it('publishes every pending draft together as one new bundle', function () {
    AppTexts::key('order.test.a');
    AppTexts::key('order.test.b', ['default_en' => 'Second', 'default_ar' => 'التاني']);
    AppTexts::key('order.test.c', ['default_en' => 'Third', 'default_ar' => 'التالت']);
    AppTexts::save($this, 'order.test.a', ['text_en' => 'A live', 'text_ar' => 'أ لايف'])->assertOk();
    AppTexts::save($this, 'order.test.b', ['text_en' => 'B live', 'text_ar' => 'ب لايف'])->assertOk();

    $res = AppTexts::publish($this)->assertCreated()
        ->assertJsonPath('data.bundle_version', 1)
        ->assertJsonPath('data.keys', ['order.test.a', 'order.test.b']);

    $live = AppTexts::live('order.test.a');
    expect($live->state)->toBe('published')
        ->and($live->published_by)->toBe($this->ops->staff_id)
        ->and($live->bundle_version)->toBe(1)
        ->and($live->published_at)->not->toBeNull()
        ->and(AppTexts::live('order.test.c'))->toBeNull()
        ->and(AppTexts::draft('order.test.a'))->toBeNull()
        ->and(AppTextBundle::query()->sole()->key_count)->toBe(2)
        ->and(AppTextBundle::query()->sole()->kinds)->toBe(['app'])
        ->and(AppTextBundle::query()->sole()->published_by)->toBe($this->ops->staff_id);

    $bundle = AppTexts::bundle($this)->assertOk();
    expect($bundle->json('data.version'))->toBe(1)
        ->and($bundle->json('data.texts'))->toBe([
            'order.test.a' => ['en' => 'A live', 'ar' => 'أ لايف'],
            'order.test.b' => ['en' => 'B live', 'ar' => 'ب لايف'],
        ])
        ->and($res->json('data.published_at'))->toBe($bundle->json('data.published_at'));
});

it('supersedes the version it replaces and keeps every version', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'First', 'الأول');
    $second = AppTexts::override($this, 'order.test.a', 'Second', 'التاني');

    expect($second)->toBe(2);
    $states = AppTextVersion::query()->where('text_key', 'order.test.a')->orderBy('version')->pluck('state', 'version')->all();
    expect($states)->toBe([1 => 'superseded', 2 => 'published'])
        ->and(AppTexts::live('order.test.a')->text_en)->toBe('Second');

    $history = $this->getJson(AppTexts::URL.'/order.test.a/history')->assertOk()->json('data');
    expect(collect($history)->pluck('version')->all())->toBe([2, 1])
        ->and(collect($history)->pluck('state')->all())->toBe(['published', 'superseded'])
        ->and($history[1]['published_by']['id'])->toBe($this->ops->staff_id)
        ->and($history[1]['en'])->toBe('First');
});

it('records in the audit log, per key, the version replaced and the wording before and after', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'First', 'الأول');
    AppTexts::override($this, 'order.test.a', 'Second', 'التاني');

    $audits = AuditLog::query()->where('action', 'content.published')->orderBy('audit_id')->get();
    expect($audits)->toHaveCount(2);

    $first = $audits[0]->after_json['keys'][0];
    expect($first['text_key'])->toBe('order.test.a')
        ->and($first['version'])->toBe(1)
        ->and($first['replaces_version'])->toBeNull()
        ->and($first['before'])->toEqual(['en' => 'Default title', 'ar' => 'العنوان الافتراضي'])
        ->and($first['after'])->toEqual(['en' => 'First', 'ar' => 'الأول']);

    $second = $audits[1]->after_json;
    expect($audits[1]->actor_staff_id)->toBe($this->ops->staff_id)
        ->and($second['bundle_version'])->toBe(2)
        ->and($second['keys'][0]['version'])->toBe(2)
        ->and($second['keys'][0]['replaces_version'])->toBe(1)
        ->and($second['keys'][0]['before'])->toEqual(['en' => 'First', 'ar' => 'الأول'])
        ->and($second['keys'][0]['after'])->toEqual(['en' => 'Second', 'ar' => 'التاني']);
});

it('refuses to publish with no drafts', function () {
    AppTexts::key('order.test.a');

    AppTexts::publish($this)->assertStatus(422)->assertJsonPath('code', 'content_nothing_to_publish');

    expect(AppTextBundle::query()->count())->toBe(0);
});

it('publishes nothing when one draft is invalid, and says which', function () {
    AppTexts::key('order.test.a');
    AppTexts::key('order.test.b');
    AppTexts::save($this, 'order.test.a', ['text_en' => 'Fine', 'text_ar' => 'تمام'])->assertOk();
    // A draft the rules would not have allowed (a placeholder no key may use), written past the API.
    AppTextVersion::query()->create([
        'text_key' => 'order.test.b', 'version' => 1, 'state' => 'draft', 'text_en' => 'Hi {mystery}', 'text_ar' => 'اهلا {mystery}',
        'edited_by' => $this->ops->staff_id, 'edited_at' => now(),
    ]);

    $res = AppTexts::publish($this)->assertStatus(422)->assertJsonPath('code', 'content_invalid');

    expect($res->json('details.keys')['order.test.b']['code'])->toBe('content_invalid_placeholder')
        ->and($res->json('details.keys'))->not->toHaveKey('order.test.a')
        ->and(AppTextBundle::query()->count())->toBe(0)
        ->and(AppTexts::live('order.test.a'))->toBeNull()
        ->and(AppTexts::draft('order.test.a'))->not->toBeNull();
});

it('refuses a draft that has become identical to what customers see', function () {
    AppTexts::key('order.test.a');
    AppTexts::save($this, 'order.test.a', ['text_en' => 'Same soon', 'text_ar' => 'نفسه'])->assertOk();
    // What customers see catches up with the draft before it is published: nothing would change.
    AppTextVersion::query()->where('text_key', 'order.test.a')->update(['text_en' => 'Default title', 'text_ar' => 'العنوان الافتراضي']);

    $res = AppTexts::publish($this)->assertStatus(422)->assertJsonPath('code', 'content_invalid');

    expect($res->json('details.keys')['order.test.a']['code'])->toBe('content_unchanged');
});

it('replays a publish with the same Idempotency-Key without publishing twice', function () {
    AppTexts::key('order.test.a');
    AppTexts::save($this, 'order.test.a')->assertOk();
    $key = (string) Str::uuid();

    $first = $this->postJson(AppTexts::URL.'/publish', [], ['Idempotency-Key' => $key])->assertCreated();
    $again = $this->postJson(AppTexts::URL.'/publish', [], ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json())->toBe($first->json())->and(AppTextBundle::query()->count())->toBe(1);
});

it('keeps published history immutable in the database (DH017)', function () {
    AppTexts::key('order.test.a');
    AppTexts::override($this, 'order.test.a', 'First', 'الأول');
    AppTexts::override($this, 'order.test.a', 'Second', 'التاني');
    $live = AppTexts::live('order.test.a');
    $old = AppTextVersion::query()->where('text_key', 'order.test.a')->where('state', 'superseded')->sole();

    // A published text never changes its words, and is never deleted.
    expect(publishSqlState(fn () => AppTextVersion::query()->whereKey($live->version_id)->update(['text_en' => 'Edited'])))->toBe('DH017')
        ->and(publishSqlState(fn () => AppTextVersion::query()->whereKey($live->version_id)->update(['state' => 'draft'])))->toBe('DH017')
        ->and(publishSqlState(fn () => AppTextVersion::query()->whereKey($live->version_id)->delete()))->toBe('DH017')
        // A superseded one never changes at all.
        ->and(publishSqlState(fn () => AppTextVersion::query()->whereKey($old->version_id)->update(['text_ar' => 'معدل'])))->toBe('DH017')
        ->and(publishSqlState(fn () => AppTextVersion::query()->whereKey($old->version_id)->update(['state' => 'published'])))->toBe('DH017')
        ->and(publishSqlState(fn () => AppTextVersion::query()->whereKey($old->version_id)->delete()))->toBe('DH017')
        // A version cannot be inserted already published.
        ->and(publishSqlState(fn () => DB::table('app_text_version')->insert([
            'text_key' => 'order.test.a', 'version' => 9, 'state' => 'superseded', 'text_en' => 'x', 'text_ar' => 'y',
            'edited_by' => $this->ops->staff_id, 'published_by' => $this->ops->staff_id, 'published_at' => now(), 'bundle_version' => 1,
        ])))->toBe('DH017')
        // A bundle never changes.
        ->and(publishSqlState(fn () => AppTextBundle::query()->where('bundle_version', 1)->update(['key_count' => 9])))->toBe('DH017')
        ->and(publishSqlState(fn () => AppTextBundle::query()->where('bundle_version', 1)->delete()))->toBe('DH017');

    expect(AppTexts::live('order.test.a')->text_en)->toBe('Second');
});

it('lets a draft be edited and deleted, and a published row only become superseded', function () {
    AppTexts::key('order.test.a');
    AppTexts::save($this, 'order.test.a')->assertOk();
    $draft = AppTexts::draft('order.test.a');

    expect(publishSqlState(fn () => AppTextVersion::query()->whereKey($draft->version_id)->update(['text_en' => 'Changed in place'])))->toBeNull()
        ->and(publishSqlState(fn () => AppTextVersion::query()->whereKey($draft->version_id)->delete()))->toBeNull();
});

it('refuses two live versions or two drafts of one text', function () {
    AppTexts::key('order.test.a');
    AppTexts::save($this, 'order.test.a')->assertOk();

    $second = publishSqlState(fn () => AppTextVersion::query()->create([
        'text_key' => 'order.test.a', 'version' => 2, 'state' => 'draft', 'text_en' => 'x', 'text_ar' => 'y',
        'edited_by' => $this->ops->staff_id, 'edited_at' => now(),
    ]));

    expect($second)->toBe('23505');
});
