# Spec 026 — Staff work notices (the Dashboard bell for operations work)

**Feature Branch**: `feature/staff-work-notices` (Backend, Dashboard)
**Created**: 2026-10-18
**Status**: Clarified by the owner 2026-10-18 (D26-1, -2, -3, -5); D26-4 taken as the default (it is the fix for the reported fault)
**Input**: Owner report "Dashboard notifications don't work on the server"; asked what they expected, the owner chose *work notices*: the bell should tell staff about operations work — a new ID to review, a new incoming-transfer notice, a new listing to review, a new order / dispute / withdrawal to handle.
**Repos**: Backend, Dashboard · **Customer app**: not affected (no customer-facing change; nothing under `/customer/*`, `/market/*` or `/reference/*` moves)

## Why

The bell (spec 021) only ever shows founder security alerts. Two things go wrong on the server:

1. Nothing about day-to-day work reaches it, so staff learn about a new ID, transfer, listing, order, dispute or withdrawal only by opening each page.
2. The founder alerts that do exist are written by a **queued** notification. A server without a running queue worker writes no notice at all — and, for the same reason, sends no customer SMS / email and no inbox message either. The scheduler (`schedule:run` every minute) must also run, or the sweeps (deadlines, expiries, the price feed) never happen.

## What exists today

- `staff_notice` table, the `staff_notice` channel, `GET /dashboard/notices` (+ unread count), mark one / all read (spec 021). Kinds: `founder_alert` only. Each notice: kind, severity, title, body, link (a Dashboard path), read time.
- The Dashboard bell polls every 30 s, shows the unread count, opens the notice's link and marks it read. Its empty text says only founder security notices appear there.
- Every queue has a permission that already decides who may work it (identity.review, topup.match, listing.review, order.* with branch scope, dispute.handle, withdrawal.release, payout_account.verify, listing_report.handle, order.extend_deadline).
- Staff with an assigned branch act only at that branch; staff without a branch act at every branch.

## Clarifications

### Session 2026-10-18

- Q: Which customer actions put a work notice on the bell? → A: All nine, E1–E9.
- Q: Several items before the bell is read? → A: One unread notice per queue that counts up; a fresh one after it is read.
- Q: Which staff get order events? → A: Only staff assigned to the order's branch who hold the permission; head-office staff (no branch) are not told.
- Q: How long are notices kept? → A: Read work notices 30 days; unread ones and founder alerts kept.

## User Scenarios & Testing

### User Story 1 — Staff are told there is new work in their queue (Priority: P1)

A staff member who works a queue sees the bell count go up when a customer creates work for that queue, and one click opens the right Dashboard page (the item itself where the page supports it).

**Why this priority**: it is what the owner asked for; without it the bell is empty for everyone except the founders.

**Independent test**: a customer submits an ID document; the staff member holding `identity.review` sees a new unread notice within one poll (≤ 30 s) whose link opens *Users and verification*; a staff member without the permission sees nothing.

**Acceptance scenarios** (the events — confirmed by the owner, D26-1):

| # | When (customer action, after it is saved) | Who is told | Opens |
|---|---|---|---|
| E1 | An ID document is submitted (first or a new attempt) | `identity.review` | Users and verification |
| E2 | A top-up notice is sent ("I transferred …") | `topup.match` | Incoming transfers |
| E3 | A listing is submitted for review (first time or after changes) | `listing.review` | Listings to review, the listing |
| E4 | A seller accepts a buyer → a new order expecting the piece at a branch | `order.receive` at the order's branch (D26-3) | Orders, the order |
| E5 | A dispute is opened on an order | `dispute.handle` | Disputes, the dispute |
| E6 | A withdrawal is submitted (after the email check) | `withdrawal.release` | Withdrawals, the withdrawal |
| E7 | A payout account is added | `payout_account.verify` | Withdrawals → Payout accounts to check |
| E8 | A seller asks for more time on an order | `order.extend_deadline` at the order's branch (D26-3) | Orders, the order |
| E9 | A listing is reported | `listing_report.handle` | Disputes and reports → Listing reports |

1. **Given** a staff member with the permission for a queue, **When** a customer creates one of the events above, **Then** a notice is written for them once the customer's change is saved, and it never appears if the change was rolled back.
2. **Given** a staff member without that permission, **Then** they get no notice for that event.
3. **Given** a disabled or frozen staff member, **Then** they get no notice.
4. **Given** the staff member who acts (e.g. credits a transfer), **Then** acting does not write a notice to anyone (only customer-created work notifies).
5. **Given** a notice, **When** it is clicked, **Then** it is marked read and opens its link.

### User Story 2 — The bell is not flooded (Priority: P1)

On a busy day a reviewer must not get fifty separate rows. Notices are grouped per queue while unread (D26-2).

**Independent test**: three ID documents are submitted while the reviewer has not read the bell; the reviewer sees **one** unread notice "3 new ID documents to review", moved to the top with the latest time; after reading it, the next submission starts a new notice "1 new ID document to review".

