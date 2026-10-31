<?php

use App\Enums\AccountKind;
use App\Enums\PieceCategory;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Lab;
use App\Models\Listing;
use App\Models\Order;
use App\Notifications\AccountNotification;
use App\Support\DatabaseActor;
use App\Support\Listings\CertificateFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// docs/features/labs-and-certificate-costs.md: a lab sets what it costs the seller; the seller
// chooses one lab and one branch; the cost is locked at acceptance and taken at payment.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->seller = Customer::factory()->verified()->create();
    $this->igi = Lab::main();
    $this->lab = Lab::factory()->create();
    $this->labBranch = Branch::factory()->create(['lab_id' => $this->lab->lab_id]);
    $this->igiBranch = Branch::factory()->create();
});

/** A live stone piece at one branch of `$lab`. */
function stoneAt($test, Lab $lab, array $attributes): Listing
{
    $piece = Listing::factory()->diamond()->withPhotos(3)->live()->create([
        'seller_id' => $test->seller->customer_id, 'lab_id' => $lab->lab_id, ...$attributes,
    ]);
    DatabaseActor::elevate('maintenance', function () use ($piece, $lab, $test) {
        DB::table('listing_branch_option')->where('listing_id', $piece->listing_id)->delete();
        DB::table('listing_branch_option')->insert(['listing_id' => $piece->listing_id,
            'branch_id' => $lab->lab_id === $test->lab->lab_id ? $test->labBranch->branch_id : $test->igiBranch->branch_id]);
    });

    return $piece;
}

function labStaff($test): void
{
    Orders::staff($test, SeedRole::CEO);
}

it('makes the IGI lab from the old settings and puts every branch on it', function () {
    expect($this->igi->name_en)->toBe('IGI')
        ->and((string) $this->igi->certificate_own_cost_egp)->toBe('0.0000')
        ->and((string) $this->igi->certificate_other_cost_egp)->toBe('1000.0000')
        ->and((string) $this->igi->certificate_none_cost_egp)->toBe('2000.0000')
        ->and((string) $this->igi->gold_inspection_cost_egp)->toBe('0.0000')
        ->and(DB::table('setting')->where('setting_key', 'like', 'certificate.%')->count())->toBe(0);
});

it('decides the cost by the lab that inspects the piece', function (string $kind, ?string $issuer, string $expected) {
    $labIds = ['this' => $this->lab->lab_id, 'igi' => $this->igi->lab_id];

    $cost = app(CertificateFee::class)->forListing(new Listing([
        'category' => 'diamond', 'certificate_kind' => $kind,
        'certificate_lab_id' => $issuer === null ? null : $labIds[$issuer],
    ]), $this->lab);

    expect($cost)->toBe($expected);
})->with([
    'its own certificate' => ['lab', 'this', '100.0000'],
    'another listed lab' => ['lab', 'igi', '700.0000'],
    'another lab, typed' => ['other_lab', null, '700.0000'],
    'no certificate' => ['none', null, '1500.0000'],
]);

it('lets the admin add and edit a lab, audited, with the seller told of a changed cost', function () {
    labStaff($this);

    $id = $this->postJson('/api/v1/dashboard/labs', [
        'name_en' => 'GIA', 'name_ar' => 'جي آي إيه', 'certificate_own_cost_egp' => '50',
        'certificate_other_cost_egp' => '600', 'certificate_none_cost_egp' => '900',
    ])->assertCreated()->assertJsonPath('data.gold_inspection_cost_egp', '0.0000')->json('data.id');

    Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id, 'lab_id' => $id]);

    $this->patchJson("/api/v1/dashboard/labs/{$id}", ['gold_inspection_cost_egp' => '250'])
        ->assertOk()->assertJsonPath('data.gold_inspection_cost_egp', '250.0000')->assertJsonPath('data.live_pieces', 1);

    expect(AuditLog::query()->where('action', 'reference.lab.updated')->count())->toBe(1);
    Notification::assertSentTo($this->seller, AccountNotification::class);
});

