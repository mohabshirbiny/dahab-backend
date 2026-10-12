# Dahab API Contract

The conventions of the Dahab REST API, as implemented in `dahab-backend` (verified 2026-09-26).
This file **summarises** the contract; it does not replace it.

- **The contract is the Backend code**, documented by `#[OA\…]` attributes on controllers, Requests,
  Resources and `app/Support/*` DTOs, and generated into OpenAPI 3 by l5-swagger:
  `dahab-backend/storage/api-docs/api-docs.json` (gitignored — `composer swagger:generate`;
  Swagger UI at `/api/documentation`). **Treat the generated OpenAPI as the API contract.**
- Per-endpoint request/response examples: `dahab-backend/postman/Dahab-Backend.postman_collection.json`.
- Do not hand-write an `openapi.yaml`. `dahab-backend/specs/*/contracts/` are planning artefacts only.
- If this file disagrees with the code, the code wins — fix this file.

## Versioning

- All routes are under **`/api/v1`** (`routes/api.php`, `Route::prefix('v1')`; OpenAPI server `/api/v1`).
- Additive, non-breaking changes stay in `v1`.
- Per the Backend Constitution, a **breaking change ships under a new prefix** (`/api/v2`), and the old
  prefix is deprecated on a documented schedule.
- Precedent for retiring an endpoint inside v1: `POST /customer/auth/register/complete` is kept as a
  **410** stub returning `registration_endpoint_deprecated` (replaced by `/register/submit`).

## Surfaces and consumers

| Surface | Prefix | Guard / token | Consumers |
|---|---|---|---|
| Public | `/api/v1/health` | none | any |
| Public (spec 010) | `/api/v1/market/*`, `/api/v1/reference/*` | none; on `/market/*` a customer access token is optional and only sets `is_mine` | `dahab-flutter`, `dahab-dashboard` |
| Public (spec 013) | `/api/v1/withdrawal-confirmations/read`, `/confirm` | none; the email link's token is the key (throttled per IP) | `dahab-flutter` (the link's page) |
| Customer | `/api/v1/customer/*` | `auth:customer` (Sanctum, `customers` provider) | `dahab-flutter` |
| Dashboard | `/api/v1/dashboard/*` | `auth:staff` (Sanctum, `staff` provider) | `dahab-dashboard` |

A customer token is never valid on `/dashboard/*` and vice-versa (**401**). **Any change to a surface
must be checked against every consumer listed for it**; changes to shared conventions (envelope, error
codes, auth headers) affect all consumers.

## Authentication

Bearer tokens only (Sanctum personal access tokens). No cookie/SPA session auth — `statefulApi()` is
deliberately not enabled.

- **Token pair.** Sign-in issues a *session* object (`app/Support/SessionDto.php`):
  `token_type` (`Bearer`), `access_token`, `access_token_expires_at`, `refresh_token`,
  `refresh_token_expires_at`, `family_id`. Defaults: access 15 min, refresh 30 days
  (`config/dahab-auth.php`, env-overridable).
- **Abilities.** Access tokens carry `customer:access` / `staff:access`; refresh tokens carry
  `customer:refresh` / `staff:refresh`. The wrong kind on an endpoint → **403 `forbidden`**.
- **Refresh.** `POST /{customer|dashboard}/auth/refresh` with the **refresh token** as Bearer; rotates on
  every use (returns a new session). Rejected → **401 `refresh_invalid`**; clients must then sign out.
- **Customer sign-in.** `POST /customer/auth/login` requires the `X-Device-Id` header (also
  `X-Device-Platform`, default `web`). From a trusted device → `{ data: { customer, session } }`.
  From a new device → `{ data: { otp_required: true, otp_channel, challenge_id, expires_at, resend_available_at } }`;
  finish with `POST /customer/auth/otp/verify` (resend: `/otp/resend`) from the same device.
  Accounts in `pending_verification` / `rejected` / `suspended` are refused with their codes.
- **Customer registration** is six steps under `/customer/auth/register/*`
  (`start → verify-phone-otp → email → verify-email-otp → documents → submit`), state held server-side
  under an opaque `registration_ref`; only `submit` writes to the database.
- **Staff sign-in.** `POST /dashboard/auth/login` (email + password) → `{ data: { staff, session } }`, or,
  for MFA roles, `{ data: { … mfa_required | mfa_enrollment_required, session_ref, expires_at } }`;
  finish with `/dashboard/auth/mfa/verify` or `/mfa/enroll` (TOTP).
- **Founder devices (spec 021).** The Dashboard sends `X-Device-Id` (and `X-Device-Platform: web`) on every request. A
  **founder** must send it at sign-in (`422 device_id_required` otherwise). A founder on a device not yet known for them gets,
  after the password **and** MFA, a fourth keyed variant with **no token**:
  `{ data: { device_approval_required: true, approval_id, approval_ref, expires_at, poll_interval_seconds } }` (on `mfa/verify`,
  `mfa/enroll`, or `login` when MFA is off locally). The device polls `POST /dashboard/auth/device-approval/status` until the
  other founder approves (the first poll after approval returns `staff` + `session`, once) or refuses. Known devices,
  non-founders and an approved device sign in as before. A founder's first device is trusted on the server with
  `php artisan founder:trust-device <email> <device-id>`.
- `GET …/auth/me`, `POST …/auth/logout`, `POST …/auth/logout-all` exist on both surfaces.

## Authorization

- **Customer**: a customer can act only on its own resources (`/customer/me/*`).
- **Public market** (spec 010): served in a read-only `market` database scope — live/reserved listings and their public media only — and returns no seller field (no database view; `MarketLeakTest` guards it).
- **Buy requests** (spec 011): a buyer reaches only their own requests (`buy_request` RLS), a seller only the line on
  their own listing (404 otherwise), where each buyer appears as `display_ref` only; an order is visible to its buyer
  and seller. Queue operations run in the non-elevated `queue` database scope (`QueueScopeTest`, `BuyRequestLeakTest`).
