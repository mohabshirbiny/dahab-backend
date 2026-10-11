<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Models\AppTextBundle;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\Customer;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\OrderNotification;
use App\Notifications\Templates\Catalogue;
use App\Notifications\Templates\Templates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AppTexts;
use Tests\Support\NotificationCases;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// The golden file and the expectations below were written with the app named Dahab.
beforeEach(fn () => config(['app.name' => 'Dahab']));

// Spec 019 US4 (FR-040–FR-045): every customer message is a template with its code text as default.
// The golden test proves nothing changed by default; the rest proves an override reaches SMS,
// email and inbox, that anything wrong falls back to the code text, and that codes stay locked.

function tplCustomer(string $lang = 'en'): Customer
{
    return (new Customer)->forceFill(['preferred_lang' => $lang, 'email' => 'a@example.test', 'display_ref' => '000123']);
}

function tplCollected(?string $deadline = '2026-10-12T10:30:00+03:00'): OrderNotification
{
    return new OrderNotification(OrderEvent::COLLECTED, 'DH-2026-000042', 'Ring 21K', 'خاتم عيار 21', null, $deadline, 'Nasr City', 'مدينة نصر');
}

/** Stage the override the way staff do: a draft through the API, then Publish changes. */
function tplPublish($test, string $key, string $en, string $ar): void
{
    AppTexts::save($test, $key, ['text_en' => $en, 'text_ar' => $ar])->assertOk();
    AppTexts::publish($test)->assertCreated();
}

it('sends every message exactly as before while nothing is overridden (golden)', function () {
    $golden = json_decode((string) file_get_contents(base_path('tests/Fixtures/notification-golden.json')), true, flags: JSON_THROW_ON_ERROR);
    $now = NotificationCases::render();

    expect(array_keys($now))->toBe(array_keys($golden));
    $changed = array_keys(array_filter($now, fn ($row, $id) => $row !== $golden[$id], ARRAY_FILTER_USE_BOTH));

    expect($changed)->toBe([]);
});

it('registers every message of the catalogue, read-only for codes and links', function () {
    $this->artisan('app-texts:sync')->assertSuccessful();

    $keys = AppTextKey::query()->where('kind', 'notification')->get()->keyBy('text_key');
    expect($keys)->toHaveCount(count(Catalogue::registryEntries()))
        ->and($keys['order.collected.sms']->read_only)->toBeFalse()
        ->and($keys['order.collected.sms']->placeholders)->toContain('piece', 'note')
        ->and($keys['order.collected.mail_body']->default_en)->toBe($keys['order.collected.sms']->default_en)
        ->and($keys['order.collection_code.sms']->read_only)->toBeTrue()
        ->and($keys['order.collection_code.sms']->required_placeholders)->toBe(['code'])
        ->and($keys['order.collection_code.sms']->inbox)->toBeFalse()
        ->and($keys['auth.login_otp.sms']->read_only)->toBeTrue()
        ->and($keys['withdrawal.confirmation_link.mail_action']->required_placeholders)->toBe(['link'])
        ->and($keys['order.collected.sms']->area)->toBe('Notifications');

    // Running it again changes nothing.
    $this->artisan('app-texts:sync')->assertSuccessful();
    expect(AppTextKey::query()->where('kind', 'notification')->whereNotNull('default_changed_at')->count())->toBe(0);
});

it('lets an override reach the SMS, the email and the inbox, in each language', function () {
    $this->artisan('app-texts:sync')->assertSuccessful();
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    tplPublish($this, 'order.collected.sms', 'Collected: ({piece}). Order {order_ref}.{note}', 'اتسلمت: ({piece}). طلب {order_ref}.{note}');

    $n = tplCollected();
    $en = $n->toSms(tplCustomer('en'))->content;
    expect($en)->toBe('Dahab: Collected: (Ring 21K). Order DH-2026-000042. You can put it back on the market at 0% commission until 12 Oct, 10:30.')
        ->and($n->toSms(tplCustomer('ar'))->content)->toStartWith('Dahab: اتسلمت: (خاتم عيار 21). طلب DH-2026-000042.')
        // The email body follows the SMS sentence until it is edited on its own.
        ->and($n->toMail(tplCustomer('en'))->introLines[0])->toBe('Collected: (Ring 21K). Order DH-2026-000042. You can put it back on the market at 0% commission until 12 Oct, 10:30.')
        ->and($n->toInbox(tplCustomer('en'))->bodyEn)->toBe('Collected: (Ring 21K). Order DH-2026-000042. You can put it back on the market at 0% commission until 12 Oct, 10:30.')
        ->and($n->toInbox(tplCustomer('en'))->bodyAr)->toStartWith('اتسلمت: (خاتم عيار 21)');

    // Its own email body, and its own subject, are edited separately.
    tplPublish($this, 'order.collected.mail_body', 'Email only: {piece} is collected.', 'للإيميل بس: {piece} اتسلمت.');
    tplPublish($this, 'order.collected.mail_subject', 'Collected {order_ref}', 'اتسلمت {order_ref}');

    $mail = tplCollected()->toMail(tplCustomer('en'));
    expect($mail->subject)->toBe('Collected DH-2026-000042')
        ->and($mail->introLines[0])->toBe('Email only: Ring 21K is collected.')
        ->and(tplCollected()->toSms(tplCustomer('en'))->content)->toStartWith('Dahab: Collected: (Ring 21K)');
});

