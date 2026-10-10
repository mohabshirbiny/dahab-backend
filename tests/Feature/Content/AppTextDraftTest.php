<?php

use App\Enums\SeedRole;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AppTexts;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US1 (Q2, A2): a draft is the shared, customer-invisible edit of one text.

beforeEach(function () {
    $this->ops = TopUps::actAsStaff($this, SeedRole::OPERATIONS);
});

it('saves a draft that customers cannot see', function () {
    AppTexts::key('order.test.title');

    AppTexts::save($this, 'order.test.title', ['text_en' => 'Relist for free', 'text_ar' => 'اعرضها ببلاش'])
        ->assertOk()
        ->assertJsonPath('data.key', 'order.test.title')
        ->assertJsonPath('data.en', 'Relist for free')
        ->assertJsonPath('data.ar', 'اعرضها ببلاش')
        ->assertJsonPath('data.draft_version', 1)
        ->assertJsonPath('data.is_default_marker', false)
        ->assertJsonPath('data.edited_by.id', $this->ops->staff_id);

    $draft = AppTexts::draft('order.test.title');
    expect($draft->state)->toBe('draft')->and($draft->version)->toBe(1)->and($draft->published_at)->toBeNull();

    // Customers: nothing changed.
    AppTexts::bundle($this)->assertOk()->assertJsonPath('data.version', 0)->assertJsonPath('data.texts', []);
});

it('lists every key with the default, what is live and the draft', function () {
    AppTexts::key('order.test.a');
    AppTexts::key('order.test.b', ['default_en' => 'Second', 'default_ar' => 'التاني']);
    AppTexts::save($this, 'order.test.a')->assertOk();

    $rows = collect($this->getJson(AppTexts::URL)->assertOk()->json('data'))->keyBy('key');

    expect($rows->keys()->all())->toBe(['order.test.a', 'order.test.b'])
        ->and($rows['order.test.a']['default'])->toBe(['en' => 'Default title', 'ar' => 'العنوان الافتراضي'])
        ->and($rows['order.test.a']['published'])->toBeNull()
        ->and($rows['order.test.a']['draft']['en'])->toBe('New title')
        ->and($rows['order.test.a']['is_overridden'])->toBeFalse()
        ->and($rows['order.test.b']['draft'])->toBeNull()
        ->and($rows['order.test.a'])->toHaveKeys(['kind', 'area', 'description', 'placeholders', 'required_placeholders', 'max_length', 'read_only', 'retired', 'default_changed_since_override']);
});

it('filters the list by area, kind, search and state, and pages by key', function () {
    AppTexts::key('order.test.a', ['area' => 'Orders']);
    AppTexts::key('order.test.b', ['area' => 'Orders', 'default_en' => 'Unique words here']);
    AppTexts::key('wallet.test.c', ['area' => 'Wallet']);
    AppTexts::key('help.faq.x.question', ['area' => 'Help', 'kind' => 'faq']);
    AppTexts::save($this, 'order.test.a')->assertOk();

    $keys = fn (string $query) => collect($this->getJson(AppTexts::URL.'?'.$query)->assertOk()->json('data'))->pluck('key')->all();

    expect($keys('area=Wallet'))->toBe(['wallet.test.c'])
        ->and($keys('kind=faq'))->toBe(['help.faq.x.question'])
        ->and($keys('q=unique+words'))->toBe(['order.test.b'])
        ->and($keys('state=draft'))->toBe(['order.test.a'])
        ->and($keys('state=default'))->toBe(['help.faq.x.question', 'order.test.a', 'order.test.b', 'wallet.test.c'])
        ->and($keys('changed=1'))->toBe([]);

    $page = $this->getJson(AppTexts::URL.'?per_page=2')->assertOk();
    expect($page->json('data'))->toHaveCount(2)->and($page->json('meta.next_cursor'))->not->toBeNull();
    $next = $this->getJson(AppTexts::URL.'?per_page=2&cursor='.$page->json('meta.next_cursor'))->assertOk();
    expect(collect($next->json('data'))->pluck('key')->all())->toBe(['order.test.b', 'wallet.test.c'])->and($next->json('meta.next_cursor'))->toBeNull();

    $this->getJson(AppTexts::URL.'?cursor=%25%25')->assertStatus(422)->assertJsonValidationErrors(['cursor']);
});