- **Orders** (spec 012): a customer reaches only orders they are the buyer or seller of (`"order"` and its eight
  tables under RLS, 404 otherwise); the other party appears as `counterparty_ref` (a display reference) only; a
  collection or return code appears only in the owner's own order detail. A party's write that reaches the other
  party's rows runs in the non-elevated, **audited** `order` scope (`OrderScopeTest`). Staff branch actions (receive,
  inspection result, handover) are limited to the staff member's assigned branch — `403 wrong_branch`, audited — and
  every answer an inspector can reach carries no money and no names (`InspectorLeakTest`).
- **Staff**: permission-based via Spatie Permission, enforced per route by the
  `staff.permission:<code>` middleware (`EnforceStaffPermission`), which returns **403 `permission_denied`**
  and audit-logs the denial. The permission catalogue is `app/Enums/StaffPermission.php` (customers, identity,
  access control, reference data, pricing, audit, and — since spec 008 — `wallet.view`, and — since spec 009 —
  `topup.match` and `topup.accounts.manage`, all seeded to `ceo` and `finance`, never `coo`: wallet-touching codes
  skip the COO by default; and — since spec 010 — `listing.review`, `listing.request_changes` and `listing.takedown`,
  seeded to `ceo`, `coo` and `operations`; the review queue and a listing open with any of the three; and — since
  spec 011 — `order.cancel` (cancel an acceptance, refunding the buyer), seeded to `ceo`, `coo` and `operations`;
  and — since spec 012 — `order.view` (coo, finance, operations), `order.receive` (coo, operations, igi_branch),
  `inspection.enter` (igi_branch), `order.price_adjust`, `order.change_branch`, `order.extend_deadline` and
  `buy_request.view` (coo, operations), `order.handover` (igi_branch); the work list opens with any of
  `inspection.enter|order.receive|order.handover`, the inspection results with `inspection.enter|order.view`); and — since
  spec 013 — `withdrawal.release` (the Withdrawals queue and every action on it, the export; CEO, Finance, never COO; the
  list and a withdrawal also open read-only, numbers masked, with `wallet.view`) and `payout_account.verify` (verify or refuse
  a payout account; CEO, Finance, Verification); and — since spec 014 — `dispute.handle` (the Disputes queue, detail, photos,
  pass on and resolve; CEO, COO, Operations, Finance), `order.refund` (resolve a dispute against the sale; CEO, Finance),
  `compensation.pay` (compensation within a resolution, up to the caps; CEO, Finance) and `compensation.uncapped` (lifts the
  caps; no role — the CEO holds every code); the extension requests use `order.extend_deadline` (`order.view` reads), the
  proxy ID `order.handover` or `order.view`, *Suspend the seller* on a resolution `customer.suspend`; and — since spec 015 —
  `wallet.adjust` (adjust a customer's wallet; no role — the founders hold every code), `bank.record` (record a bank movement,
  the proof upload; Finance) and `day.close` (close a day; Finance); `compensation.pay` also pays outside a dispute. Reads:
  compensation `compensation.pay|wallet.view`, adjustments `wallet.adjust|wallet.view`, bank book and movements
  `bank.record|wallet.view`, the daily close `day.close|wallet.view`; `GET /dashboard/overview` needs no code and returns only
  the sections the viewer's codes allow. Since spec 016: `invoice.view` (invoices, credit notes, their PDFs, the export) and
  `invoice.correct` (issue a credit note) — both Finance (founders hold every code), never the COO.
  Roles (`app/Enums/StaffRole.php`): `ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch`.
- Frontends gate UI on the **permission strings** from `GET /dashboard/auth/me`, never on role names.
  A new permission is a contract change: update `StaffPermission`, seeders, the route, and the Dashboard's
  `src/types/staff.ts` / `usePermissions` / route guards.

## HTTP methods and resource naming

- Methods in use: `GET` (read), `POST` (create and actions), and `PUT` / `PATCH` / `DELETE` on a few resources
  (e.g. `PATCH /customer/me/listings/{id}`, spec 010).
- Paths are lower-case, kebab-case, plural nouns: `/dashboard/customers`, `/dashboard/identity-documents/{document}`.
- State transitions are sub-resource actions via `POST`: `/identity-documents/{document}/review`,
  `/auth/logout`, `/auth/otp/verify`.
- Route parameters are **UUIDs** (`whereUuid`). Customer "own" resources live under `/customer/me/*`.
- Route names mirror paths: `api.v1.<surface>.<resource>.<action>`.
- Field names are **`snake_case`** in requests and responses. Frontends map to their own casing in
  their service/model layer, never in UI components.

## Request format

- JSON bodies (`Content-Type: application/json`), `Accept: application/json`.
- File uploads are `multipart/form-data` (`/customer/auth/register/documents`, `/customer/me/uploads`). Upload
  purposes: `identity` (JPEG/PNG/WebP, open to unverified customers) and, since spec 009, `topup_receipt`
  (JPEG/PNG/WebP/PDF, customer gate `trade`), and since spec 010 `listing_photo` (JPEG/PNG/WebP, 8 MB),
  `listing_video` (MP4/MOV/WebM, 50 MB), `listing_invoice` and `stone_certificate` (JPEG/PNG/WebP/PDF, 8 MB), all gate
  `trade`. Listing media is served back by streaming media endpoints with `Cache-Control: no-store`.
- Validation lives in `app/Http/Requests/*` FormRequests; enums are validated with `Rule::enum(...)`.
- Customer requests send `X-Device-Id` and `X-Device-Platform` (both frontends do this on every request).

