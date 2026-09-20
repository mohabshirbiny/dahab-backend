---
name: "laravel-queues-notifications"
description: "Add queued jobs, events/listeners, notifications and scheduled tasks on Redis + Horizon, with retries, idempotency and after-commit dispatch. Use when a task sends notifications, runs background work, calls external services, or needs scheduling."
argument-hint: "Job, event, notification or schedule to add"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

## Generate

```bash
php artisan make:job <Verb><Noun>
php artisan make:event <Noun><PastTense>
php artisan make:listener <Verb>On<Event> --event=<Event> --queued
php artisan make:notification <Name>
```

## Jobs

- `implements ShouldQueue`, constructor takes models (serialized by id) or scalar ids, never whole payload blobs.
- Always set `$tries`, `backoff()`, and `$timeout`; add `failed(Throwable $e)` that logs/audits.
- **Idempotent**: re-running must not double-post ledger entries or double-notify. Use `ShouldBeUnique` / `uniqueId()` or a DB idempotency key, and re-check state inside the job.
- Dispatch after commit: `Job::dispatch(...)->afterCommit()`, or set `after_commit => true` on the redis connection in `config/queue.php`.
- Money-moving jobs call Actions (`laravel-actions-services`); they don't duplicate business logic.
- Named queues by priority (`payments`, `notifications`, `default`) and matching supervisors in `config/horizon.php`.
- External HTTP: `Http::timeout()->retry()` with `->throw()`, behind a service interface so tests can fake it.

## Events & notifications

- Domain events implement `ShouldDispatchAfterCommit`.
- Notifications `implements ShouldQueue`, `via()` driven by the user's preferences and the spec, with `toMail`/`toArray`/etc. per channel. No personal or financial data beyond what the spec allows.
- Emitting a notification is a state-changing path: it needs a feature test with `Notification::fake()` (Constitution V).

## Scheduling

Declare in `routes/console.php`:

```php
Schedule::job(new ExpireStaleBuyRequests)->everyFiveMinutes()->withoutOverlapping()->onOneServer();
```

## Operate

```bash
php artisan horizon              # local worker + dashboard at /horizon
php artisan queue:failed
php artisan queue:retry <id>
```

Horizon dashboard access is restricted in `HorizonServiceProvider::gate()`. Keep it staff-only.
