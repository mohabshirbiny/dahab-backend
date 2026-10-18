# Contract — Dashboard notices after spec 026

Endpoints unchanged (spec 021): `GET /dashboard/notices` (`unread_only`, `per_page`; `meta.unread_count`), `POST /dashboard/notices/{id}/read`, `POST /dashboard/notices/read-all`. Any staff member, own notices only.

## Notice item

| Field | Type | Change |
|---|---|---|
| `id` | uuid | — |
| `kind` | string | **+9 values**: `work_identity`, `work_topup`, `work_listing`, `work_order`, `work_dispute`, `work_withdrawal`, `work_payout_account`, `work_extension`, `work_listing_report` (plus `founder_alert`) |
| `severity` | `info`/`warning`/`critical` | work notices are `info` |
| `title`, `body` | string | staff-facing English; for a group the title carries the count ("3 new ID documents to review") |
| `link` | string\|null | a Dashboard path; the item for count 1, the queue page for a group |
| `count` | integer ≥ 1 | **new** — events in the notice (1 for founder alerts) |
| `created_at` | date-time | for a group: the latest event |
| `read_at` | date-time\|null | — |

Classification: non-breaking (new optional field) / potentially breaking (new `kind` values — the Dashboard has no exhaustive map). Customer app: not a consumer.
