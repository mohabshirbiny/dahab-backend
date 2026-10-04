# API contract (planning): disputes, proxy collection, extension requests — spec 014

Planning artefact. The built contract is the code's `#[OA]` attributes → `composer swagger:generate`; the Postman folder "Disputes" mirrors it. Envelope, money (4-place strings), keyset pagination and the error shape are as in `docs/platform/api-contract.md`. Every POST needs an `Idempotency-Key`.

## Customer — `/api/v1/customer/me/*` (`auth:customer`)

### `POST /uploads` — two new purposes

- `dispute_photo`: images (identity mimes and size); gate **verified** (not trade — a suspended customer may report a problem).
- `proxy_id`: images; gate **trade**.

### `POST /orders/{order}/disputes` — gate `verified` · idempotent · throttle `customer.disputes`

Body `{ "reason": "not_as_listed|disagree_inspection|money_wrong|other_side_unresponsive|not_theirs_to_sell|other", "detail": "10–2000", "photo_tokens": ["…"] }` (0–5 tokens, purpose `dispute_photo`, the caller's, unused).

`201 { data: <own dispute> }`:

```json
{ "ref": "DSP-4417", "order_ref": "DH-2026-004417", "raised_as": "buyer|seller",
  "reason": "disagree_inspection", "detail": "…", "photo_count": 2,
  "state": "open|being_looked_at|resolved", "outcome": null, "reply": null,
  "opened_at": "…", "resolved_at": null }
```

Errors: `404` (not a party), `409 illegal_order_transition` (state not in the four), `409 order_frozen` (another party's dispute is open, `details.dispute_ref`), `409 dispute_already_raised`, `422 validation_failed` (`reason` `not_theirs_to_sell` from a seller; token not found/used/wrong purpose), `403 verification_required`.

### `GET /orders/{order}` — additions (non-breaking)

```json
{ "frozen": true,
  "dispute": <own dispute> | null,
  "dispute_outcome": "resumed|cancelled" | null,
  "extension_request": { "state": "waiting|accepted|refused|lapsed", "reason": "branch_closed", "detail": "…",
                         "hours_granted": 12, "answer_note": "…", "requested_at": "…", "answered_at": "…" } | null,
  "proxy": { "name": "Ahmed Samir", "phone_masked": "+20 10 •••• •123", "named_at": "…" } | null,
  "can": { "…existing…", "report_problem": true, "ask_more_time": false, "name_proxy": false } }
```

`dispute`: only the caller's own dispute (each party has at most one); the other side's is never returned (the database hides it). `dispute_outcome`: for either party, from the order's history once a dispute on it was resolved (latest). `extension_request`: the seller only, the latest. `proxy`: the buyer only. The list (`GET /orders`) items add `frozen`.

### `POST /orders/{order}/extension-requests` — gate `verified` · idempotent

Body `{ "reason": "travelling|emergency|branch_closed|other", "detail": "10–1000" }` → `201 { data: <extension_request> }`.
Errors: `404` (not the seller), `409 illegal_order_transition` (not `awaiting_delivery`), `409 deadline_not_running` (the reach-branch deadline has passed — spec 012's code), `409 extension_request_pending`.

### `POST /orders/{order}/proxy` — gate `trade` · idempotent

Body `{ "name": "2–120", "phone": "+201001234567", "id_upload_token": "…", "authorisation_id": <legal_doc_id>, "authorisation_accepted": true }` → `200 { data: <order detail> }`. Replaces any proxy (new acceptance). After commit: the proxy's SMS (no code), the buyer's confirmation.
Errors: `404` (not the buyer), `409 illegal_order_transition` (not `ready_to_collect` or already collected), `409 order_frozen`, `422 declaration_required` (not accepted or not the current `collection_proxy_authorisation` version), `422 validation_failed`.

### `POST /orders/{order}/proxy/remove` — gate `verified` · idempotent

→ `200 { data: <order detail> }`. `409 illegal_order_transition` when none named or already collected; `409 order_frozen`.

### `GET /reference/legal-documents/collection_proxy_authorisation` (public, existing route)

The current version (`id`, `version`, `body_en`, `body_ar`).

## Dashboard — `/api/v1/dashboard/*` (`auth:staff`)

### `GET /disputes` — `dispute.handle` · keyset

Query `state=unresolved|open|passed_on|resolved` (default `unresolved`), `assigned=me`, `q` (`DSP-…` or `DH-…`), `cursor`.
Item: `{ "id", "ref", "order": { "id", "ref", "state", "frozen_from" }, "reason", "raised_as", "raised_by": { "id", "display_ref" }, "state", "assigned_to": { "id", "name" } | null, "opened_at", "age_seconds" }`. `meta.counts { "open": 2, "passed_on": 1 }`.

### `GET /disputes/{dispute}` — `dispute.handle`

The item plus `detail`, `photos: [{ "id", "mime" }]`, `history: [{ "kind", "by": { "type": "customer|staff", "name" }, "assigned_to", "note", "at" }]`, `outcome`, `reply`, `resolved_by`, `compensations: [{ "party", "amount", "reason", "paid_by", "paid_at" }]`, and `order`: the staff order detail (spec 012 shape: figures, ledger, timeline, deposit held). `can { "pass_on", "resolve_resume", "resolve_against_sale", "compensate", "suspend_seller", "compensation_caps": { "per_payment": "2000.0000", "left_today": "3500.0000" } | null }` (`null` = uncapped).

### `GET /disputes/{dispute}/photos/{photo}` — `dispute.handle`

The decrypted image (`Cache-Control: no-store`); audited `dispute.photo_viewed`.

### `GET /dispute-assignees` — `dispute.handle`

`{ data: [{ "id", "name", "roles": ["Operations"] }] }` — active staff holding `dispute.handle`, excluding the caller.

### `POST /disputes/{dispute}/pass-on` — `dispute.handle` · idempotent · audited `dispute.passed_on`

Body `{ "assignee_id": "uuid", "note": "10–1000" }` → `200 { data: <detail> }`.
Errors: `409 illegal_dispute_transition` (resolved), `422 assignee_not_eligible` (inactive, lacks the code, or self).

### `POST /disputes/{dispute}/resolve` — `dispute.handle` (+ codes per field) · idempotent · audited `dispute.resolved`

```json
{ "outcome": "resume|against_sale",
  "reply": "10–2000",
  "compensation": { "party": "buyer|seller", "amount": "500.0000", "reason": "igi_delay|dahab_mistake|wasted_trip|dispute_settlement|goodwill", "note": "10–1000" } | null,
  "suspend_seller": { "reason": "piece_misrepresented|off_platform_dealing|repeated_disputes|reported_by_users|identity_unconfirmed|other", "note": "…|null" } | null }
```

One transaction: the order move (resume + deadline give-back, or cancel + deposit refund + seller return), compensation, suspension, the dispute resolved, audit; notifications after commit. `200 { data: <detail> }`.
Errors: `403 forbidden` (`against_sale` without `order.refund`; compensation without `compensation.pay`; suspension without `customer.suspend`; `suspend_seller` with `resume`), `403 compensation_cap_exceeded` (`details.per_payment`, `details.left_today`), `409 dispute_outcome_not_allowed` (`against_sale` on a paid order), `409 illegal_dispute_transition`, `422 reason_required` / `validation_failed`.

### `GET /extension-requests` — `order.extend_deadline` or `order.view` · keyset

Query `state=waiting|accepted|refused|lapsed|all` (default `waiting`), `month=YYYY-MM`.
Item: `{ "id", "order": { "id", "ref", "branch": { "id", "name" } }, "seller": { "id", "display_ref" }, "reason", "detail", "state", "deadline_now", "seconds_left", "extensions_before": 0, "requested_at", "answered_at", "answered_by", "hours_granted", "answer_note" }`.

### `POST /extension-requests/{request}/accept` — `order.extend_deadline` · idempotent

Body `{ "hours": 6|12|24|48, "note": "10–1000" }` → `200 { data: <item> }`. Runs the spec 012 extend (audited `order.deadline_extended`, both parties told) and audits `order.extension_request_accepted`.
Errors: `409 illegal_extension_request_transition`, `409 order_frozen`, `409 deadline_not_running` (deadline passed / order moved), `422 validation_failed`.

### `POST /extension-requests/{request}/refuse` — `order.extend_deadline` · idempotent

Body `{ "note": "10–1000" }` → `200`. Audited `order.extension_request_refused`; the seller told.

### `POST /orders/{order}/handover` — body extended (non-breaking: defaults keep spec 012 behaviour)

`{ "code": "123456", "collector": "buyer|proxy", "proxy_id_checked": true }`. `collector=proxy` with no proxy or without `proxy_id_checked: true` → `422 proxy_details_missing` (no attempt counted). `409 order_frozen`.

### `GET /orders/{order}/proxy-id` — `order.handover` or `order.view`

The decrypted ID photo; audited `order.proxy_id_viewed`. `404` when no proxy.

### `GET /orders/{order}` and `GET /orders` — additions (non-breaking)

Detail: `frozen`, `disputes: [{ "id", "ref", "raised_as", "state", "outcome" }]`, `extension_request` (latest, staff form), `proxy: { "name", "phone", "named_at", "collected_by_proxy" } | null`, `can { …, "handle_dispute", "answer_extension", "view_proxy_id" }`. List rows: `frozen`, `has_waiting_extension`.

## Errors (new codes)

| Code | HTTP | Where |
|---|---|---|
| `order_frozen` | 409 | any order action while `disputed` |
| `dispute_already_raised` | 409 | open, the party already raised one on the order |
| `dispute_outcome_not_allowed` | 409 | `against_sale` on a paid order |
| `illegal_dispute_transition` | 409 | DH009 |
| `assignee_not_eligible` | 422 | pass-on |
| `extension_request_pending` | 409 | a request already waiting |
| `illegal_extension_request_transition` | 409 | DH010 |
| `compensation_cap_exceeded` | 403 | already in Part 2 §12 |
| `proxy_details_missing` | 422 | already in Part 2 §12 |

`deadline_not_running` (spec 012) is reused for a request after the deadline.

## Classification

All new endpoints and added fields: **non-breaking**. **Potentially breaking**: customer and staff order `state` can now be `disputed` (clients map states — both apps updated); new `OrderEvent`/audit values; the permission union grows by four codes; the upload `purpose` enum grows by two.
