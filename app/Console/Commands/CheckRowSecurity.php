<?php

namespace App\Console\Commands;

use App\Support\Database\RowSecurityGuard;
use Illuminate\Console\Command;

/**
 * Spec 003 FR-002: run after every deploy and after a change of database user or host.
 * Fails when the application's role is a superuser, has BYPASSRLS, owns a table whose
 * row security is not forced, or connects through a transaction-mode pooler, and
 * refreshes the cached answer the API checks (EnsureRowSecurity).
 */
class CheckRowSecurity extends Command
{
    protected $signature = 'db:check-isolation';

    protected $description = 'Fail if the database connection can bypass row-level security';

    public function handle(RowSecurityGuard $guard): int
    {
        $result = $guard->refresh();

        if ($result['bypasses']) {
            $this->error("The database role [{$result['role']}] bypasses row-level security: ".implode('; ', $result['reasons'])
                .'. The API answers 503 until the application connects safely.');

            return self::FAILURE;
        }

        $this->info("The database role [{$result['role']}] is subject to row-level security.");

        return self::SUCCESS;
    }
}