## Response format

| Case | Status | Body |
|---|---|---|
| Single resource / action result | 200 (201 on create) | `{ "data": { … } }` |
| Collection, paginated | 200 | `{ "data": [ … ], "links": { … }, "meta": { … } }` |
| No content (logout, logout-all) | 204 | empty |
| Error | 4xx/5xx | `{ "message": "…", "code": "…", "errors"?: { … }, …extra }` |

`App\Support\ApiResponse::ok()` builds `{ data, meta? }`; Laravel API Resources build the same envelope.

**Money** is always a decimal **string** with 4 places (`"56760.0000"`, EGP), never a JSON number, to keep the
`NUMERIC(18,4)` precision the ledger depends on. Clients format it for display and never compute with it (spec 008).
Ledger event kinds are sent as stable codes (`topup`, `deposit_hold`, …); the Dashboard also gets a staff `label`, the
Customer App localises the code itself. Since spec 009 a `topup` row's `reference` is its top-up number `TOP-{n}`
(in the customer's history and the Wallet statement); other kinds stay `null` until orders and listings exist.
Amounts people type (top-up notices, matches) are accepted with at most 2 decimals and returned with 4.
A top-up's `expected_amount` is a display-only estimate (claim minus the provider fee snapshotted on the notice when it
was filed, `notice_fee_percent`); it never decides what is credited — staff credit what actually arrived.

