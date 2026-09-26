<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 003 US4 / FR-031 / SC-005: a table that holds customer-owned rows can
// never be added without forced row-level security and a policy.

const OWNER_COLUMNS = ['customer_id', 'actor_customer_id', 'buyer_id', 'seller_id'];

/** @return list<string> tables with an owner column but no forced RLS + policy */
function unprotectedCustomerTables(): array
{
    return collect(DB::select("
        SELECT DISTINCT c.table_name AS t
        FROM information_schema.columns c
        JOIN pg_class k ON k.relname = c.table_name AND k.relnamespace = 'public'::regnamespace AND k.relkind = 'r'
        WHERE c.table_schema = 'public'
          AND c.column_name = ANY (?::text[])
          AND (NOT k.relrowsecurity OR NOT k.relforcerowsecurity
               OR NOT EXISTS (SELECT 1 FROM pg_policies p WHERE p.schemaname = 'public' AND p.tablename = c.table_name))
        ORDER BY 1
    ", ['{'.implode(',', OWNER_COLUMNS).'}']))->pluck('t')->all();
}

it('protects every table that holds customer-owned rows', function () {
    expect(unprotectedCustomerTables())->toBe([]);
});

it('reports a new customer table added without isolation', function () {
    DB::statement('CREATE TABLE zz_wishlist_probe (id bigserial primary key, customer_id uuid not null)');

    expect(unprotectedCustomerTables())->toBe(['zz_wishlist_probe']);
});
