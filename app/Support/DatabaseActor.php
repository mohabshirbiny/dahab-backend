<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Binds the database actor that PostgreSQL row-level security reads (spec 003).
 *
 * Every unit of work — a request, a queued job, a console command, or an
 * explicit elevation — pushes a frame and pops it when it ends, success or
 * failure, restoring the previous frame. The bottom of the stack is "no
 * actor", so no identity ever carries over to the next unit on the same
 * connection (FR-010). Session-level settings are used on purpose instead of
 * SET LOCAL: see specs/003-customer-rls-isolation/research.md R2.
 *
 * Scopes:
 *  - customer:    rows of `customerId` only
 *  - staff:       any authenticated staff request; permissions (spec 002) still decide
 *  - bootstrap:   auth routes and token-owner loading, before an actor exists
 *  - system:      queued jobs, as the system actor (audited)
 *  - maintenance: migrations, seeders, tests (audited outside tests)
 */
final class DatabaseActor
{
    public const SCOPES = ['customer', 'staff', 'bootstrap', 'system', 'maintenance'];

    public const ELEVATED = ['staff', 'bootstrap', 'system', 'maintenance'];

    /** @var list<array{scope: string, customer: string, staff: string}> */
    private static array $stack = [];

    public static function push(string $scope, ?string $customerId = null, ?string $staffId = null): void
    {
        if ($scope !== '' && ! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException("Unknown database actor scope [{$scope}].");
        }

        $frame = ['scope' => $scope, 'customer' => (string) $customerId, 'staff' => (string) $staffId];
        self::$stack[] = $frame;
        self::apply($frame);
    }

    public static function pop(): void
    {
        array_pop(self::$stack);
        self::apply(self::current());
    }

    /**
     * Run `$work` with a cross-customer scope, restoring the previous frame
     * afterwards even if it throws.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function elevate(string $scope, Closure $work, ?string $staffId = null): mixed
    {
        if (! in_array($scope, self::ELEVATED, true)) {
            throw new InvalidArgumentException("[{$scope}] is not an elevation scope.");
        }

        self::push($scope, null, $staffId);

        try {
            return $work();
        } finally {
            self::pop();
        }
    }

    /**
     * Re-publish the current frame on a newly opened connection. Session
     * settings live on one connection, so after a reconnect (for example
     * `migrate:fresh` purging its connection before seeding) the new one would
     * otherwise run with no actor.
     */
    public static function reapply(?Connection $connection = null): void
    {
        self::apply(self::current(), $connection);
    }

    public static function scope(): string
    {
        return self::current()['scope'];
    }

    public static function isElevated(): bool
    {
        return in_array(self::scope(), self::ELEVATED, true);
    }

    /**
     * Clear every frame (worker boot, test setup). `$apply = false` only
     * forgets the stack, for when the connection is about to be discarded
     * (test teardown, possibly on an aborted transaction).
     */
    public static function reset(bool $apply = true): void
    {
        self::$stack = [];

        if ($apply) {
            self::apply(self::current());
        }
    }

    /** @return array{scope: string, customer: string, staff: string} */
    private static function current(): array
    {
        return self::$stack[array_key_last(self::$stack) ?? -1] ?? ['scope' => '', 'customer' => '', 'staff' => ''];
    }

    /** @param  array{scope: string, customer: string, staff: string}  $frame */
    private static function apply(array $frame, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select(
            "SELECT set_config('app.rls_scope', ?, false), set_config('app.current_customer_id', ?, false), set_config('app.current_staff_id', ?, false)",
            [$frame['scope'], $frame['customer'], $frame['staff']],
        );
    }
}