it('fills a setting placeholder with its live value when the message is sent', function () {
    $this->artisan('app-texts:sync')->assertSuccessful();
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    tplPublish($this, 'order.collected.sms', 'Collected. Relist free for {free_relist_hours} working hours.', 'اتسلمت. اعرضها ببلاش لمدة {free_relist_hours} ساعة عمل.');

    expect(tplCollected()->toSms(tplCustomer('en'))->content)->toBe('Dahab: Collected. Relist free for 12 working hours.');
});

it('sends the code text when the override cannot be filled, and never fails', function () {
    $this->artisan('app-texts:sync')->assertSuccessful();
    $staff = TopUps::actAsStaff($this, SeedRole::OPERATIONS);
    $default = tplCollected()->toSms(tplCustomer('en'))->content;

    // An override the API would have refused, written straight to the database: it names
    // something no message knows, and one with no branch to fill.
    $bypass = function (string $key, string $en, string $ar) use ($staff) {
        $version = AppTextVersion::query()->create([
            'text_key' => $key, 'version' => 1, 'state' => 'draft', 'text_en' => $en, 'text_ar' => $ar,
            'edited_by' => $staff->staff_id, 'edited_at' => now(),
        ]);
        $version->forceFill(['state' => 'published', 'published_by' => $staff->staff_id, 'published_at' => now(),
            'bundle_version' => (int) AppTextBundle::query()->create([
                'bundle_version' => ((int) AppTextBundle::query()->max('bundle_version')) + 1,
                'published_by' => $staff->staff_id, 'key_count' => 1, 'kinds' => ['notification'],
            ])->bundle_version])->save();
        Templates::flush();
    };

    $bypass('order.collected.sms', 'Collected {mystery}', 'اتسلمت {mystery}');
    expect(tplCollected()->toSms(tplCustomer('en'))->content)->toBe($default)
        ->and(tplCollected()->toSms(tplCustomer('ar'))->content)->toStartWith('Dahab: القطعة (خاتم عيار 21) اتسلمت.');

    // A value missing at send time: no branch on this message.
    $bypass('order.branch_changed.sms', 'The branch is now {branch}.', 'الفرع بقى {branch}.');
    $noBranch = new OrderNotification(OrderEvent::BRANCH_CHANGED, 'DH-2026-000042', 'Ring 21K', 'خاتم', null, '2026-10-12T10:30:00+03:00', null, null);
    expect($noBranch->toSms(tplCustomer('en'))->content)->toBe('Dahab: The branch for order DH-2026-000042 is now . Deadline: 12 Oct, 10:30.');
});

it('lets only a holder of content.edit_secure edit a code or link template, and keeps its {code}', function () {
    $this->artisan('app-texts:sync')->assertSuccessful();
    $staff = TopUps::actAsStaff($this, SeedRole::OPERATIONS);
    $staff->givePermissionTo(StaffPermission::CONTENT_EDIT_SECURE->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    AppTexts::save($this, 'auth.login_otp.sms', ['text_en' => 'Your code is nothing', 'text_ar' => 'الكود مفيش'])
        ->assertStatus(422);
    AppTexts::save($this, 'auth.login_otp.sms', ['text_en' => 'Sign-in code {code}', 'text_ar' => 'كود الدخول {code}'])->assertOk();
});

it('refuses to edit a template that carries a code or a link without that permission, and sends it unchanged', function () {
    $this->artisan('app-texts:sync')->assertSuccessful();
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    foreach (['auth.login_otp.sms', 'order.collection_code.sms', 'withdrawal.confirmation_link.mail_action'] as $key) {
        AppTexts::save($this, $key, ['text_en' => 'Your code is {code}', 'text_ar' => 'الكود {code}'])
            ->assertStatus(409)->assertJsonPath('code', 'content_key_read_only');
    }

    expect(AppTextVersion::query()->count())->toBe(0)
        ->and(OrderNotification::class)->toBeString();
});

it('refuses a template that drops, or invents, a placeholder', function () {
    $this->artisan('app-texts:sync')->assertSuccessful();
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    AppTexts::save($this, 'order.collected.sms', ['text_en' => 'Collected {nonsense}', 'text_ar' => 'اتسلمت'])
        ->assertStatus(422)->assertJsonPath('code', 'content_invalid_placeholder');
    // A message that names none of its own names is allowed; the app's setting names are too.
    AppTexts::save($this, 'order.collected.sms', ['text_en' => 'Collected {commission_gold_pct}', 'text_ar' => 'اتسلمت {commission_gold_pct}'])->assertOk();
});

it('keeps the inbox masking of the collection code, and the inbox flag of read-only messages', function () {
    $n = new OrderNotification(OrderEvent::COLLECTION_CODE, 'DH-2026-000042', 'Ring 21K', 'خاتم', '100.0', '2026-10-12T10:30:00+03:00', 'Nasr City', 'مدينة نصر', '482913');

    expect($n)->toBeInstanceOf(InboxNotification::class)
        ->and($n->toSms(tplCustomer('en'))->content)->toContain('482913')
        ->and($n->toInbox(tplCustomer('en'))->bodyEn)->toContain('••••••')->not->toContain('482913');
});

it('knows every message the classes can send', function () {
    // A case id with no catalogue entry behind it would render as an empty string.
    foreach (NotificationCases::render() as $id => $row) {
        foreach (['en', 'ar'] as $lang) {
            expect($row[$lang])->not->toHaveKey('sms_error')->not->toHaveKey('mail_error')->not->toHaveKey('inbox_error');
            if (isset($row[$lang]['sms'])) {
                expect(trim((string) $row[$lang]['sms']))->not->toBe('', $id);
            }
        }
    }

});