1. **Given** an unread notice of the same queue for the same staff member, **When** a new event arrives, **Then** the count and time of that notice go up instead of a new row (per-branch for orders).
2. **Given** the notice was read, **When** a new event arrives, **Then** a fresh notice starts at 1.
3. Founder security alerts are never grouped (spec 021 unchanged).

### User Story 3 — Notices are written even without a queue worker (Priority: P1)

**Independent test**: with no queue worker running, a customer submits an ID document; the reviewer's notice is there on the next poll.

1. Work notices are written in the same request, right after the customer's change is saved — never through the queue. A failure to write a notice never fails the customer's request.
2. The founder alerts of spec 021 keep their email/SMS on the queue; their Dashboard notice is written straight away as well (D26-4).

### User Story 4 — Old notices are cleared (Priority: P3)

1. Read work notices older than 30 days are deleted by a daily job; unread ones and founder alerts are kept (D26-5).

### Edge cases

- A staff member holds several queue permissions → one grouped notice per queue, not one per permission.
- The queue's item is handled by someone else before the notice is read → the notice stays (it is a "there was new work" signal, not a live task list); the page shows the current state.
- An order changes branch (staff action) → no notice (staff action); the new branch's staff see it in their list.
- A customer retries a request with the same `Idempotency-Key` → nothing new is saved, so no second notice.
- A role gains a permission later → only events after that moment notify.
- Permissions held through a role or directly both count.

## Requirements

### Functional requirements

- **FR-001**: The system MUST write a work notice for each event in the confirmed event list (D26-1), only after the customer's change has been saved.
- **FR-002**: Recipients MUST be the active, not-frozen staff holding the event's permission; for order events (E4, E8) only staff assigned to the order's branch — staff with no assigned branch are not told (D26-3).
- **FR-003**: Notices MUST be grouped per staff member, queue (and branch for orders) while unread: one row whose count, text and time are updated (D26-2).
- **FR-004**: Work notices MUST be written without the queue worker; writing them MUST NOT fail or slow the customer's request noticeably (best effort, logged on failure).
- **FR-005**: Each work notice MUST carry a link to a Dashboard path of the page that works that queue (the item where the page accepts one) and a severity of `info`.
- **FR-006**: Notices MUST NOT contain secrets, amounts, phone numbers, ID numbers or customer names — only the queue, the count and, for a single item, its public reference (e.g. `DH-2026-000123`, `WD-12`).
- **FR-007**: The bell's API (list, unread count, mark read, mark all read) keeps its shape; `kind` gains new values (potentially breaking for an exhaustive kind map — the Dashboard has none).
- **FR-008**: Read work notices older than 30 days MUST be removed by a daily scheduled job; unread work notices and founder alerts are never removed (D26-5).
- **FR-009**: The Dashboard bell's empty text MUST describe work notices; nothing else in the Dashboard changes shape.
- **FR-010**: A deployment note MUST state that a queue worker (Horizon or `php artisan queue:work`) and the scheduler (`php artisan schedule:run` every minute) must run on the server, and what stops without each.

### Key entities

- **Staff notice** (existing `staff_notice`): gains a grouped count for work notices; `dedupe_key` (or a new group key) identifies the open group per staff member and queue.
- **Work notice kind**: one kind per queue (`work_identity`, `work_topup`, `work_listing`, `work_order`, `work_dispute`, `work_withdrawal`, `work_payout_account`, `work_extension`, `work_listing_report` — names final in the plan).

## Success criteria

- **SC-001**: For every confirmed event, the right staff see the notice within one bell poll (≤ 30 s) with no queue worker running.
- **SC-002**: Ten events of one queue while unread produce exactly one unread notice per recipient.
- **SC-003**: No staff member without the queue's permission (or outside the branch rule) ever receives its notice.
- **SC-004**: A failure to write a notice never changes the customer's response.

## Decisions (owner, 2026-10-18)

| ID | Question | Decision |
|---|---|---|
| D26-1 | Which events notify? | All nine, E1–E9 |
| D26-2 | Grouping | One unread notice per queue (per branch for orders) that counts up; a new one after it is read |
| D26-3 | Order events (E4, E8): which staff? | Only that branch's staff: holders of `order.receive` (E8: `order.extend_deadline`) assigned to the order's branch; staff with no branch are not told |
| D26-4 | Founder alerts: write the Dashboard notice straight away too? | Default taken: yes — the bell works without the worker; email/SMS stay queued |
| D26-5 | Retention | Read work notices deleted after 30 days; unread ones and founder alerts kept |

## Assumptions

- Only customer-created work notifies; staff actions never do.
- Notices are staff-facing English, like spec 021's.
- No email or SMS to staff for work notices (bell only).
- No per-staff mute setting in this release.

## Deployment note (server)

- **Queue worker**: run Horizon (`php artisan horizon`, supervised) or `php artisan queue:work --tries=3` under a process manager. Without it: no customer SMS, email or inbox message, no founder email/SMS, no invoice PDF job, no referral reward job.
- **Scheduler**: a cron entry `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`. Without it: no price feed, no buy-request expiry, no order deadlines, no withdrawal-pause notices, no invoice-PDF healing, no idempotency pruning, no notice retention.
- After this spec, work notices themselves appear without the worker; everything above still needs it.