it('refuses a lab change without branches.manage', function () {
    Orders::staff($this, SeedRole::IGI_BRANCH);

    $this->patchJson("/api/v1/dashboard/labs/{$this->lab->lab_id}", ['name_en' => 'Nope'])->assertForbidden();
});

it('lists only open labs publicly, with their costs and branches', function () {
    Lab::factory()->create(['is_enabled' => false]);
    Lab::factory()->create(); // no branch

    $data = $this->getJson('/api/v1/reference/labs')->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toContain($this->lab->lab_id)->toHaveCount(collect($data)->count())
        ->and(collect($data)->firstWhere('id', $this->lab->lab_id)['costs'])->toBe(['own' => '100.0000', 'other' => '700.0000', 'none' => '1500.0000', 'gold' => null])
        ->and(collect($data)->firstWhere('id', $this->lab->lab_id)['branches'][0]['id'])->toBe($this->labBranch->branch_id);
});

it('quotes the chosen lab', function () {
    $base = '/api/v1/reference/quote?category=diamond&asking_price=100000';

    $this->getJson("{$base}&certificate=none&lab_id={$this->lab->lab_id}")->assertOk()->assertJsonPath('data.certificate_fee', '1500.0000');
    $this->getJson("{$base}&certificate=lab&certificate_lab_id={$this->lab->lab_id}&lab_id={$this->lab->lab_id}")->assertJsonPath('data.certificate_fee', '100.0000');
    $this->getJson("{$base}&certificate=igi")->assertJsonPath('data.certificate_fee', '0.0000');
});

it('takes one branch and needs the certificate details on a new listing', function () {
    $body = diamondBodyAt($this, ['branch_option_ids' => [$this->labBranch->branch_id], 'certificate_kind' => 'lab']);
    createListingAtLab($this, $body)->assertStatus(422)->assertJsonPath('code', 'certificate_lab_required');

    $body = diamondBodyAt($this, ['branch_option_ids' => [$this->labBranch->branch_id], 'certificate_kind' => 'other_lab']);
    createListingAtLab($this, $body)->assertStatus(422)->assertJsonPath('code', 'other_lab_name_required');

    $two = Branch::factory()->create(['lab_id' => $this->lab->lab_id]);
    $body = diamondBodyAt($this, ['branch_option_ids' => [$this->labBranch->branch_id, $two->branch_id], 'certificate_kind' => 'none']);
    createListingAtLab($this, $body)->assertStatus(422)->assertJsonValidationErrors('branch_option_ids');

    $body = diamondBodyAt($this, [
        'branch_option_ids' => [$this->labBranch->branch_id], 'certificate_kind' => 'other_lab', 'certificate_other_lab_name' => 'Small Lab',
    ]);
    createListingAtLab($this, $body)->assertCreated()
        ->assertJsonPath('data.lab.id', $this->lab->lab_id)
        ->assertJsonPath('data.certificate_fee', '700.0000')
        ->assertJsonPath('data.certificate_other_lab_name', 'Small Lab')
        ->assertJsonPath('data.needs_branch_choice', false);
});

function diamondBodyAt($test, array $overrides): array
{
    return Listings::goldBody($test, $test->seller, [
        'category' => 'diamond', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::DIAMOND), 'karat_code' => null, 'stated_weight_g' => null,
        'making_charge_per_g' => null, 'asking_price' => '100000', ...$overrides,
    ]);
}

function createListingAtLab($test, array $body)
{
    Listings::as($test, $test->seller);

    return $test->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson(Listings::SELLER_URL, $body);
}

it('never shows the typed lab name on the market', function () {
    $piece = stoneAt($this, $this->lab, ['certificate_kind' => 'other_lab', 'certificate_other_lab_name' => 'Secret Lab']);

    $this->getJson(Listings::MARKET_URL.'/'.$piece->listing_id)->assertOk()->assertDontSee('Secret Lab');
});

