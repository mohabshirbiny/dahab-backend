# Staff work notices

> File: `docs/features/staff-work-notices.md` · Branch: `feature/staff-work-notices` (Backend, Dashboard)
> Status: in progress · Date: 2026-10-18 · Spec Kit: [`specs/026-staff-work-notices/`](../../specs/026-staff-work-notices/spec.md)

## Goal

The owner reported "Dashboard notifications don't work on the server" and chose *work notices*: the bell tells
staff about operations work in the queues they work — a new ID to review, a transfer notice, a listing to review,
a new order at their branch, a dispute, a withdrawal, a payout account to check, a request for more time, a
listing report. It also works on a server with no queue worker.

## Impact Summary

```
Backend:      YES  — nine customer actions write a work notice after commit; founder alerts' notice on sync; prune job
Database:     YES  — staff_notice.group_key, staff_notice.item_count, one partial unique index
API:          YES  — GET /dashboard/notices items gain `count`; `kind` gains nine values
Dashboard:    YES  — the notice type gains `count`; the bell's empty text
Customer App: NO   — nothing under /customer/*, /market/*, /reference/* changes
Auth:         NO
Permissions:  NO   — recipients use the existing queue permissions; no new code
```

## Backend Impact

- `App\Support\Notices\WorkNotices::record(kind, itemId, ref, branchId)` — called inside each action's
  transaction, writes after commit in the same request (never the queue), errors reported and swallowed.
- `App\Enums\StaffNoticeKind` — nine `work_*` kinds with permission, branch scope, texts and links.
- Hooks: `SubmitIdentityDocumentAction` and `SubmitCustomerRegistrationAction` (E1), `SubmitTopUpNoticeAction` (E2),
  `SubmitListingAction` (E3), `AcceptBuyRequestAction` (E4), `OpenDisputeAction` (E5), `SubmitWithdrawalAction` (E6),
  `AddPayoutAccountAction` (E7), `RequestMoreTimeAction` (E8), `ListingReportsAction::report` (E9).
- `FounderSecurityNotification::viaConnections()` — the Dashboard notice on the `sync` connection; email/SMS queued.
- `php artisan staff-notices:prune` (daily) — read work notices older than 30 days (`config/dahab-notices.php`).

| Kind | Who is told | Link (one) | Link (several) |
|---|---|---|---|
| `work_identity` | `identity.review` | `/dashboard/users` | same |
| `work_topup` | `topup.match` | `/dashboard/transfers` | same |
| `work_listing` | `listing.review` | `/dashboard/listings?id=…` | `/dashboard/listings` |
| `work_order` | `order.receive` at the order's branch | `/dashboard/orders?id=…` | `/dashboard/orders` |
| `work_dispute` | `dispute.handle` | `/dashboard/disputes?id=…` | `/dashboard/disputes` |
| `work_withdrawal` | `withdrawal.release` | `/dashboard/withdrawals?id=…` | `/dashboard/withdrawals` |
| `work_payout_account` | `payout_account.verify` | `/dashboard/withdrawals?tab=accounts` | same |
| `work_extension` | `order.extend_deadline` at the order's branch | `/dashboard/orders?id=…` | `/dashboard/orders` |
| `work_listing_report` | `listing_report.handle` | `/dashboard/disputes?view=reports&rid=…` | `/dashboard/disputes?view=reports` |

Recipients are active, not frozen, not the system actor. Staff with no assigned branch get no order notices (D26-3).

## Database Impact

Migration `2026_10_18_000010_staff_work_notices`: `group_key TEXT NULL`, `item_count INT NOT NULL DEFAULT 1`
(CHECK ≥ 1), `uq_staff_notice_open_group (staff_id, group_key) WHERE read_at IS NULL AND group_key IS NOT NULL`.
No backfill. Reversible.

## API Changes

`GET /dashboard/notices`: each item gains `count` (integer ≥ 1) — non-breaking; `kind` gains nine values —
potentially breaking for an exhaustive map (none exists). Mark read / read all unchanged.

## Dashboard Impact

`src/types/notices.ts` + the service mapping gain `count`; `NoticesMenu.vue`'s empty text describes work notices.

## Customer App Impact

Not affected — the customer API does not change.

## Authentication / Authorization

Unchanged: any staff member reads and marks their own notices only.

## Permissions

None new. Recipients are the holders of the queue permissions above (through a role or directly).

## Validation

None.

## Error Handling

A failure to write a notice is reported to the log and never changes the customer's response.

## UI States

Bell: as spec 021; the empty state now reads "Nothing new. New work in your queues and security notices appear here."

## Testing

`tests/Feature/Notices/StaffWorkNoticesTest.php` (recipients, grouping and restart after read, links, branch scope,
rollback, the bell API's `count`, founder alert without a worker, prune, and each of E1–E9 through its endpoint).

## Breaking Changes

None.

## Migration / Compatibility — deployment note (server)

Deploy the Backend first (`php artisan migrate`), then the Dashboard. On the server:

- **Queue worker must run**: Horizon (`php artisan horizon`, under Supervisor/systemd) or
  `php artisan queue:work --tries=3`. Without it no customer SMS, email or in-app inbox message is sent, no founder
  security email/SMS, no invoice PDF job, no referral reward job. (After this feature the Dashboard bell itself no
  longer needs it.) Restart it after each deploy (`php artisan horizon:terminate` or `queue:restart`).
- **Scheduler must run**: cron `* * * * * cd /path/to/dahab-backend && php artisan schedule:run >> /dev/null 2>&1`.
  Without it: no gold price feed, no buy-request expiry, no order deadlines and reminders, no withdrawal-pause notices,
  no invoice-PDF healing, no idempotency or listing-view pruning, no notice retention.
- Check: `php artisan schedule:list`, `php artisan horizon:status` (or the queue worker's process), and that the
  `jobs` / Redis queue drains.
