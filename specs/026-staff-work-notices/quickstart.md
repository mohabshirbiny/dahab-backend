# Quickstart — validating spec 026

Prerequisites: the test database (never the primary `dahab` DB), migrations run.

1. `php artisan test tests/Feature/Notices/StaffWorkNoticesTest.php` — each of E1–E9 notifies the right holders only; grouping counts up and restarts after reading; branch scope for orders; disabled/frozen staff skipped; a rolled-back change writes nothing; no queue worker involved (`QUEUE_CONNECTION` is irrelevant: the test asserts rows with `Queue::fake()`).
2. `php artisan test --filter=StaffNotice` — spec 021 notice tests still pass (founder alert now on the sync connection).
3. `php artisan staff-notices:prune` — read work notices older than 30 days disappear; unread and founder alerts stay.
4. Manual (local): with no worker running, submit an ID document from the app; within 30 s the reviewer's bell shows "New ID document to review" → click opens *Users and verification*.
5. Dashboard: `npm run type-check`, `npm run lint`, `npm run build`; the empty bell reads the new text.