it('shows and takes no gold cost at 0, and takes it above 0', function () {
    $gold = fn () => Listing::factory()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id, 'lab_id' => $this->lab->lab_id]);

    $piece = $gold();
    $order = Orders::accepted($this, $piece, '200000');
    expect($order->refresh()->gold_inspection_cost)->toBeNull();

    $this->lab->update(['gold_inspection_cost_egp' => '300']);
    $order = Orders::accepted($this, $gold(), '200000');
    expect((string) $order->refresh()->gold_inspection_cost)->toBe('300.0000');

    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH, $order->branch_id);
    Orders::result($this, $order, ['measured_karat' => $order->listing->karat_code, 'measured_weight_g' => (string) $order->listing->stated_weight_g])->assertCreated();
    Orders::pay($this, Orders::buyer($order), $order->refresh())->assertOk();

    expect(Orders::internal(AccountKind::IGI_CERTIFICATES))->toBe('300.0000');
});

it('takes no gold cost on gold with diamond, or on a free relist', function () {
    $this->lab->update(['gold_inspection_cost_egp' => '300']);

    $mixed = new Listing(['category' => 'gold_with_diamond', 'certificate_kind' => 'none']);
    $relist = new Listing(['category' => 'gold', 'relisted_from_order_id' => (string) Str::uuid()]);

    expect(app(CertificateFee::class)->goldForListing($mixed, $this->lab))->toBeNull()
        ->and(app(CertificateFee::class)->goldForListing($relist, $this->lab))->toBeNull()
        ->and(app(CertificateFee::class)->forListing($relist, $this->lab))->toBeNull();
});

it('keeps the cost locked at acceptance after the lab edits it', function () {
    $piece = stoneAt($this, $this->lab, ['certificate_kind' => 'none']);
    $order = Orders::accepted($this, $piece, '200000');

    $this->lab->update(['certificate_none_cost_egp' => '9000']);
    expect((string) $order->refresh()->certificate_cost)->toBe('1500.0000')
        ->and($order->lab_id)->toBe($this->lab->lab_id);
});

it('moves the cost when inspection finds another certificate, and tells the seller', function () {
    $piece = stoneAt($this, $this->igi, ['certificate_kind' => 'lab', 'certificate_lab_id' => $this->igi->lab_id]);
    $order = Orders::accepted($this, $piece, '200000');
    expect((string) $order->refresh()->certificate_cost)->toBe('0.0000');

    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH, $order->branch_id);
    Orders::result($this, $order, ['measured_stone_grade' => 'VS1 G', 'certificate_kind_found' => 'none'])->assertCreated();

    expect((string) $order->refresh()->certificate_cost)->toBe('2000.0000')
        ->and($order->certificate_kind_found)->toBe('none')
        ->and($piece->refresh()->certificate_kind->value)->toBe('none')
        ->and(AuditLog::query()->where('action', 'order.certificate_corrected')->count())->toBe(1);
});

it('refuses a corrected cost that leaves the seller nothing', function () {
    $this->igi->update(['certificate_none_cost_egp' => '99999999']);
    $piece = stoneAt($this, $this->igi, ['certificate_kind' => 'lab', 'certificate_lab_id' => $this->igi->lab_id]);
    $order = Orders::accepted($this, $piece, '200000');

    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH, $order->branch_id);
    Orders::result($this, $order, ['measured_stone_grade' => 'VS1 G', 'certificate_kind_found' => 'none'])
        ->assertStatus(422)->assertJsonPath('code', 'certificate_cost_too_high');

    expect((string) $order->refresh()->certificate_cost)->toBe('0.0000');
});

it('takes a piece off the market while its lab is closed and brings it back by a new choice', function () {
    $piece = stoneAt($this, $this->lab, ['certificate_kind' => 'none']);

    $this->getJson(Listings::MARKET_URL.'/'.$piece->listing_id)->assertOk();
    $this->lab->update(['is_enabled' => false]);
    $this->getJson(Listings::MARKET_URL.'/'.$piece->listing_id)->assertNotFound();

    Listings::as($this, $this->seller);
    $this->getJson(Listings::SELLER_URL.'/'.$piece->listing_id)->assertJsonPath('data.needs_lab_choice', true);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson(Listings::SELLER_URL.'/'.$piece->listing_id.'/lab', ['branch_id' => $this->igiBranch->branch_id])
        ->assertOk()->assertJsonPath('data.needs_lab_choice', false)
        ->assertJsonPath('data.lab.id', $this->igi->lab_id)
        ->assertJsonPath('data.certificate_fee', '2000.0000');

    $this->getJson(Listings::MARKET_URL.'/'.$piece->listing_id)->assertOk();
});

