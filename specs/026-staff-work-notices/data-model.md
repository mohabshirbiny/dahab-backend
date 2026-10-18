# Data model — Spec 026

## `staff_notice` (existing, spec 021) — altered

| Column | Change | Notes |
|---|---|---|
| `group_key` | **new** `TEXT NULL` | Work notices: `work_<queue>` or `work_<queue>:<branch_id>`. NULL for founder alerts (never grouped). |
| `item_count` | **new** `INT NOT NULL DEFAULT 1 CHECK (item_count >= 1)` | How many events the open group holds. |
| `created_at` | behaviour | For a grouped notice: the time of the latest event in the group. |
| `title`, `body`, `link`, `params` | behaviour | Rewritten for the group's current count while unread. Once read, a row never changes again. |

Index: `CREATE UNIQUE INDEX uq_staff_notice_open_group ON staff_notice(staff_id, group_key) WHERE read_at IS NULL AND group_key IS NOT NULL;`

`params` for work notices: `{ "count": n, "ref": "<public ref or null>", "item_id": "<uuid/int or null>" }` — never a name, phone, amount or ID number.

## `StaffNoticeKind` (enum) — nine new values

`work_identity`, `work_topup`, `work_listing`, `work_order`, `work_dispute`, `work_withdrawal`, `work_payout_account`, `work_extension`, `work_listing_report`. Each knows its permission, whether it is branch-scoped, its texts and its links.

## Lifecycle of a work notice

```
(no open group) --event--> unread, count 1
unread, count n --event--> unread, count n+1 (time = now, text/link for n+1)
unread --mark read--> read (frozen; next event opens a new group)
read, older than 30 days --daily prune--> deleted
```
