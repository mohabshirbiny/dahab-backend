<?php

use App\Enums\IdentityDocumentKind;
use App\Enums\IdentityDocumentStatus;
use App\Models\Customer;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Constraint violations abort the surrounding Postgres transaction; wrapping the
// statement in a nested transaction turns that into a savepoint rollback.
function violates(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

it('builds a pending Egyptian ID by default and casts kind and status to enums', function () {
    $document = IdentityDocument::factory()->create()->fresh();

    expect($document->doc_kind)->toBe(IdentityDocumentKind::EGYPTIAN_ID)
        ->and($document->status)->toBe(IdentityDocumentStatus::PENDING)
        ->and($document->reviewed_by)->toBeNull()
        ->and($document->reviewed_at)->toBeNull()
        ->and($document->image_deleted_at)->toBeNull()
        ->and($document->customer)->toBeInstanceOf(Customer::class);
});

it('has named factory states for kind and review status', function () {
    expect(IdentityDocument::factory()->passport()->create()->doc_kind)->toBe(IdentityDocumentKind::PASSPORT);

    $approved = IdentityDocument::factory()->verified()->create()->fresh();
    expect($approved->status)->toBe(IdentityDocumentStatus::VERIFIED)
        ->and($approved->reviewer)->toBeInstanceOf(Staff::class)
        ->and($approved->reviewed_at)->not->toBeNull();

    expect(IdentityDocument::factory()->rejected()->create()->status)->toBe(IdentityDocumentStatus::REJECTED);
    expect(IdentityDocument::factory()->verified()->pending()->create()->fresh()->reviewed_by)->toBeNull();
});

it('refuses an unsupported document kind or status at the database', function () {
    $document = IdentityDocument::factory()->create();

    violates(fn () => DB::table('identity_document')->where('document_id', $document->document_id)->update(['doc_kind' => 'drivers_license']));
    violates(fn () => DB::table('identity_document')->where('document_id', $document->document_id)->update(['status' => 'archived']));
});

it('never serialises the storage references', function () {
    $document = IdentityDocument::factory()->create();

    expect($document->toArray())->not->toHaveKey('front_ref')->not->toHaveKey('back_ref')
        ->and($document->toJson())->not->toContain('front_ref')->not->toContain('back_ref');
});

it('stores factory images encrypted on the private disk', function () {
    Storage::fake('identity_private');
    $document = IdentityDocument::factory()->withImage('raw-image-bytes')->create();

    Storage::disk('identity_private')->assertExists($document->front_ref);
    expect(Storage::disk('identity_private')->get($document->front_ref))->not->toContain('raw-image-bytes');
    expect(app(IdentityDocumentStorage::class)->read($document->front_ref))->toBe('raw-image-bytes');
});

it('records a view with its viewer and ip', function () {
    $view = DocumentViewLog::factory()->create(['ip_address' => '203.0.113.9'])->fresh();

    expect($view->view_id)->toBeInt()
        ->and($view->viewer)->toBeInstanceOf(Staff::class)
        ->and($view->document)->toBeInstanceOf(IdentityDocument::class)
        ->and($view->ip_address)->toBe('203.0.113.9')
        ->and($view->viewed_at)->not->toBeNull();
});

it('requires a real document and a real staff viewer', function () {
    $document = IdentityDocument::factory()->create();
    $staff = Staff::factory()->create();

    violates(fn () => DB::table('document_view_log')->insert(['document_id' => (string) Str::uuid(), 'viewed_by' => $staff->staff_id]));
    violates(fn () => DB::table('document_view_log')->insert(['document_id' => $document->document_id, 'viewed_by' => (string) Str::uuid()]));
    violates(fn () => DB::table('document_view_log')->insert(['document_id' => $document->document_id, 'viewed_by' => null]));
});

it('makes document_view_log append-only at the database: no update, no delete', function () {
    $view = DocumentViewLog::factory()->create();

    violates(fn () => DB::table('document_view_log')->where('view_id', $view->view_id)->update(['ip_address' => '10.0.0.1']));
    violates(fn () => DB::table('document_view_log')->where('view_id', $view->view_id)->delete());
    violates(fn () => DB::table('document_view_log')->update(['viewed_at' => now()]));

    expect(DB::table('document_view_log')->where('view_id', $view->view_id)->count())->toBe(1);
});

it('makes the model refuse updates and deletes before a query is built', function () {
    $view = DocumentViewLog::factory()->create();

    expect(fn () => $view->update(['ip_address' => '10.0.0.1']))->toThrow(LogicException::class);
    expect(fn () => $view->delete())->toThrow(LogicException::class);
});

it('does not let a document with views be deleted out from under its log', function () {
    $view = DocumentViewLog::factory()->create();

    violates(fn () => DB::table('identity_document')->where('document_id', $view->document_id)->delete());
});
