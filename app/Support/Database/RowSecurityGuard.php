<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Spec 003 FR-002 at run time: row-level security is only real if the role the
 * application connects as can neither bypass it (superuser, BYPASSRLS) nor skip
 * the policies of a table it owns (RLS enabled without FORCE), and if the session
 * settings that carry the actor stay on one server connection (research R2: no
 * transaction-mode pooler). DatabaseRoleTest checks the role against the test
 * database only; this check runs on every environment, so a server set up with an
 * owner role that bypasses RLS (Neon's `neondb_owner`) stops instead of serving
 * other customers' rows.
 *
 * Unsafe answers are fail-closed: an unknown role is unsafe, and an error reading
 * the catalogue is not caught here (the request fails). The answer is cached for a
 * minute; `db:check-isolation` reads it fresh and refreshes the cache.
 */
final class RowSecurityGuard
{
    public const CACHE_KEY = 'db.row_security.bypassed';

    private const TTL_SECONDS = 60;

    public function bypassed(): bool
    {
        try {
            return (bool) Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn () => $this->inspect()['bypasses']);
        } catch (Throwable) {
            // A cache outage must not switch the check off.
            return $this->inspect()['bypasses'];
        }
    }

    /** @return array{role: string, reasons: list<string>, bypasses: bool} */
    public function refresh(): array
    {
        $result = $this->inspect();

        try {
            Cache::put(self::CACHE_KEY, $result['bypasses'], self::TTL_SECONDS);
        } catch (Throwable) {
        }

        return $result;
    }

    /**
     * Why the given role (the connection's own by default) cannot be trusted with
     * row-level security; no reasons means it can.
     *
     * @return array{role: string, reasons: list<string>, bypasses: bool}
     */
    public function inspect(?string $role = null): array
    {
        $row = $role === null
            ? DB::selectOne('SELECT current_user AS role, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user')
            : DB::selectOne('SELECT rolname AS role, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [$role]);

        if ($row === null) {
            return ['role' => (string) $role, 'reasons' => ['unknown role'], 'bypasses' => true];
        }

        $reasons = [];
        if ($row->rolsuper) {
            $reasons[] = 'superuser';
        }
        if ($row->rolbypassrls) {
            $reasons[] = 'BYPASSRLS';
        }

        // An owner (or a member of the owner role) skips the policies of a table without FORCE.
        $unforced = DB::select(
            "SELECT c.relname FROM pg_class c
             WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('r', 'p')
               AND c.relrowsecurity AND NOT c.relforcerowsecurity AND pg_has_role(?, c.relowner, 'USAGE')
             ORDER BY c.relname",
            [$row->role],
        );
        if ($unforced !== []) {
            $reasons[] = 'owns tables with row security not forced: '.implode(', ', array_map(fn ($t) => $t->relname, $unforced));
        }

        // Research R2: the actor lives in session settings, which a transaction-mode pooler
        // hands to other clients. Neon's pooled endpoint is one (its host ends in -pooler).
        if ($role === null && str_contains((string) config('database.connections.'.DB::getDefaultConnection().'.host'), '-pooler')) {
            $reasons[] = 'connected through a transaction-mode pooler (-pooler host)';
        }

        return ['role' => (string) $row->role, 'reasons' => $reasons, 'bypasses' => $reasons !== []];
    }
}