Since spec 014 an order's `state` can be `disputed` (frozen: no deadline runs, no money moves, every other action answers
`order_frozen`). The customer order shape adds `frozen`, `dispute` (only the caller's own), `dispute_outcome`
(`resumed|cancelled`, from the order's history), `extension_request` (seller only) and `proxy` (buyer only, phone masked);
the upload `purpose` gains `dispute_photo` (verified, not trade-gated) and `proxy_id` (trade).

Since spec 013 a `withdrawal` history row's `reference` is its number `WD-{n}`, and every wallet figure that shows `held`
also shows its split: `held_on_orders` + `pending_withdrawals` (withdrawals requested or under review) = `held`. A payout
account number is shown masked (`•••• 4417`) except to its owner's staff readers holding `payout_account.verify` or
`withdrawal.release`; customers only ever get it masked.

**Weights** are decimal strings with 3 places (grams). A listing's weight is `stated_weight_g` in requests and in the
seller and staff shapes, and `weight_g` in the public market shapes (spec 010). A listing's `current_price` and
`you_would_receive` are indicative, recomputed on every read from the price calculator, `null` when no price can be
quoted (`price_available: false`).

## Pagination

- Laravel length-aware pagination through `Resource::collection($paginator)`:
  `meta` has `current_page`, `from`, `last_page`, `per_page`, `to`, `total` (+ `path`, `links`);
  `links` has `first`, `last`, `prev`, `next`.
- Query params: `page`, `per_page` (integer 1–50, default 25). Current paginated endpoints:
  `GET /dashboard/customers`, `GET /dashboard/identity-documents`.
- **Keyset (cursor) pages** for append-only or fast-growing lists: `meta` has `per_page` and `next_cursor`
  (opaque; `null` on the last page), the client passes `cursor=<next_cursor>`; a malformed cursor is `422`.
  Used by the audit log and customer History (specs 006/007), the wallet history and Wallet statement (spec 008), and
  every listing list — market, seller, review queue (spec 010).
  A cursor only positions the page — every figure is recomputed server-side.
- Filters are optional query params validated by the list FormRequest (e.g. `status` as an enum).
- The Dashboard types this as `ApiPageMeta` in `src/types/api.ts`.

## Validation errors

**422**, `code: "validation_failed"`:

```json
{ "message": "The phone field is required.", "code": "validation_failed",
  "errors": { "phone": ["The phone field is required."] } }
```

Keys in `errors` are request field names (`snake_case`). Clients show them per field
(Flutter: `ApiException.fieldErrors`; Dashboard: `services/errors.ts`).

## Error responses

Rendered centrally in `dahab-backend/bootstrap/app.php` for every `api/*` request. **`code` is the stable,
machine-readable value clients must switch on**; `message` is human text and may change.

- Auth codes: `app/Enums/AuthErrorCode.php` (`unauthenticated`, `invalid_credentials`, `account_locked`,
  `account_suspended` (+ `suspended_reason`), `account_frozen`, `account_pending_verification`,
  `account_rejected`, `permission_denied`, `otp_invalid`, `otp_expired`, `mfa_invalid`, `refresh_invalid`,
  `registration_*`, …).
- Domain codes: `app/Exceptions/DomainApiException.php` (`document_already_pending` 409,
  `illegal_document_transition` 409, `unsupported_doc_kind` 422, `upload_token_invalid` 422,
  `customer_already_suspended` 409, `customer_not_suspended` 409, the `idempotency_*` codes below,
  `insufficient_funds` 409 and `ledger_already_reversed` 409 from the money service (spec 008),
  `illegal_topup_transition` 409 — a top-up status move not allowed, or a lost race (spec 009); from spec 010:
  `illegal_listing_transition` 409 (a listing move not allowed, or a lost race), `listing_not_editable` 409,
  from spec 022: `listing_has_requests` 409, `listing_price_locked` 409, `listing_price_history_immutable` 409 (SQLSTATE DH023),
  and `listing_not_live` is reused for a price change on a piece that is not live,
  `seller_suspended` 409 and `karat_disabled` 409 (approving a listing), `gold_needs_karat_weight` 422,
  `branch_options_required` 422, `ownership_declaration_required` 422, `photo_required` 422; from spec 011:
  `price_moved` 409, `price_unavailable` 409, `already_in_queue` 409, `listing_not_purchasable` 409,
  `cannot_buy_own_listing` 409, `deposit_agreement_required` 422, `not_in_queue` 409, `not_queue_head` 409,
  `queue_empty` 409, `branch_not_in_options` 409, `buyer_suspended` 409, `branch_hours_unavailable` 409,
  `order_not_cancellable` 409, `illegal_buy_request_transition` 409; from spec 012: `illegal_order_transition` 409
  (an order move not allowed, or a lost race — SQLSTATE DH006, previously answered `illegal_buy_request_transition`),
  `wrong_branch` 403, `inspection_correction_not_allowed` 409, `price_not_set` 409, `balance_deadline_passed` 409,
  `settlement_not_possible` 409, `invalid_collection_code` **422** (not 401: a 401 makes the Dashboard sign out),
  `handover_locked` 429, `order_not_open` 409, `deadline_not_running` 409, `deadline_must_move_forward` 422; from spec 013:
  `email_confirmation_required` 403, `confirmation_invalid` 422, `declaration_required` 422, `withdrawals_paused` 409,
  `payout_account_not_active` 409, `withdrawal_on_hold` 409, `illegal_withdrawal_transition` 409 (SQLSTATE DH007),
  `illegal_payout_account_transition` 409 (SQLSTATE DH008); from spec 014: `order_frozen` 409 (any order action while the order is
  `disputed`), `dispute_already_raised` 409, `dispute_outcome_not_allowed` 409, `illegal_dispute_transition` 409 (SQLSTATE DH009),
  `assignee_not_eligible` 422, `extension_request_pending` 409, `illegal_extension_request_transition` 409 (SQLSTATE DH010),
  `compensation_cap_exceeded` 403, `proxy_details_missing` 422; from spec 015: `day_not_ended` 422, `day_already_closed` 409; from spec 016: `invoice_not_creditable` 409,
  `credit_exceeds_invoice` 422 (`details.remaining`), `document_not_ready` 409, SQLSTATE DH012; from spec 017:
  `contact_taken` 409, `same_contact` 422, `change_code_invalid` 422 (`details.tries_left`), `change_code_locked` 429,
  `change_link_invalid` 410, `current_password_wrong` 422, `current_session` 422, `account_closed` (403 at sign-in, 409 from
  SQLSTATE DH013), `account_has_open_items` 409 (`details.blockers`), `listing_not_saveable` 422, `saved_limit_reached` 422
  (`details.limit`), `listing_not_reportable` 422, `report_already_open` 409, `report_not_open` 409 (SQLSTATE DH015),
  `notification_immutable` 409 (SQLSTATE DH014); from spec 018: `free_relist_expired` 409, `already_relisted` 409,
  `rating_not_available` 409, `rating_closed` 409, `already_rated` 409, `rating_immutable` 409 (SQLSTATE DH016); from spec 019:
  `content_draft_changed` 409 (`details.draft_version`), `content_key_read_only` 409, `content_key_retired` 409,
  `content_no_draft` 404, `content_invalid_placeholder` 422 (`details.placeholders`), `content_missing_required_placeholder` 422,
  `content_language_missing` 422 (`details.languages`), `content_too_long` 422 (`details.max_length`), `content_unchanged` 422,
  `content_nothing_to_publish` 422, `content_invalid` 422 (`details.keys`: per key `{code, message}`),
  `content_history_immutable` 409 (SQLSTATE DH017), `content_faq_exists` 409, `content_faq_unknown` 404,
  `legal_acceptance_required` 409 (`details.required`), `legal_document_not_current` 422, `legal_acceptance_not_required` 409,
  `legal_body_required` 422, `legal_code_unknown` 404, `legal_version_conflict` 409, `legal_document_immutable` 409 (SQLSTATE DH018),
  `category_stopped` 409, `category_paused` 409, `platform_stopped` 409 (each with `details.message {en, ar}`),
  `control_already_active` 409, `control_not_active` 409, `control_category_required` 422,
  `control_history_immutable` 409 (SQLSTATE DH019), `setting_not_editable_here` 422, `promo_kind_not_available` 422,
  `promo_customer_not_market_maker` 422, `promo_code_exists` 409, `already_approved` 409, `listing_too_new` 422,
  `listing_not_live` 409, `promo_code_invalid` 422 (`details.reason`: inactive, not_your_code, not_approved, too_new,
  cap_reached), `market_maker_only` 403, `promo_history_immutable` 409 (SQLSTATE DH020); from spec 020: `reset_link_invalid` 422 (the staff set-password
  link is unknown, used, voided, expired, or its member is disabled), `founder_protected` 403, `staff_already_disabled` 409,
  `staff_not_disabled` 409; from spec 021: `device_id_required` 422, `device_approval_refused` 403, `device_approval_invalid` 401,
  `device_approval_not_pending` 409 (SQLSTATE DH021), `device_not_known` 403, `founder_only` 403, `founder_self_action` 403,
  `freeze_target_invalid` 422, `already_frozen` 409, `not_frozen` 409, `unfreeze_order` 409, `unfreeze_already_confirmed` 409
  (SQLSTATE DH022), …).
- Generic: `forbidden` (403, wrong token ability or HTTP 403), `not_found` (404), `method_not_allowed`
  (405), `too_many_requests` (429, with `Retry-After`), `server_error` (500; message hidden unless debug).
- Extra top-level keys may accompany an error (e.g. `resend_available_at`, `suspended_reason`); clients
  should tolerate unknown keys. Since spec 011 a domain error may carry `details`, an object of decimal strings the
  client needs to act: `insufficient_funds` on a buy request `{ deposit_amount, available, shortfall }`,
  `price_moved` `{ current_price, deposit_amount }`; since spec 012 `insufficient_funds` on pay-balance
  `{ amount_due, available, shortfall }`, `invalid_collection_code` `{ attempts_left }`, `handover_locked`
  `{ retry_after }` (seconds); since spec 013 `insufficient_funds` on a withdrawal `{ available, shortfall }` and
  `withdrawals_paused` `{ pause_until }`; since spec 014 `order_frozen` `{ dispute_ref }` (only to the customer who raised the
  dispute, and to staff) and `compensation_cap_exceeded` `{ per_payment, left_today }`.

## Account recovery and staff access (spec 020)

Seven endpoints; exact shapes in `specs/020-account-recovery-and-staff-access/contracts/account-recovery-api.md` and the generated OpenAPI.

| Endpoint | Auth · permission | Notes |
|---|---|---|
| `POST /customer/auth/password/reset-request` | public | `202` always the same body; throttle `auth.password_reset.request` (3/h per phone, 10/h per device, 20/h per IP) |
| `POST /customer/auth/password/reset-confirm` | public | `200`; `otp_invalid` / `otp_expired` 401; throttle `auth.password_reset.confirm`; no token issued |
| `POST /dashboard/auth/password/reset-request` | public | `202` always the same message; same throttle as the customer request |
| `POST /dashboard/auth/password/reset-confirm` | public | `200`; `reset_link_invalid` 422; throttle `auth.staff.password_reset.confirm` (20/h per IP) |
| `POST /dashboard/staff` | `staff.manage`, `Idempotency-Key` | `201`; no password; invitation link mailed; `reason` required |
| `POST /dashboard/staff/{staff}/disable` | `staff.manage`, `Idempotency-Key` | `403 escalation_denied` (yourself) / `founder_protected`, `409 staff_already_disabled` / `last_role_manager` |
| `POST /dashboard/staff/{staff}/enable` | `staff.manage`, `Idempotency-Key` | `409 staff_not_disabled`; the old password is deleted, a 48-hour link is mailed |

Additions to existing contracts: `GET /dashboard/staff?status=active|disabled` and `password_set` on the staff member
(non-breaking); `password_reset` in the customer file's `withdrawal_pause.trigger_kind` (potentially breaking for exhaustive maps;
the Dashboard was updated in the same change). New permission `staff.manage` (CEO only, no seed role). New error codes:
`reset_link_invalid` 422, `founder_protected` 403, `staff_already_disabled` 409, `staff_not_disabled` 409.

## Founder-account security and Dashboard notices (spec 021)

Twelve endpoints; exact shapes in `specs/021-founder-security/contracts/founder-security-api.md` and the generated OpenAPI.

| Endpoint | Auth · gate | Notes |
|---|---|---|
| `POST /dashboard/auth/device-approval/status` | public (`approval_ref` + the same `X-Device-Id`) | `200 {status: pending}` or `{status: approved, staff, session}` (first poll only); `403 device_approval_refused`; `401 device_approval_invalid`; throttle `auth.staff.device_approval` (30/min per ref, 60/min per IP) |
| `POST /dashboard/auth/frozen/confirm-unfreeze` | public (email + password + TOTP or recovery code, from a known device) | the frozen founder's slot 1; **never a token**; checks in order `invalid_credentials` → `not_frozen` → `device_id_required` / `device_not_known` → `mfa_invalid` → `unfreeze_already_confirmed`; sign-in and MFA limiters |
| `GET /dashboard/founder/device-approvals` | `staff.founder` | every founder's requests; `status=pending|approved|refused|expired|all`; `can_decide`, `meta.pending_for_me` |
| `POST /dashboard/founder/device-approvals/{id}/approve` · `/refuse` | `staff.founder`, `Idempotency-Key` | optional `note`; `403 founder_self_action`, `409 device_approval_not_pending` |
| `GET /dashboard/founder/devices` | `staff.founder` | each founder's known devices by `device_ref` (8 hex characters) |
| `POST /dashboard/founder/freeze` | `staff.founder`, `Idempotency-Key` | `201`; `reason` required; every token of the target deleted; `422 freeze_target_invalid`, `403 founder_self_action`, `409 already_frozen` |
| `GET /dashboard/founder/freezes` | `staff.founder_or:founder.unfreeze_second` | `state=open|all`; `can_confirm`, `my_slot` |
| `POST /dashboard/founder/unfreeze` | `staff.founder_or:founder.unfreeze_second`, `Idempotency-Key` | one confirmation; the server picks the slot (a founder → 2, last; a holder → 1); `409 unfreeze_order` / `unfreeze_already_confirmed` / `not_frozen` |
| `GET /dashboard/notices` | any staff | own notices, newest first; `unread=1`; `meta.unread_count` |
| `POST /dashboard/notices/{id}/read` · `/read-all` | any staff | someone else's notice → `404` |

`staff.founder` gates on `staff.is_founder` (a flag, never a role) → `403 founder_only`; `staff.founder_or:<permission>` also lets
holders of that permission through → `403 permission_denied` otherwise. New permission `founder.unfreeze_second` (the CEO role
holds it; no other seed role). Every active founder — the actor and a frozen one included — gets an email and a Dashboard notice
for the Story 3 actions done by a founder and for every founder security event, and an SMS for a freeze; a notice never carries
a password, code, poll ref, device id, amount or setting value. The `auth.staff.sign_in` audit payload's `via` gains
`device_approval`.

Classification: the new variant on `mfa/verify` / `mfa/enroll` and `422 device_id_required` for founders are **potentially
breaking** (the Dashboard was changed in the same release and must ship with the Backend); everything else is non-breaking.
The Customer app is not affected.

## Idempotency

Added by feature 007 (`App\Http\Middleware\EnforceIdempotency`, route alias `idempotent`, table
`idempotency_key`). A route marked `idempotent: required` in `#[OA]` / Part 2 needs an
`Idempotency-Key` header: a client-generated UUID, one per user action.

| Situation | Result |
|---|---|
| Header missing, or not a UUID | `400 idempotency_key_required` |
| First use of the key | The request runs; a response < 500 is stored as sent, for 24 h |
| Same key, same request, finished | The stored status and body, with `Idempotent-Replayed: true`; nothing runs again |
| Same key, different body or target | `422 idempotency_key_mismatch` (never the other request's response) |
| Same key, still running (< 60 s) | `409 idempotency_in_progress` |
| Earlier attempt ended in 5xx | The request runs again |

Keys are scoped per actor (customer or staff) and per route. Clients reuse the key only when **retrying
the same submission**, and use a new key when the input changes (Dashboard: `composables/useIdempotencyKey.ts`).

In use on: `POST /dashboard/customers/{id}/suspend`, `POST /dashboard/customers/{id}/reinstate`, and since spec 009
every top-up POST: `POST /customer/me/wallet/topups`, `POST /customer/me/wallet/topups/{id}/cancel`,
`POST /dashboard/topups` (credit by hand) and `POST /dashboard/topups/{id}/match|hold|unhold|reject`; and since spec 010
every listing write: `POST /customer/me/listings`, `PATCH /customer/me/listings/{id}`,
`POST /customer/me/listings/{id}/submit|withdraw` and `POST /dashboard/listings/{id}/approve|request-changes|reject|takedown`
(since spec 022 also `POST /customer/me/listings/{id}/price`);
and since spec 011 every buy-request write: `POST /customer/me/buy-requests`, `POST /customer/me/buy-requests/{id}/withdraw`,
`POST /customer/me/listings/{id}/accept|decline` and `POST /dashboard/orders/{id}/cancel`; and since spec 012 every
order write: `POST /customer/me/orders/{id}/cancel|decision|pay-balance|relist` and
`POST /dashboard/orders/{id}/receive|inspection-results|propose-price|change-branch|extend-deadline|handover|seller-return/handover`;
and since spec 013 every payout-account and withdrawal write: `POST /customer/me/payout-accounts`,
`POST /customer/me/payout-accounts/{id}/use|remove|keep`, `POST /customer/me/withdrawals/confirmations`,
`POST /customer/me/withdrawals`, `POST /customer/me/withdrawals/{id}/cancel`, `POST /dashboard/withdrawals/{id}/review|hold|unhold|release|reject`
and `POST /dashboard/payout-accounts/{id}/verify|refuse`. The public `POST /withdrawal-confirmations/confirm` has no key: its
token is single use. Since spec 014: `POST /customer/me/orders/{id}/disputes|extension-requests|proxy|proxy/remove`,
`POST /dashboard/disputes/{id}/pass-on|resolve` and `POST /dashboard/extension-requests/{id}/accept|refuse`. Since spec 015:
`POST /dashboard/compensation`, `POST /dashboard/customers/{id}/wallet-adjustments`, `POST /dashboard/bank-movements` and
`POST /dashboard/daily-close` (the staff upload `POST /dashboard/uploads` returns a single-use token, like the customer's).
Since spec 016: `POST /dashboard/invoices/{id}/credit-notes`. Since spec 017: `POST /customer/me/phone-change`,
`/phone-change/{id}/confirm`, `/email-change`, `/password`, `/sessions/{id}/sign-out`, `/notifications/{id}/read`,
`/notifications/read-all`, `/saved-pieces`, `/account/close`, `/listing-reports`, and `POST /dashboard/listing-reports/{id}/dismiss|take-down`.
The public `POST /contact-changes/email/confirm` has no key: its link is single use. Since spec 018:
`POST /customer/me/orders/{id}/free-relist` and `POST /customer/me/orders/{id}/rating`.

## Status codes in use

`200` OK · `201` Created · `204` No Content · `401` unauthenticated / bad credentials / bad refresh ·
`403` permission or state refusal · `404` · `405` · `409` conflicting state · `410` deprecated endpoint ·
`422` validation / invalid input · `429` rate limited · `500`.

## Rate limiting

Named limiters on sensitive routes (`throttle:auth.customer.login`, `auth.customer.register`,
`auth.customer.register.otp`, `auth.customer.register.documents`, `auth.otp.verify`, `auth.staff.login`,
`auth.staff.mfa`, `auth.refresh`, `customer.uploads` (20/min since spec 010), `customer.topups`, `customer.listings`,
`customer.buy_requests` (10/min, spec 011), `public.market`, and since spec 013 `customer.payout_accounts` (5/min),
`customer.withdrawals` (10/min), `public.withdrawal_confirmations` (10/min per IP), and since spec 014 `customer.disputes` (5/min), and since spec 015 `dashboard.uploads` (20/min per staff member)) plus the default API throttle. Identity lockouts
return **429 `account_locked`**.

## CORS

`config/cors.php`: `api/*`, origins from `CORS_ALLOWED_ORIGINS` (comma-separated; `*` if unset).
Each frontend's dev origin must be listed (Dashboard Vite :3000, Flutter web :8765).

## Backward-compatibility rules

1. Adding an optional response field, a new endpoint, or an optional query param is **non-breaking**.
2. Adding an enum value, removing a field, changing type/nullability, adding a required input or a new
   validation rule, or tightening auth/permissions is **potentially breaking** — check every consumer.
3. Renaming a field, changing a URL/method, the envelope, or an error `code` is **breaking** → new
   version prefix, per the Constitution, and only after the user approves.
4. Never change the meaning of an existing `code`. Add new codes instead.
5. Clients must ignore unknown response fields and handle unknown enum values gracefully.
6. Retire endpoints with a stub (e.g. 410 + explicit code) before removal, and note it here.

## Every API change — checklist

1. Backend: FormRequest / Resource / Action / controller `#[OA]` / Pest tests / Postman request.
2. `composer swagger:generate`.
3. Search **both** frontends (Dashboard for `/dashboard/*`, Flutter for `/customer/*`) for the path, fields (both casings),
   enum values and permission strings; update or report every consumer.
4. Update this file if a convention (not just an endpoint) changed.

## CSV exports and public price reads (spec 015)

- Every staff CSV export (`/dashboard/wallet-statement/export`, `/topups/export`, `/withdrawals/export`, `/audit-log/export`,
  and since spec 015 `/orders/export`, `/compensation/export`, `/bank-movements/export`, `/bank-book/export`; since spec 016
  `/invoices/export`) takes the same
  filters as its list, is UTF-8 with a BOM, neutralises spreadsheet formulas, stops at a configured row cap with a closing
  line (and `X-Export-Truncated: true`), and is audited with the filters and the row count.
- Staff uploads (spec 015): `POST /dashboard/uploads` (multipart `purpose`, `file`) → `{ token, expires_in }`; the token is
  single use, tied to the staff member and the purpose (`bank_movement_proof`), and is passed to the POST that uses it.
- Public price reads (spec 015): `GET /reference/gold-prices` and `GET /reference/quote` are unauthenticated, limited by
  `public.market`, cacheable for 30 s, and answer `price_unavailable` (409) when no usable price exists. Figures are
  indicative; nothing is locked until a buy request.

## Documents and tax invoices (spec 016)

- Binary documents: `GET …/pdf` answers `200 application/pdf` with `Content-Disposition: attachment; filename="<number>.pdf"`,
  or `409 document_not_ready` while the document is being generated (it is built after the money transaction commits). Staff
  views are audited; a customer's own downloads are not.
- Customer surface: `/customer/me/invoices*` and `/customer/me/credit-notes/{id}/pdf` (gate `verified`); the customer order
  carries `invoice {id, number} | null` (the caller's own invoice); wallet movements carry `invoice_id` and may have the kind
  `credit_note` — consumers must keep a fallback label for unknown kinds.
- Dashboard surface: `/dashboard/invoices*`, `/dashboard/credit-notes*`. Invoice status (`issued | partly_credited | credited`)
  is derived from the credit notes. No Tax Authority status exists in any response (not integrated).

## The customer account and the inbox (spec 017)

- Customer status gains `closed` (final): sign-in, the new-device code and refresh answer `403 account_closed`; nothing new is
  created for a closed customer (SQLSTATE DH013 → `409 account_closed`). Consumers map every status, with a fallback.
- Contact changes are security events: a phone change proves the new number with an SMS code (`/customer/me/phone-change*`),
  an email change with a single-use link opened on the public page (`/contact-changes/email/read|confirm`, token in the body).
  Both may cancel withdrawals not yet released and open a withdrawal pause (`trigger_kind` `phone_change | email_change`), and
  always tell the old contact.
- The inbox (`/customer/me/notifications*`) is a third channel next to SMS and email. An item is generic and open for later
  specs: `{id, type: "<area>.<event>", params, link: {kind, id}, title, body, title_en, title_ar, body_en, body_ar, created_at,
  read_at}`. Link kinds: `order, listing, buy_request, wallet, withdrawal, payout_account, topup, invoice, credit_note,
  dispute, account, none` (`id` null for `wallet`, `account`, `none`). Codes, collection codes and confirmation links never
  appear in an item. Consumers ignore unknown types and link kinds (show the text, open nothing).
- Limits: phone/email change requests 3 an hour; code confirmations and password changes 5 per 15 minutes; sign-outs 10 a
  minute; listing reports 10 a day; the public link page 10 a minute per IP.
- Reference: `GET /reference/legal-documents` (the four codes with `published`) and `GET /reference/support-contacts`.

## After collection: the free relist and the rating (spec 018)

- The buyer of a collected piece may relist it at once with no commission: `POST /customer/me/orders/{order}/free-relist`
  (trade gate, `Idempotency-Key`) creates a **new** live listing, owned by the buyer and linked to the order. The window is
  `free_relist.ends_at` on the customer order (`status` `none | open | used | expired`; consumers keep a fallback for an unknown
  status). The server decides; a client countdown is only a display. The listing's `relisted_from_order` (`{id, order_ref}`)
  marks it as a free relist.
- A sale of a free relist has commission, its VAT and the minimum at 0 (the buy/sell spread still applies) and only the buyer's
  invoice. The seller's order carries `no_fee: true` and `invoice: null`; the listing's `you_would_receive` already shows it.
- `POST /customer/me/orders/{order}/rating` (verified gate; a suspended customer may rate): `stars` 1–5 and an optional `note`
  (≤ 500). One per party and order, immutable. A customer reads only their own (`rating` on the order); staff read both with
  `rating.view` (`ratings` is absent from the order detail without it). Limit: 10 a minute.
- Dashboard: `GET /dashboard/orders?free_relist=open|used|expired` (each row has `free_relist_status`; the CSV export honours the
  filter), order detail `free_relist {status, ends_at, listing: {id, title}}` and `relisted_from_order`. No staff endpoint
  creates or changes an offer or a rating. Audit events `order.free_relisted` and `order.rated` (stars only; the latter is
  visible with `rating.view`).
- Non-breaking: new endpoints, optional fields, an optional filter and one new permission string (`rating.view`).

## App text: the published bundle and the App text page (spec 019, first release)

- `GET /reference/app-texts` (public, `throttle:public.market`) returns what staff published for the customer app:
  `data { tag, version, published_at, texts { "<key>": { en, ar } }, faq [ { key, position, question {en,ar}, answer {en,ar} } ],
  settings { "<placeholder>": "<value>" } }`. Only overrides of the kinds `app` and `faq` are served: never a draft, a
  notification template, a retired key or a key whose override was reverted (the app shows its own default). `settings` holds
  the live value of every setting placeholder (`commission_gold_pct`, `commission_minimum_egp`, …) as people write the number.
- **Version tag.** The `ETag` header (also `data.tag`, for browsers that do not expose it — CORS exposes `ETag`) is
  `"<bundle_version>-<sha1 of the setting values>"`: it changes when staff publish **or** a number a text may state changes.
  `If-None-Match` with the tag answers `304` with no body. Clients keep the last bundle, ask on start, on return to the
  foreground and every 15 minutes, and use their own text on any failure, an unknown key, a missing language or a
  placeholder they cannot fill. A `404` from an older Backend means "no overrides".
- Dashboard (`content.edit`): `GET /dashboard/app-texts` (filters `kind`, `area`, `q`, `changed`, `state`; keyset `cursor`,
  `per_page`), `PUT|DELETE /dashboard/app-texts/{key}/draft`, `POST /dashboard/app-texts/{key}/revert`
  (`to: "default"` or a version), `POST /dashboard/app-texts/publish`, `GET /dashboard/app-texts/{key}/history`,
  `GET /dashboard/app-texts/export` (CSV), `GET /dashboard/content/placeholders`. Every write takes an `Idempotency-Key` and is
  audited (`content.draft_saved`, `content.draft_discarded`, `content.reverted`, `content.published`, `content.exported`) with
  the version it replaced.
- Texts are plain text: `{snake_case}` placeholders only, from the key's own list or the setting list. Keys are readable and
  stable (`order.free_relist.card_title`) and are never renamed.
- Non-breaking: new endpoints and one new permission string (`content.edit`).

## Help, legal text, switches, market makers and People to watch (spec 019, later phases)

- **Help.** `POST /dashboard/faq` creates an entry (two keys, each with a draft, at the end of the list);
  `PATCH /dashboard/faq/{slug}/position` moves it as a draft (`pending_position` on the entry's keys); both need
  `content.edit` and an `Idempotency-Key`. A reorder, like any text, goes live with *Publish changes*. Hiding an entry is
  `is_hidden` on the answer's draft. The public bundle's `faq[]` is the published, not hidden entries, in order.
- **Code and link messages (D7).** A read-only template can be edited by a holder of `content.edit_secure` (no seeded role)
  and must keep its `{code}` or `{link}`; without it, `409 content_key_read_only`. `read_only` in `GET /dashboard/app-texts`
  means *locked for the viewer*.
- **Legal.** `GET /dashboard/legal-documents`, `GET …/{code}/versions`, `POST …/{code}/versions` (`legal.publish`: the CEO;
  reads also `content.edit`). `GET /customer/me` carries `legal_acceptance_required`; every state-changing customer route
  answers `409 legal_acceptance_required` while one is owed (reads, sign-out and `POST /customer/me/legal-acceptances` pass).
  `GET /reference/legal-documents` keeps `data[]` and adds `declarations[]`. Registration accepts
  `terms_legal_doc_id` / `privacy_legal_doc_id`.
- **Switches.** `GET|POST /dashboard/category-controls`, `POST /dashboard/category-controls/clear`,
  `GET /dashboard/visibility`, `PATCH /dashboard/visibility/{key}`; public `GET /reference/controls`
  (`stop_everything {active, message}`, `categories[]`, `visibility {rapaport_reference, payout_averages, listing_stats,
  sharing}`). Effects: listing create and submit, buy requests, withdrawals and staff release, requests for more time, and the
  market reads (a paused category's `live` pieces are absent; a piece somebody asked for stays). Anything with a locked price
  finishes; a customer may still cancel a pending withdrawal.
- **Market makers.** `/dashboard/promo-codes*` (`promo.manage`), `/dashboard/market-maker/{queue,approvals,cost}`
  (`market_maker.approve`); `POST /customer/me/buy-requests` accepts an optional `promo_code`;
  `GET /customer/me/market/listings` (a market maker's account only) marks `mm_approved`. `GET /customer/me` carries
  `customer_type`.
- **People to watch.** `GET /dashboard/people-to-watch` and `/export` (`people_to_watch.view`): figures per customer, no
  verdict; each read is audited.
- Classification: non-breaking additions, plus **potentially breaking** new refusal codes on existing POSTs
  (`legal_acceptance_required`, `category_stopped`, `category_paused`, `platform_stopped`) and new permission strings.

## Spec 023 — first-sale advance, codes, referral, market-maker terms (non-breaking)

| Area | Endpoints / fields | Class |
|---|---|---|
| Customer codes | `POST /customer/me/commission-preview`, `PUT|DELETE /customer/me/listings/{id}/promo`, `promo_code` on listing create, `pays_advance` / `promo` / `can_change_promo` on `CustomerListing`, `advance` / `commission` on the seller's order | Non-breaking (new optional) |
| Referral | `GET /customer/me/referral`, `POST /customer/me/referral/shares` | Non-breaking (new endpoints) |
| First-sale | `POST|DELETE /dashboard/customers/{id}/first-sale-advance`, `GET /dashboard/first-sale-advances`, `first_sale` on the Customer file; permission `first_sale.manage` | Non-breaking |
| Market maker | `POST /dashboard/promo-codes` takes terms; queue gains view count and guide; `…/leave`, audited media, cost panel | Non-breaking (the old body still works; the Dashboard page was rebuilt in the same change) |
| Identity | the identity-document review takes the national ID number on approval | Non-breaking (optional `id_number`, 14 digits) |

Error `promo_code_invalid` carries `details.reason`; SQLSTATE DH024–DH027 map to `409 …` per `bootstrap/app.php`. The three
switches (`payout.first_sale_enabled`, `promo.seller_codes_enabled`, `referral.enabled`) default off.