it('lets an accepted piece replace a closed branch with one of the same lab only', function () {
    $other = Branch::factory()->create(['lab_id' => $this->lab->lab_id]);
    $piece = stoneAt($this, $this->lab, ['certificate_kind' => 'none']);
    $request = BuyRequests::queued($this, BuyRequests::funded('200000'), $piece);
    $this->labBranch->update(['is_enabled' => false]);

    BuyRequests::accept($this, $this->seller, $piece, $request, $this->igiBranch->branch_id)->assertStatus(422)->assertJsonPath('code', 'branch_not_in_lab');
    BuyRequests::accept($this, $this->seller, $piece, $request, $other->branch_id)->assertCreated();

    expect(Order::query()->where('listing_id', $piece->listing_id)->sole())
        ->branch_id->toBe($other->branch_id)
        ->lab_id->toBe($this->lab->lab_id)
        ->and((string) Order::query()->where('listing_id', $piece->listing_id)->sole()->certificate_cost)->toBe('1500.0000');
});

it('lets a reserved piece move to another lab at acceptance, at that lab cost', function () {
    $piece = stoneAt($this, $this->lab, ['certificate_kind' => 'none']);
    $request = BuyRequests::queued($this, BuyRequests::funded('200000'), $piece);
    $this->lab->update(['is_enabled' => false]);

    BuyRequests::accept($this, $this->seller, $piece, $request, $this->igiBranch->branch_id)->assertCreated();

    $order = Order::query()->where('listing_id', $piece->listing_id)->sole();
    expect($order->lab_id)->toBe($this->igi->lab_id)
        ->and((string) $order->certificate_cost)->toBe('2000.0000')
        ->and($piece->refresh()->lab_id)->toBe($this->igi->lab_id);
});

it('moves an order between branches of its lab only', function () {
    $sameLab = Branch::factory()->create(['lab_id' => $this->lab->lab_id]);
    $piece = stoneAt($this, $this->lab, ['certificate_kind' => 'none']);
    $order = Orders::accepted($this, $piece, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);

    $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
    $this->withHeaders($key())->postJson("/api/v1/dashboard/orders/{$order->order_id}/change-branch", ['branch_id' => Branch::factory()->create()->branch_id, 'reason' => 'Closer for the seller.'])
        ->assertStatus(422)->assertJsonPath('code', 'branch_not_in_lab');
    $this->withHeaders($key())->postJson("/api/v1/dashboard/orders/{$order->order_id}/change-branch", ['branch_id' => $sameLab->branch_id, 'reason' => 'Closer for the seller.'])
        ->assertOk();
});

it('reports what is owed per lab, equal to the account', function () {
    $piece = stoneAt($this, $this->lab, ['certificate_kind' => 'none']);
    $order = Orders::accepted($this, $piece, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH, $order->branch_id);
    Orders::result($this, $order, ['measured_stone_grade' => 'VS1 G'])->assertCreated();
    Orders::pay($this, Orders::buyer($order), $order->refresh())->assertOk();

    Orders::staff($this, SeedRole::CEO);
    $today = now('Africa/Cairo')->toDateString();
    $summary = $this->getJson("/api/v1/dashboard/wallet-statement?view=dahab&from={$today}&to={$today}")->assertOk()->json('data.summary');

    expect($summary['payable_by_lab'])->toBe([['lab_id' => $this->lab->lab_id, 'name_en' => $this->lab->name_en, 'name_ar' => $this->lab->name_ar, 'amount' => '1500.0000']])
        ->and($summary['certificates_payable'])->toBe('1500.0000');
});
