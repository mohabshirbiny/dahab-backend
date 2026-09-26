<?php

use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Models\CustomerTrustedDevice;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Spec 003 US1 / SC-001: raw SQL with NO owner filter, as customer A, must
// only ever touch A's rows — the database enforces it, not the application.

const ISOLATED_TABLES = [
    'customer' => 'customer_id',
    'customer_password' => 'customer_id',
    'customer_trusted_device' => 'customer_id',
    'identity_document' => 'customer_id',
    'one_time_token' => 'actor_customer_id',
];

function customerWithRows(): Customer
{
    $c = Customer::factory()->create();
    CustomerPassword::query()->create(['customer_id' => $c->customer_id, 'password_hash' => Hash::make('x'), 'password_changed_at' => now()]);
    CustomerTrustedDevice::query()->create(['customer_id' => $c->customer_id, 'fingerprint_hash' => Str::random(64), 'first_seen_at' => now(), 'last_seen_at' => now()]);
    IdentityDocument::factory()->create(['customer_id' => $c->customer_id]);
    DB::table('one_time_token')->insert([
        'token_hash' => Str::random(64), 'purpose' => 'email_verification',
        'actor_customer_id' => $c->customer_id, 'expires_at' => now()->addHour(),
    ]);

    return $c;
}

/** Run a statement that must fail without aborting the test transaction. */
function refused(Closure $statement): bool
{
    try {
        DB::transaction($statement);

        return false;
    } catch (QueryException) {
        return true;
    }
}

beforeEach(function () {
    $this->a = customerWithRows();
    $this->b = customerWithRows();
    DatabaseActor::push('customer', customerId: $this->a->customer_id);
});

afterEach(fn () => DatabaseActor::pop());

it('returns only the current customer\'s rows from an unfiltered read', function (string $table, string $owner) {
    $owners = collect(DB::select("SELECT {$owner} AS o FROM {$table}"))->pluck('o')->unique()->values()->all();

    expect($owners)->toBe([$this->a->customer_id]);
})->with(fn () => collect(ISOLATED_TABLES)->map(fn ($o, $t) => [$t, $o])->values()->all());

it('changes none of another customer\'s rows', function (string $table, string $owner) {
    $updated = DB::update("UPDATE {$table} SET {$owner} = {$owner} WHERE {$owner} = ?", [$this->b->customer_id]);
    $deleted = DB::delete("DELETE FROM {$table} WHERE {$owner} = ?", [$this->b->customer_id]);

    expect($updated)->toBe(0)->and($deleted)->toBe(0);

    DatabaseActor::push('maintenance');
    expect(DB::table($table)->where($owner, $this->b->customer_id)->count())->toBeGreaterThan(0);
    DatabaseActor::pop();
})->with(fn () => collect(ISOLATED_TABLES)->map(fn ($o, $t) => [$t, $o])->values()->all());

it('refuses to create a row owned by another customer', function () {
    expect(refused(fn () => DB::table('customer_trusted_device')->insert([
        'customer_id' => $this->b->customer_id, 'fingerprint_hash' => Str::random(64), 'first_seen_at' => now(), 'last_seen_at' => now(),
    ])))->toBeTrue();

    expect(refused(fn () => DB::table('customer_trusted_device')->insert([
        'customer_id' => $this->a->customer_id, 'fingerprint_hash' => Str::random(64), 'first_seen_at' => now(), 'last_seen_at' => now(),
    ])))->toBeFalse();
});

it('lets a customer write its own audit rows but never read the audit log', function () {
    DatabaseActor::push('maintenance');
    DB::table('audit_log')->insert(['actor_staff_id' => Staff::query()->where('is_system', true)->value('staff_id'), 'action' => 'x', 'entity_type' => 'x', 'after_json' => '{}']);
    DatabaseActor::pop();

    expect(DB::select('SELECT * FROM audit_log'))->toBe([])
        ->and(refused(fn () => DB::table('audit_log')->insert(['actor_customer_id' => $this->a->customer_id, 'action' => 'mine', 'entity_type' => 'x', 'after_json' => '{}'])))->toBeFalse()
        ->and(refused(fn () => DB::table('audit_log')->insert(['actor_customer_id' => $this->b->customer_id, 'action' => 'forged', 'entity_type' => 'x', 'after_json' => '{}'])))->toBeTrue()
        ->and(refused(fn () => DB::table('audit_log')->insert(['actor_staff_id' => Staff::query()->value('staff_id'), 'action' => 'forged', 'entity_type' => 'x', 'after_json' => '{}'])))->toBeTrue();
});

it('gives a customer no access to the staff-side document view log', function () {
    $doc = IdentityDocument::query()->where('customer_id', $this->a->customer_id)->value('document_id');

    expect(DB::select('SELECT * FROM document_view_log'))->toBe([])
        ->and(refused(fn () => DB::table('document_view_log')->insert([
            'document_id' => $doc, 'viewed_by' => Staff::query()->value('staff_id'), 'viewed_at' => now(),
        ])))->toBeTrue();
});