it('updates the one shared draft and bumps its draft_version', function () {
    AppTexts::key('order.test.title');
    AppTexts::save($this, 'order.test.title', ['text_en' => 'One', 'text_ar' => 'واحد'])->assertOk()->assertJsonPath('data.draft_version', 1);

    AppTexts::save($this, 'order.test.title', ['text_en' => 'Two', 'text_ar' => 'اتنين', 'draft_version' => 1])
        ->assertOk()->assertJsonPath('data.draft_version', 2)->assertJsonPath('data.en', 'Two');

    expect(AppTextVersion::query()->where('text_key', 'order.test.title')->count())->toBe(1);
});

it('refuses a save made on an outdated copy of the draft', function () {
    AppTexts::key('order.test.title');
    AppTexts::save($this, 'order.test.title', ['text_en' => 'One', 'text_ar' => 'واحد'])->assertOk();
    AppTexts::save($this, 'order.test.title', ['text_en' => 'Two', 'text_ar' => 'اتنين', 'draft_version' => 1])->assertOk();

    AppTexts::save($this, 'order.test.title', ['text_en' => 'Three', 'text_ar' => 'تلاتة', 'draft_version' => 1])
        ->assertStatus(409)->assertJsonPath('code', 'content_draft_changed')->assertJsonPath('details.draft_version', 2);

    expect(AppTexts::draft('order.test.title')->text_en)->toBe('Two');
});

it('refuses what the content rules refuse, each with its own code', function (array $key, array $body, int $status, string $code) {
    AppTexts::key('order.test.title', $key);

    AppTexts::save($this, 'order.test.title', $body)->assertStatus($status)->assertJsonPath('code', $code);

    expect(AppTextVersion::query()->count())->toBe(0);
})->with([
    'unknown placeholder' => [[], ['text_en' => 'Hi {name}', 'text_ar' => 'اهلا {name}'], 422, 'content_invalid_placeholder'],
    'unbalanced brace' => [[], ['text_en' => 'Hi {name', 'text_ar' => 'اهلا'], 422, 'content_invalid_placeholder'],
    'empty arabic' => [[], ['text_en' => 'Hi', 'text_ar' => ''], 422, 'content_language_missing'],
    'empty english' => [[], ['text_en' => '   ', 'text_ar' => 'اهلا'], 422, 'content_language_missing'],
    'too long' => [['max_length' => 10], ['text_en' => 'Eleven char', 'text_ar' => 'قصير'], 422, 'content_too_long'],
    'required placeholder dropped' => [['placeholders' => ['link'], 'required_placeholders' => ['link']], ['text_en' => 'Open it', 'text_ar' => 'افتحه {link}'], 422, 'content_missing_required_placeholder'],
    'same as what customers see' => [[], ['text_en' => 'Default title', 'text_ar' => 'العنوان الافتراضي'], 422, 'content_unchanged'],
]);

it('allows the key\'s own placeholders and the setting placeholders', function () {
    AppTexts::key('order.test.left', ['default_en' => '{hours} h left', 'default_ar' => 'فاضل {hours} ساعة', 'placeholders' => ['hours']]);

    AppTexts::save($this, 'order.test.left', [
        'text_en' => '{hours} h left, then {commission_gold_pct}% applies',
        'text_ar' => 'فاضل {hours} ساعة، وبعدها {commission_gold_pct}٪',
    ])->assertOk();
});

it('refuses a missing field as a validation error', function () {
    AppTexts::key('order.test.title');

    $this->putJson(AppTexts::URL.'/order.test.title/draft', ['text_en' => 'Only English'], TopUps::key())
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['text_ar']);
});

