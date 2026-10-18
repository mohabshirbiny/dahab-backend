# Research — Spec 026 Staff work notices

## R1 — Written in the request, after commit, never through the queue

- **Decision**: a small service `App\Support\Notices\WorkNotices` with `record(WorkNoticeEvent)`. Called inside each action's transaction, it registers `DB::afterCommit(...)`; the callback finds the recipients and writes/updates their `staff_notice` rows straight away. Every failure is caught and logged (`report()`), never rethrown.
- **Rationale**: FR-004 / US3 — the bell must work without a worker. `DB::afterCommit` is the pattern already used for customer notices (e.g. `ChangePasswordAction`, `ReleasesRequests`) and fires only when the outermost transaction commits, so a rolled-back change writes nothing (US1 scenario 1). A handful of inserts per event is cheap.
- **Alternatives**: a queued Notification through `StaffNoticeChannel` (rejected: the fault being fixed); a DB trigger on each source table (rejected: text, links and recipients would live in SQL, and no Laravel notification channel could reuse it).

## R2 — Grouping (D26-2)

- **Decision**: two new columns on `staff_notice`: `group_key TEXT NULL` and `item_count INT NOT NULL DEFAULT 1`, plus a partial unique index `(staff_id, group_key) WHERE read_at IS NULL AND group_key IS NOT NULL`. Per recipient: `INSERT … ON CONFLICT (staff_id, group_key) WHERE … DO UPDATE SET item_count = staff_notice.item_count + 1, created_at = now() RETURNING notice_id, item_count`; then `UPDATE … SET title, body, link, params WHERE notice_id = ? AND item_count = ?` with the text for that count. The guard on `item_count` means that if two events race, the text of the later count wins.
- **Group key**: the kind (`work_identity`), or `work_order:<branch_id>` / `work_extension:<branch_id>` for branch-scoped kinds.
- **Created time** moves to the latest event so the group rises to the top of the newest-first list.
- **Link**: count 1 → the item (`/dashboard/orders?id=…`); count ≥ 2 → the queue page.
- **Rationale**: once read, the partial index no longer covers the row, so the next event inserts a fresh notice at 1 (US2 scenario 2). Founder alerts keep `group_key` NULL and are never grouped.
- **Alternatives**: one row per item (owner chose grouping); compute the title at read time (rejected: keeps the stored row the truth, like spec 021).

## R3 — Recipients (FR-002, D26-3)

- **Decision**: `Staff::permission($code)` (Spatie scope: through roles and direct), `is_active = true`, `is_system = false`, no open `account_freeze` (`whereDoesntHave('activeFreeze')`); for `work_order` / `work_extension` also `branch_id = <order branch>` (staff with no branch excluded).
- **Rationale**: the same rule the queue pages already enforce; the bell never tells someone about work they cannot open.

## R4 — Events and hooks

| Kind | Action (hook inside its transaction) | Permission | Link (1 item) | Link (group) |
|---|---|---|---|---|
| `work_identity` | `SubmitIdentityDocumentAction`, `SubmitCustomerRegistrationAction` (the document step) | `identity.review` | `/dashboard/users` | `/dashboard/users` |
| `work_topup` | `SubmitTopUpNoticeAction` | `topup.match` | `/dashboard/transfers` | `/dashboard/transfers` |
| `work_listing` | `SubmitListingAction` | `listing.review` | `/dashboard/listings?id=<listing_id>` | `/dashboard/listings` |
| `work_order` | `AcceptBuyRequestAction` | `order.receive` + branch | `/dashboard/orders?id=<order_id>` | `/dashboard/orders` |
| `work_dispute` | `OpenDisputeAction` | `dispute.handle` | `/dashboard/disputes?id=<dispute_id>` | `/dashboard/disputes` |
| `work_withdrawal` | `SubmitWithdrawalAction` | `withdrawal.release` | `/dashboard/withdrawals?id=<withdrawal_id>` | `/dashboard/withdrawals` |
| `work_payout_account` | `AddPayoutAccountAction` | `payout_account.verify` | `/dashboard/withdrawals?tab=accounts` | same |
| `work_extension` | `RequestMoreTimeAction` | `order.extend_deadline` + branch | `/dashboard/orders?id=<order_id>` | `/dashboard/orders` |
| `work_listing_report` | `ListingReportsAction::report` | `listing_report.handle` | `/dashboard/disputes?view=reports&rid=<report_id>` | `/dashboard/disputes?view=reports` |

Texts are staff-facing English with only public references (order ref, `WD-n`, dispute ref, `RPT-n`) — no names, phones, amounts or ID numbers (FR-006).

## R5 — Founder alerts without the worker (D26-4)

- **Decision**: `FounderSecurityNotification::viaConnections()` sends `StaffNoticeChannel` on the `sync` connection; mail and SMS stay on the default queue. The notification is already `afterCommit`.
- **Rationale**: the minimal change that makes the founder bell work on a server without a worker; the channel is idempotent on its dedupe key.

## R6 — Retention (D26-5)

- **Decision**: `php artisan staff-notices:prune` scheduled daily: deletes `staff_notice` rows with `kind LIKE 'work\_%'`, `read_at < now() - 30 days`. Unread rows and `founder_alert` rows are never touched. The 30 days are a config value (`dahab-notices.work_read_retention_days`).

## R7 — API shape

- `GET /dashboard/notices` items gain `count` (integer ≥ 1; 1 for founder alerts). Non-breaking (new optional field). `kind` gains nine values — potentially breaking only for an exhaustive kind map; the Dashboard has none (it renders title/body/severity/link). No new endpoint, no new permission.
