<?php

use App\Enums\SeedRole;
use App\Models\AppTextKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AppTexts;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US5, FR-030..FR-032: Help entries are pairs of keys, drafts until Publish changes,
// ordered by position, hidden without deleting.

beforeEach(function () {
    $this->ops = TopUps::actAsStaff($this, SeedRole::OPERATIONS);
});

function faqCreate($test, string $slug, array $extra = [])
{
    return $test->postJson('/api/v1/dashboard/faq', $extra + [
        'slug' => $slug,
        'question_en' => 'How do I '.$slug.'?',
        'question_ar' => 'ازاي '.$slug.'؟',
        'answer_en' => 'You '.$slug.' from the app.',
        'answer_ar' => 'بتعمل '.$slug.' من التطبيق.',
    ], ['Idempotency-Key' => (string) Str::uuid()]);
}

function faqMove($test, string $slug, int $position)
{
    return $test->patchJson('/api/v1/dashboard/faq/'.$slug.'/position', ['position' => $position], ['Idempotency-Key' => (string) Str::uuid()]);
}

it('creates an entry as two keys with drafts, invisible to customers until published', function () {
    faqCreate($this, 'sell_piece')->assertCreated()->assertJsonPath('data.slug', 'sell_piece')->assertJsonPath('data.position', 1);
    faqCreate($this, 'add_funds')->assertCreated()->assertJsonPath('data.position', 2);

    $q = AppTextKey::query()->findOrFail('help.faq.sell_piece.question');
    expect($q->kind)->toBe('faq')->and($q->area)->toBe('Help')->and($q->default_en)->toBe('')
        ->and(AppTexts::draft('help.faq.sell_piece.answer')->text_en)->toBe('You sell_piece from the app.')
        ->and(AppTexts::bundle($this)->json('data.faq'))->toBe([]);

    AppTexts::publish($this)->assertCreated();

    $faq = AppTexts::bundle($this)->json('data.faq');
    expect(array_column($faq, 'key'))->toBe(['help.faq.sell_piece', 'help.faq.add_funds'])
        ->and($faq[0]['question']['ar'])->toBe('ازاي sell_piece؟')
        ->and($faq[0]['position'])->toBe(1);
});

it('refuses a repeated slug, a bad slug, a missing language and a too long question', function () {
    faqCreate($this, 'sell_piece')->assertCreated();

    faqCreate($this, 'sell_piece')->assertStatus(409)->assertJsonPath('code', 'content_faq_exists');
    faqCreate($this, 'Bad Slug')->assertStatus(422)->assertJsonValidationErrors(['slug']);
    faqCreate($this, 'no_arabic', ['answer_ar' => ''])->assertStatus(422)->assertJsonPath('code', 'content_language_missing');
    faqCreate($this, 'too_long', ['question_en' => str_repeat('a', 161)])->assertStatus(422)->assertJsonPath('code', 'content_too_long');

    expect(AppTextKey::query()->where('text_key', 'like', 'help.faq.no_arabic%')->count())->toBe(0);
});

it('hides an entry without deleting it, and shows it again', function () {
    faqCreate($this, 'sell_piece')->assertCreated();
    faqCreate($this, 'add_funds')->assertCreated();
    AppTexts::publish($this)->assertCreated();

    // The same words, hidden: that is a change.
    AppTexts::save($this, 'help.faq.add_funds.answer', ['text_en' => 'You add_funds from the app.', 'text_ar' => 'بتعمل add_funds من التطبيق.', 'is_hidden' => true])->assertOk();
    AppTexts::publish($this)->assertCreated();
    expect(array_column(AppTexts::bundle($this)->json('data.faq'), 'key'))->toBe(['help.faq.sell_piece']);

    AppTexts::save($this, 'help.faq.add_funds.answer', ['text_en' => 'You add_funds from the app.', 'text_ar' => 'بتعمل add_funds من التطبيق.', 'is_hidden' => false])->assertOk();
    AppTexts::publish($this)->assertCreated();
    expect(array_column(AppTexts::bundle($this)->json('data.faq'), 'key'))->toBe(['help.faq.sell_piece', 'help.faq.add_funds']);
});

it('reorders as a draft and puts the new order live with Publish changes', function () {
    foreach (['one', 'two', 'three'] as $slug) {
        faqCreate($this, $slug)->assertCreated();
    }
    AppTexts::publish($this)->assertCreated();

    faqMove($this, 'three', 1)->assertOk()->assertJsonPath('data.position', 1);

    // Not live yet; the list shows it as pending.
    expect(array_column(AppTexts::bundle($this)->json('data.faq'), 'key'))->toBe(['help.faq.one', 'help.faq.two', 'help.faq.three']);
    $row = collect($this->getJson(AppTexts::URL.'?kind=faq&state=draft')->assertOk()->json('data'))->firstWhere('key', 'help.faq.three.question');
    expect($row['pending_position'])->toBe(1)->and($row['position'])->toBe(3);

    $before = AppTexts::bundle($this)->json('data.version');
    $res = AppTexts::publish($this)->assertCreated();
    expect($res->json('data.bundle_version'))->toBe($before + 1)
        ->and(array_column(AppTexts::bundle($this)->json('data.faq'), 'key'))->toBe(['help.faq.three', 'help.faq.one', 'help.faq.two']);

    expect(AppTextKey::query()->whereNotNull('pending_position')->count())->toBe(0);
    AppTexts::publish($this)->assertStatus(422)->assertJsonPath('code', 'content_nothing_to_publish');
});

it('clears the pending order when an entry is moved back, and refuses a no-op move', function () {
    foreach (['one', 'two'] as $slug) {
        faqCreate($this, $slug)->assertCreated();
    }
    AppTexts::publish($this)->assertCreated();

    faqMove($this, 'two', 1)->assertOk();
    faqMove($this, 'two', 1)->assertStatus(422)->assertJsonPath('code', 'content_unchanged');
    faqMove($this, 'two', 2)->assertOk();

    expect(AppTextKey::query()->whereNotNull('pending_position')->count())->toBe(0);
    faqMove($this, 'nothing_here', 1)->assertNotFound()->assertJsonPath('code', 'content_faq_unknown');
});

it('is for people who can edit app text, and a repeated write is idempotent', function () {
    $key = (string) Str::uuid();
    $first = faqCreate($this, 'sell_piece');
    $first->assertCreated();

    $payload = ['slug' => 'again', 'question_en' => 'Q', 'question_ar' => 'س', 'answer_en' => 'A', 'answer_ar' => 'ج'];
    $a = $this->postJson('/api/v1/dashboard/faq', $payload, ['Idempotency-Key' => $key])->assertCreated();
    $b = $this->postJson('/api/v1/dashboard/faq', $payload, ['Idempotency-Key' => $key])->assertCreated();
    expect($b->json('data'))->toBe($a->json('data'))
        ->and(AppTextKey::query()->where('text_key', 'help.faq.again.question')->count())->toBe(1);

    TopUps::actAsStaff($this, SeedRole::FINANCE);
    faqCreate($this, 'not_allowed')->assertForbidden();
    faqMove($this, 'sell_piece', 1)->assertForbidden();
});