it('refuses to edit a read-only template, in the API and in the database', function () {
    AppTexts::key('order.test.otp', ['kind' => 'notification', 'read_only' => true, 'placeholders' => ['code'], 'required_placeholders' => ['code']]);

    AppTexts::save($this, 'order.test.otp', ['text_en' => 'Code {code}', 'text_ar' => 'الكود {code}'])
        ->assertStatus(409)->assertJsonPath('code', 'content_key_read_only');

    // Not only the API: the guard refuses a version of a read-only key (DH017).
    $state = null;
    try {
        DB::transaction(fn () => AppTextVersion::query()->create([
            'text_key' => 'order.test.otp', 'version' => 1, 'state' => 'draft', 'text_en' => 'x', 'text_ar' => 'y',
            'edited_by' => $this->ops->staff_id, 'edited_at' => now(),
        ]));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0];
    }
    expect($state)->toBe('DH017');
});

it('refuses a retired key and an unknown one', function () {
    AppTexts::key('order.test.gone', ['retired_at' => now()]);

    AppTexts::save($this, 'order.test.gone')->assertStatus(409)->assertJsonPath('code', 'content_key_retired');
    AppTexts::save($this, 'order.test.never')->assertNotFound();
});

it('discards a draft and says so in the audit log', function () {
    AppTexts::key('order.test.title');
    AppTexts::save($this, 'order.test.title', ['text_en' => 'Throw me away', 'text_ar' => 'ارميني'])->assertOk();

    AppTexts::discard($this, 'order.test.title')->assertNoContent();

    expect(AppTexts::draft('order.test.title'))->toBeNull();
    $audit = AuditLog::query()->where('action', 'content.draft_discarded')->sole();
    expect($audit->actor_staff_id)->toBe($this->ops->staff_id)
        ->and($audit->before_json)->toMatchArray(['text_en' => 'Throw me away', 'text_ar' => 'ارميني']);

    AppTexts::discard($this, 'order.test.title')->assertNotFound()->assertJsonPath('code', 'content_no_draft');
});

it('names the staff member and the version replaced in the audit log', function () {
    AppTexts::key('order.test.title');

    AppTexts::save($this, 'order.test.title', ['text_en' => 'Relist for free', 'text_ar' => 'اعرضها ببلاش'])->assertOk();

    $audit = AuditLog::query()->where('action', 'content.draft_saved')->sole();
    expect($audit->actor_staff_id)->toBe($this->ops->staff_id)
        ->and($audit->entity_type)->toBe('app_text')
        ->and($audit->after_json)->toMatchArray([
            'text_key' => 'order.test.title', 'version' => 1, 'replaces_version' => null,
            'text_en' => 'Relist for free', 'text_ar' => 'اعرضها ببلاش',
        ])
        ->and($audit->before_json)->toMatchArray(['text_en' => 'Default title', 'text_ar' => 'العنوان الافتراضي']);
});

it('answers a replay with the same Idempotency-Key from the first request, writing once', function () {
    AppTexts::key('order.test.title');
    $key = (string) Str::uuid();

    $first = AppTexts::save($this, 'order.test.title', ['text_en' => 'Once', 'text_ar' => 'مرة'], $key)->assertOk();
    $again = AppTexts::save($this, 'order.test.title', ['text_en' => 'Once', 'text_ar' => 'مرة'], $key)
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json())->toBe($first->json())
        ->and(AppTextVersion::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'content.draft_saved')->count())->toBe(1);

    // The same key with a different body is a client bug, never the other answer.
    AppTexts::save($this, 'order.test.title', ['text_en' => 'Other', 'text_ar' => 'تاني'], $key)->assertStatus(422);
});

it('requires an Idempotency-Key on every write', function () {
    AppTexts::key('order.test.title');

    $this->putJson(AppTexts::URL.'/order.test.title/draft', ['text_en' => 'A', 'text_ar' => 'ب'])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    $this->deleteJson(AppTexts::URL.'/order.test.title/draft')->assertStatus(400);
    $this->postJson(AppTexts::URL.'/order.test.title/revert', ['to' => 'default'])->assertStatus(400);
    $this->postJson(AppTexts::URL.'/publish')->assertStatus(400);

    expect(AppTextKey::query()->count())->toBe(1)->and(AppTextVersion::query()->count())->toBe(0);
});
