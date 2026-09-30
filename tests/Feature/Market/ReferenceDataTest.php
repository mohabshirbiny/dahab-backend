<?php

use App\Models\Branch;
use App\Models\Karat;
use App\Models\PieceType;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// Spec 010 FR-038, FR-039: what the apps read before anyone signs in — the
// enabled karats, piece types and branches, and the current declaration
// text. Public; only the fields of the contract.

it('lists the enabled karats with no purity or price', function () {
    $data = $this->getJson('/api/v1/reference/karats')->assertOk()->json('data');

    expect(array_column($data, 'code'))->toBe([24, 21, 18])
        ->and(array_keys($data[0]))->toBe(['code', 'sort_order']);
});

it('lists the enabled piece types, optionally of one category', function () {
    PieceType::query()->where('category', 'gold')->where('name_en', 'Pendant')->update(['is_enabled' => false]);

    $all = $this->getJson('/api/v1/reference/piece-types')->assertOk()->json('data');
    $gold = $this->getJson('/api/v1/reference/piece-types?category=gold')->assertOk()->json('data');

    expect(array_keys($all[0]))->toBe(['id', 'category', 'name_en', 'name_ar', 'typical_min_g', 'typical_max_g'])
        ->and(array_unique(array_column($all, 'category')))->toEqualCanonicalizing(['gold', 'diamond', 'gold_with_diamond'])
        ->and(array_unique(array_column($gold, 'category')))->toBe(['gold'])
        ->and(array_column($gold, 'name_en'))->toContain('Ring')->not->toContain('Pendant')
        ->and(collect($gold)->firstWhere('name_en', 'Ring')['typical_min_g'])->toBe('3.000');

    $this->getJson('/api/v1/reference/piece-types?category=silver')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('lists the enabled branches with their names and addresses only', function () {
    $open = Branch::factory()->create(['name_en' => 'IGI Nasr City']);
    Branch::factory()->disabled()->create(['name_en' => 'IGI Closed']);

    $data = $this->getJson('/api/v1/reference/branches')->assertOk()->json('data');

    expect(array_column($data, 'name_en'))->toBe(['IGI Nasr City'])
        ->and(array_keys($data[0]))->toBe(['id', 'name_en', 'name_ar', 'address_en', 'address_ar'])
        ->and($data[0]['id'])->toBe($open->branch_id);
});

it('returns the current ownership declaration', function () {
    $this->getJson('/api/v1/reference/legal-documents/ownership_declaration')->assertOk()
        ->assertJsonPath('data.code', 'ownership_declaration')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.body_en', 'I confirm this piece is mine to sell and the details above are accurate.')
        ->assertJsonStructure(['data' => ['id', 'code', 'version', 'body_en', 'body_ar']]);

    $this->getJson('/api/v1/reference/legal-documents/no_such_document')->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('serves the highest version of a document', function () {
    $v2 = DB::table('legal_document')->insertGetId([
        'code' => 'ownership_declaration', 'version' => 2, 'body_en' => 'New text.', 'body_ar' => 'نص جديد.',
        'published_by' => SystemActor::id(),
    ], 'legal_doc_id');

    $this->getJson('/api/v1/reference/legal-documents/ownership_declaration')->assertOk()
        ->assertJsonPath('data.id', $v2)->assertJsonPath('data.version', 2)->assertJsonPath('data.body_en', 'New text.');
});

it('needs no token and is rate limited as public market traffic', function () {
    foreach (['karats', 'piece-types', 'branches', 'legal-documents.show'] as $name) {
        $middleware = Route::getRoutes()->getByName('api.v1.reference.'.$name)->gatherMiddleware();

        expect($middleware)->toContain('throttle:public.market')
            ->and(collect($middleware)->filter(fn ($m) => is_string($m) && str_starts_with($m, 'auth'))->all())->toBe([]);
    }

    expect(Karat::query()->count())->toBeGreaterThan(3); // disabled karats exist and were not listed
});
