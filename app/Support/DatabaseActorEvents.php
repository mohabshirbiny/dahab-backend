<?php

namespace App\Support;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The non-HTTP units of work and their row-level-security elevations
 * (spec 003 research R6–R7, Clarification Q2):
 *
 *  - a queued job runs as the system actor in the `system` scope, and each
 *    job start writes one `rls.system_elevation` audit row;
 *  - `migrate*` and `db:seed` run in the `maintenance` scope and write one
 *    `rls.maintenance_elevation` row once the audit log and the system actor
 *    exist.
 *
 * Every frame is popped when the unit ends, success or failure.
 */
final class DatabaseActorEvents
{
    /** Jobs currently holding a frame, so after/failed pop exactly once. */
    private static array $open = [];

    public static function register(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $id = self::jobKey($event->job);
            if (isset(self::$open[$id])) {
                return;
            }

            $systemId = SystemActor::id();
            DatabaseActor::push('system', staffId: $systemId);
            self::$open[$id] = true;

            app(RecordAuditLogAction::class)->execute(
                AuditEvent::RLS_SYSTEM_ELEVATION,
                'success',
                ['job' => $event->job->resolveName(), 'connection' => $event->connectionName, 'queue' => $event->job->getQueue()],
                entityType: 'job',
                ctx: RequestContext::forSystem(),
            );
        });

        foreach ([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class] as $end) {
            Event::listen($end, function ($event) {
                $id = self::jobKey($event->job);
                if (isset(self::$open[$id])) {
                    unset(self::$open[$id]);
                    DatabaseActor::pop();
                }
            });
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (! self::isMaintenance($event->command)) {
                return;
            }

            $systemId = self::systemIdIfReady();
            DatabaseActor::push('maintenance', staffId: $systemId);

            if ($systemId !== null) {
                app(RecordAuditLogAction::class)->execute(
                    AuditEvent::RLS_MAINTENANCE_ELEVATION,
                    'success',
                    ['command' => $event->command],
                    entityType: 'command',
                    ctx: RequestContext::forSystem(),
                );
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if (self::isMaintenance($event->command)) {
                DatabaseActor::pop();
            }
        });
    }

    private static function isMaintenance(?string $command): bool
    {
        return $command !== null && ($command === 'db:seed' || Str::startsWith($command, 'migrate'));
    }

    /** Before the first migrations there is no audit log and no system actor yet. */
    private static function systemIdIfReady(): ?string
    {
        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! Schema::hasTable('audit_log')
            || ! Schema::hasColumn('staff', 'is_system')) {
            return null;
        }

        SystemActor::forget();

        return Staff::query()->where('is_system', true)->value('staff_id');
    }

    private static function jobKey(object $job): string
    {
        return spl_object_id($job).':'.($job->getJobId() ?? '');
    }
}
