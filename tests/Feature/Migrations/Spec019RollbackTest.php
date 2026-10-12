<?php

use App\Models\CategoryControl;
use App\Models\Customer;
use App\Models\PromoCode;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// Spec 019 T087, R16: each migration of the spec rolls back on an empty database and refuses
// once it holds records someone relied on. (DDL is transactional here, so each case is undone.)

function spec019Migration(string $file): object
{
    return require base_path('database/migrations/'.$file.'.php');
}

it('rolls the controls and the market-maker tables back, newest first, on an empty database', function () {
    // Spec 023 sits on the promo tables: its four migrations go first, newest first.
    foreach (['2026_10_16_000040_listing_views', '2026_10_16_000030_first_sale_advance', '2026_10_16_000020_commission_credit', '2026_10_16_000010_promo_code_terms'] as $file) {
        spec019Migration($file)->down();
    }

    spec019Migration('2026_10_12_000045_market_maker_purchases')->down();
    expect(Schema::hasColumn('buy_request', 'promo_code'))->toBeFalse();

    spec019Migration('2026_10_12_000040_market_makers')->down();
    expect(Schema::hasTable('promo_code'))->toBeFalse()
        ->and(Schema::hasTable('promo_code_use'))->toBeFalse()
        ->and(Schema::hasTable('market_maker_approval'))->toBeFalse();

    spec019Migration('2026_10_12_000030_category_controls')->down();
    expect(Schema::hasTable('category_control'))->toBeFalse()
        ->and(DB::table('setting')->where('setting_key', 'like', 'visibility.%')->count())->toBe(0);
});

it('rolls the content tables back while nothing is published', function () {
    spec019Migration('2026_10_12_000021_content_secure_templates_and_faq_order')->down();
    expect(Schema::hasColumn('app_text_key', 'pending_position'))->toBeFalse();
});

it('refuses to roll controls back once a control exists', function () {
    $staff = Staff::factory()->create();
    CategoryControl::query()->create(['category' => 'gold', 'level' => 'stop_new_listings', 'reason' => 'x', 'set_by' => $staff->staff_id]);

    expect(fn () => spec019Migration('2026_10_12_000030_category_controls')->down())
        ->toThrow(RuntimeException::class, 'cannot be rolled back');
});

it('refuses to roll controls back once a visibility value was changed', function () {
    DB::table('setting')->where('setting_key', 'visibility.sharing')->update(['value_bool' => false]);

    expect(fn () => spec019Migration('2026_10_12_000030_category_controls')->down())
        ->toThrow(RuntimeException::class);
});

it('refuses to roll the market-maker tables back once a code exists', function () {
    $staff = Staff::factory()->create();
    $dealer = Customer::factory()->verified()->create(['customer_type' => 'market_maker']);
    PromoCode::query()->create(['code' => 'DEALER', 'kind' => 'market_maker', 'tied_customer_id' => $dealer->customer_id, 'commission_waived' => true, 'gives_spread' => true, 'created_by' => $staff->staff_id]);

    // Spec 023's own rollbacks first (they hold nothing here), then the spec 019 one under test.
    foreach (['2026_10_16_000040_listing_views', '2026_10_16_000030_first_sale_advance', '2026_10_16_000020_commission_credit', '2026_10_16_000010_promo_code_terms'] as $file) {
        spec019Migration($file)->down();
    }

    expect(fn () => spec019Migration('2026_10_12_000040_market_makers')->down())
        ->toThrow(RuntimeException::class, 'cannot be rolled back');
});

it('refuses to roll the legal publishing back once a version beyond the seeds exists', function () {
    $staff = Staff::factory()->create();
    DB::table('legal_document')->insert([
        'code' => 'terms', 'version' => 1, 'body_en' => 'x', 'body_ar' => 'y', 'is_material' => false,
        'published_by' => $staff->staff_id, 'published_at' => now(),
    ]);

    expect(fn () => spec019Migration('2026_10_12_000020_legal_publishing')->down())
        ->toThrow(RuntimeException::class);
});
