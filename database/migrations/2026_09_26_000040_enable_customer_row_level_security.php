<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer data isolation enforced by the database (spec 003, Constitution
 * v2.0.0 Principle II, Part 1 §5.1).
 *
 * Every customer-owned table gets FORCE ROW LEVEL SECURITY — the application
 * connects as the table owner, and owners bypass RLS unless it is forced —
 * and one policy: an elevated scope (staff / bootstrap / system /
 * maintenance) sees everything, a customer scope sees only its own rows,
 * and no scope sees nothing (fail closed). The scope and actor are bound per
 * unit of work by App\Support\DatabaseActor.
 *
 * Adding a customer-owned table later: enable + force RLS and add the same
 * policy in that table's migration (CustomerTableIsolationTest enforces it).
 */
return new class extends Migration
{
    /** @var array<string, string> table => owner predicate */
    private const OWNED = [
        'customer' => 'customer_id = dahab_current_customer_id()',
        'customer_password' => 'customer_id = dahab_current_customer_id()',
        'customer_trusted_device' => 'customer_id = dahab_current_customer_id()',
        'identity_document' => 'customer_id = dahab_current_customer_id()',
        'one_time_token' => 'actor_customer_id = dahab_current_customer_id()',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dahab_rls_scope() RETURNS text AS $$
                SELECT COALESCE(current_setting('app.rls_scope', true), '');
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION dahab_rls_elevated() RETURNS boolean AS $$
                SELECT dahab_rls_scope() IN ('staff', 'system', 'bootstrap', 'maintenance');
            $$ LANGUAGE sql STABLE;
        SQL);

        foreach (self::OWNED as $table => $owner) {
            $this->protect($table);
            DB::statement("CREATE POLICY {$table}_isolation ON {$table} FOR ALL
                USING (dahab_rls_elevated() OR {$owner})
                WITH CHECK (dahab_rls_elevated() OR {$owner})");
        }

        // A customer context may write its own audit rows but never read the log.
        $this->protect('audit_log');
        DB::statement('CREATE POLICY audit_log_read ON audit_log FOR SELECT USING (dahab_rls_elevated())');
        DB::statement('CREATE POLICY audit_log_write ON audit_log FOR INSERT WITH CHECK (
            dahab_rls_elevated()
            OR (actor_customer_id = dahab_current_customer_id() AND actor_staff_id IS NULL)
        )');

        // Staff-side log of identity-document views: no customer access at all.
        $this->protect('document_view_log');
        DB::statement('CREATE POLICY document_view_log_staff ON document_view_log FOR ALL
            USING (dahab_rls_elevated()) WITH CHECK (dahab_rls_elevated())');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS document_view_log_staff ON document_view_log');
        DB::statement('DROP POLICY IF EXISTS audit_log_write ON audit_log');
        DB::statement('DROP POLICY IF EXISTS audit_log_read ON audit_log');

        foreach (array_keys(self::OWNED) as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_isolation ON {$table}");
        }

        foreach ([...array_keys(self::OWNED), 'audit_log', 'document_view_log'] as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP FUNCTION IF EXISTS dahab_rls_elevated()');
        DB::statement('DROP FUNCTION IF EXISTS dahab_rls_scope()');
    }

    private function protect(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }
};
