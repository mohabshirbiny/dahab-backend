<?php

use App\Support\DatabaseActor;
use Illuminate\Support\Facades\DB;

// Spec 003 FR-002: RLS is only real if the application's role cannot bypass it.
// A superuser or BYPASSRLS role ignores every policy, and without FORCE the
// table owner (the application) would too.

it('connects as a role that cannot bypass row-level security', function () {
    $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

    expect($role->rolsuper)->toBeFalse('the application DB role must not be a superuser')
        ->and($role->rolbypassrls)->toBeFalse('the application DB role must not have BYPASSRLS');
});

it('forces row-level security on every covered table', function (string $table) {
    $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ? AND relnamespace = ?::regnamespace', [$table, 'public']);

    expect($row->relrowsecurity)->toBeTrue()->and($row->relforcerowsecurity)->toBeTrue();
})->with(['customer', 'customer_password', 'customer_trusted_device', 'identity_document', 'one_time_token', 'audit_log', 'document_view_log']);

it('keeps the bound actor on a new connection after a reconnect', function () {
    // Session settings live on one connection; `migrate:fresh` (and any purge)
    // opens a new one. It must inherit the current frame, not run with no actor.
    DatabaseActor::push('customer', customerId: '01a0df6f-0000-7000-8000-000000000001');

    DB::purge();

    $row = DB::selectOne("SELECT current_setting('app.rls_scope', true) AS s, current_setting('app.current_customer_id', true) AS c");
    expect($row->s)->toBe('customer')->and($row->c)->toBe('01a0df6f-0000-7000-8000-000000000001');

    DatabaseActor::pop();
    expect(DB::selectOne("SELECT current_setting('app.rls_scope', true) AS s")->s)->toBe('maintenance');
});
