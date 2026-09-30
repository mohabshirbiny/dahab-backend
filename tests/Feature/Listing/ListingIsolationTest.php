<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 FR-019, Constitution II: forced row-level security on `listing`
// and everything that hangs off it, whatever query the application runs.

const LISTING_TABLES = ['listing', 'listing_media', 'listing_branch_option', 'listing_ownership_declaration', 'listing_state_change', 'listing_queue_seq'];

beforeEach(function () {
    Storage::fake('identity_private');
    $this->a = Customer::factory()->verified()->create();
    $this->b = Customer::factory()->verified()->create();
    $this->mine = Listing::factory()->withPhotos(2)->withInvoice()->live()->create(['seller_id' => $this->a->customer_id]);
    $this->theirs = Listing::factory()->withPhotos(2)->withInvoice()->live()->create(['seller_id' => $this->b->customer_id]);

    $doc = Listings::declarationId();
    foreach ([$this->mine, $this->theirs] as $listing) {
        DB::table('listing_ownership_declaration')->insert(['listing_id' => $listing->listing_id, 'customer_id' => $listing->seller_id, 'legal_doc_id' => $doc]);
        DB::table('agreement_acceptance')->insert(['customer_id' => $listing->seller_id, 'legal_doc_id' => $doc, 'context' => 'list_piece']);
    }
});

/** @return array<string, list<string>> listing ids visible per table in the current scope */
function visibleListingIds(): array
{
    $out = [];
    foreach (LISTING_TABLES as $table) {
        $out[$table] = collect(DB::select("SELECT DISTINCT listing_id FROM {$table}"))->pluck('listing_id')->sort()->values()->all();
    }

    return $out;
}

function refusedInScope(Closure $work): bool
{
    try {
        DB::transaction($work);
    } catch (QueryException) {
        return true;
    }

    return false;
}

it('shows a seller only their own listing rows in every table, whatever the query', function () {
    DatabaseActor::push('customer', customerId: $this->a->customer_id);

    try {
        $visible = visibleListingIds();
        $acceptances = DB::table('agreement_acceptance')->pluck('customer_id')->all();
        $updated = DB::update('UPDATE listing SET description = ? WHERE seller_id = ?', ['hijacked', $this->b->customer_id]);
        $deletedMedia = DB::delete('DELETE FROM listing_media WHERE listing_id = ?', [$this->theirs->listing_id]);
        $deletedBranches = DB::delete('DELETE FROM listing_branch_option WHERE listing_id = ?', [$this->theirs->listing_id]);
    } finally {
        DatabaseActor::pop();
    }

    foreach (LISTING_TABLES as $table) {
        expect($visible[$table])->toBe([$this->mine->listing_id], $table);
    }

    expect($acceptances)->toBe([$this->a->customer_id])
        ->and($updated)->toBe(0)->and($deletedMedia)->toBe(0)->and($deletedBranches)->toBe(0)
        ->and($this->theirs->fresh()->description)->not->toBe('hijacked')
        ->and(DB::table('listing_media')->where('listing_id', $this->theirs->listing_id)->count())->toBe(3);
});

it('refuses to write rows for another seller', function () {
    DatabaseActor::push('customer', customerId: $this->a->customer_id);

    try {
        $listing = refusedInScope(fn () => DB::table('listing')->insert([
            'seller_id' => $this->b->customer_id, 'category' => 'diamond',
            'piece_type_id' => $this->theirs->piece_type_id, 'asking_price' => '1000.00',
        ]));
        $media = refusedInScope(fn () => DB::table('listing_media')->insert([
            'listing_id' => $this->theirs->listing_id, 'kind' => 'photo', 'storage_ref' => 'x', 'mime' => 'image/png',
        ]));
        $branch = refusedInScope(fn () => DB::table('listing_branch_option')->insert([
            'listing_id' => $this->theirs->listing_id, 'branch_id' => Branch::factory()->create()->branch_id,
        ]));
        $history = refusedInScope(fn () => DB::table('listing_state_change')->insert([
            'listing_id' => $this->theirs->listing_id, 'from_state' => 'live', 'to_state' => 'withdrawn', 'actor_customer_id' => $this->a->customer_id,
        ]));
        $acceptance = refusedInScope(fn () => DB::table('agreement_acceptance')->insert([
            'customer_id' => $this->b->customer_id, 'legal_doc_id' => Listings::declarationId(), 'context' => 'list_piece',
        ]));
    } finally {
        DatabaseActor::pop();
    }

    expect($listing)->toBeTrue()->and($media)->toBeTrue()->and($branch)->toBeTrue()->and($history)->toBeTrue()->and($acceptance)->toBeTrue();
});

it('lets a seller write history for their own listing only in their own name', function () {
    DatabaseActor::push('customer', customerId: $this->a->customer_id);

    try {
        $row = fn (array $actor) => fn () => DB::table('listing_state_change')->insert([
            'listing_id' => $this->mine->listing_id, 'from_state' => 'live', 'to_state' => 'withdrawn',
        ] + $actor);

        $asOther = refusedInScope($row(['actor_customer_id' => $this->b->customer_id]));
        $asStaff = refusedInScope($row(['actor_staff_id' => SystemActor::id(), 'note' => 'forged take-down']));
        $asSelf = refusedInScope($row(['actor_customer_id' => $this->a->customer_id]));
    } finally {
        DatabaseActor::pop();
    }

    expect($asOther)->toBeTrue()->and($asStaff)->toBeTrue()->and($asSelf)->toBeFalse();
});

it('closes every listing table when no actor is bound', function () {
    DatabaseActor::push('');

    try {
        $visible = visibleListingIds();
        $acceptances = DB::table('agreement_acceptance')->count();
    } finally {
        DatabaseActor::pop();
    }

    foreach (LISTING_TABLES as $table) {
        expect($visible[$table])->toBe([], $table);
    }

    expect($acceptances)->toBe(0);
});

it('refuses every seller endpoint on another seller\'s listing', function () {
    $id = $this->theirs->listing_id;
    $media = $this->theirs->media()->first()->media_id;
    $as = fn () => Listings::as($this, $this->a);

    $as()->getJson(Listings::SELLER_URL."/{$id}")->assertNotFound();
    $as()->getJson(Listings::SELLER_URL."/{$id}/media/{$media}")->assertNotFound();
    $as()->patchJson(Listings::SELLER_URL."/{$id}", ['description' => str_repeat('a', 50)], Listings::key())->assertNotFound();
    $as()->postJson(Listings::SELLER_URL."/{$id}/submit", [], Listings::key())->assertNotFound();
    $as()->postJson(Listings::SELLER_URL."/{$id}/withdraw", [], Listings::key())->assertNotFound();

    expect($this->theirs->fresh()->state->value)->toBe('live');
});
