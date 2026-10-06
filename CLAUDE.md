# Dahab Platform — Parent Guide

This file is the **parent guide for the whole Dahab platform** and lives in the Backend repo so
that its changes are tracked. `D:\laragon\www\CLAUDE.md` only points here.
Platform docs: [`docs/platform/architecture.md`](docs/platform/architecture.md) ·
[`docs/platform/api-contract.md`](docs/platform/api-contract.md) ·
[`docs/platform/development-workflow.md`](docs/platform/development-workflow.md) ·
[`docs/features/`](docs/features/) (feature specs, template `_TEMPLATE.md`).

Part 1 covers the platform (all projects). Part 2 covers this Backend repo.

---

# Part 1 — Platform

## One platform, separate projects

The Dahab projects are sibling folders in `D:\laragon\www`. They are parts of **one** platform but
stay separate. Never merge them, never move/rename them, never move files between them, never
create a new parent/shared project.

```
Backend API (dahab-backend, Laravel 12, /api/v1)
     │
     ├──────────────► Dashboard            (../dahab-dashboard, Vue 3)  →  /api/v1/dashboard/*
     │
     └──────────────► Customer Flutter Web (../dahab-flutter, Flutter)  →  /api/v1/customer/*
                                                                           + public /api/v1/market/*, /reference/*
```

| Folder | Role | Stack | Git |
|---|---|---|---|
| `dahab-backend/` (this repo) | REST API, **source of truth** | Laravel 12, PHP 8.3+, PostgreSQL 16, Redis, Sanctum, Spatie Permission, Horizon, l5-swagger, Pest 3 | own repo, `main` |
| `../dahab-dashboard/` | Staff/admin UI | Vue 3 + TS, Vuetify 4, Pinia, Vue Router 5, TanStack Vue Query, Axios, vue-i18n, Tailwind 4, Vite 8 | own repo, `main` |
| `../dahab-flutter/` | **Customer app** (Flutter Web) | Flutter (Dart ^3.11), go_router, provider, http, shared_preferences | own repo, `main` ([github.com/mohabshirbiny/dahab-flutter](https://github.com/mohabshirbiny/dahab-flutter), public) |

`../dahab-pwa/` (an earlier Vue customer PWA) is **out of scope for now** — ignore it unless the user
brings it back. Unrelated projects in `D:\laragon\www` (VastPay, VastMenu, mysaff, `old dahab code/`, …)
are never part of Dahab work.

Project guides: this file (Backend), `../dahab-dashboard/CLAUDE.md` (+ `AGENTS.md`, `docs/`),
`../dahab-flutter/README.md`.

## Responsibilities

**Backend** — business logic · database · authentication · authorization · API · validation ·
transactions · permissions · audit. **The Backend is the source of truth for business rules.**

**Dashboard** — staff/admin UI · dashboard state · API consumption (`/dashboard/*` only) ·
navigation · UI validation · loading/error/empty states. Gates UI on the Backend's permission strings.

**Customer App** — customer UI · customer state · API consumption (`/customer/*` and the public `/market/*`,
`/reference/*`) ·
navigation · UI validation · loading/error/empty states.

Frontends may validate for UX, but must not re-implement Backend business rules (pricing
authority, status transitions, permissions) beyond what is needed for display. Where a frontend
currently holds such logic (e.g. `../dahab-flutter/lib/services/pricing.dart` — prototype maths),
it is a placeholder until the Backend provides it.

## Sources of truth

| Question | Authority | Where |
|---|---|---|
| Endpoint, method, params, validation, auth, permissions, response shape, pagination, errors, field names | **Backend code** | `routes/api.php`, controllers, `app/Http/Requests`, `app/Http/Resources`, `app/Actions`, `app/Enums/AuthErrorCode.php`, `bootstrap/app.php` |
| Machine-readable form of the above | Generated OpenAPI (from `#[OA\…]` attributes) | `storage/api-docs/api-docs.json` — gitignored; `composer swagger:generate` |
| Manual API exercising | Postman collection | `postman/` |
| Product rules / business intent | Backend docs + Constitution | `docs/`, `.specify/memory/constitution.md` |
| Dashboard visuals | Design reference | `../dahab-dashboard/docs/dahab-admin-dashboard.html` |
| Customer app visuals | Prototype | `../dahab-flutter/doc/dahab-app-prototype.html` (copy in `docs/`) |

Do **not** hand-write an `openapi.yaml`. `specs/*/contracts/*` are Spec Kit planning artefacts and
may lag the code. On disagreement: Backend code → generated OpenAPI → Postman → Spec Kit contracts →
prose docs. Report the disagreement.

## Multi-project feature rule (MANDATORY)

When asked to implement a feature, **do not** modify only the project where the feature appears.
First analyse it across **all three** projects, then implement every required change in every
affected project.

### Required workflow for every feature

**Step 1 — Understand.** Read this file, `docs/platform/architecture.md`,
`docs/platform/api-contract.md`, the relevant project guides, and any spec in `docs/features/`.
Inspect the relevant code in Backend, Dashboard **and** Customer App.

**Step 2 — Impact analysis.** Write a short analysis before editing:

```
Backend:          YES/NO
Database:         YES/NO
API:              YES/NO
Dashboard:        YES/NO
Customer App:     YES/NO
Auth:             YES/NO
Permissions:      YES/NO
API models/types: YES/NO
```

If a project is not affected, say so explicitly and why. For non-trivial features, write a spec
in `docs/features/<feature-name>.md` using `docs/features/_TEMPLATE.md`.

**Step 3 — Implement.** When the API changes, go in this order and keep all consumers in sync:

```
Backend (migration → model → Action → FormRequest/Resource/controller + #[OA] → Pest tests → Postman)
    ↓
API contract (composer swagger:generate; update docs/platform/api-contract.md if a convention changed)
    ↓
Dashboard (types → services → composables/stores → components/pages)
    ↓
Customer App (models → services/api → controllers/state → screens)
```

Backend work follows Part 2 (Spec Kit lifecycle, Laravel skills, Postman sync).

**Step 4 — Verify.** Run only the commands that exist (see `docs/platform/development-workflow.md`):

- Backend: `composer test` · `./vendor/bin/pint --test` · `composer swagger:generate`
- Dashboard: `npm run type-check` · `npm run lint` · `npm run build`
- Customer App: `flutter analyze` · `flutter test` · `flutter build web --release`

**Step 5 — Report** in this shape:

```
Feature:        <name>
Backend:        <changes>
Dashboard:      <changes>
Customer App:   <changes>
API:            <changes + classification: non-breaking / potentially breaking / breaking>
Database:       <changes>
Tests:          <results>
Builds:         <results>
Documentation:  <changes>
Projects intentionally not changed: <projects + reason>
```

## Golden rules

1. **Never invent API behaviour** — no endpoints, fields, filters or codes that don't exist, in any
   project. Missing → report what the Backend needs; don't fake it.
2. **Never modify one project without checking the others.**
3. **Never change an API without checking all its consumers** (Dashboard for `/dashboard/*`;
   Flutter for `/customer/*`, `/market/*` and `/reference/*`; both for shared conventions like the error envelope).
4. **Frontends never guess the Backend** — read the Resource/FormRequest/`#[OA]`/Postman first.
5. **Don't duplicate Backend business rules** in frontends unnecessarily.
6. **No unrelated refactoring; never delete working functionality.**
7. **Never move/rename/merge the projects or their repositories.**
8. **Never commit or push unless explicitly instructed.** Each repo gets its own commits.
9. Prefer small, focused, reversible changes.

## Git coordination

Each project keeps its own repository. For a feature touching several projects, use the **same**
branch name in each affected repo: `feature/<feature-name>` (e.g. `feature/customer-identity-approval`).
Create/switch branches only when asked. `../dahab-flutter` has its own repository since 2026-10-04 and
follows the same rules. Details: `docs/platform/development-workflow.md`.

## Change classification

| Change | Class |
|---|---|
| Add an optional response field / new endpoint / new optional query param | Non-breaking |
| Add a value to a response enum | Potentially breaking (exhaustive UI maps, status chips) |
| Remove a response field | Potentially breaking |
| Change a field's type/format/nullability | Potentially breaking → breaking, by usage |
| Add a required request field or new validation rule | Potentially breaking |
| Tighten auth, or require a new permission | Potentially breaking |
| Rename a field, change URL/method, change the response envelope or error `code`s | **Breaking** |

Breaking changes: list consumers, tell the user first; per the Constitution they ship under a new
`/api/v2` prefix with the old one deprecated on a documented schedule.

## Finding consumers

Search, don't assume file names. Grep for the **path**, the **field** (Backend `snake_case`; Dashboard
maps to `camelCase` in `src/services`/`src/types`; Flutter maps in `lib/models`/`lib/services`), the
**enum values** and the **permission strings**:

- Dashboard: `../dahab-dashboard/src` — `api/endpoints.ts` → `types/` → `services/` (+ `mock/`) → `composables/` → `stores/` → `components/`, `pages/`
- Customer App: `../dahab-flutter/lib` — `services/api/`, `services/auth/`, `models/` → controllers (`services/*_controller.dart`) → `features/`; tests in `test/` (incl. the fake backend in `test/flows_test.dart`)

## Impact report (whenever a change crosses the API boundary)

```
Change:          <what, one line>
Classification:  non-breaking | potentially breaking | breaking — <why>
Backend:         files changed / needed · OpenAPI · Postman
Dashboard:       affected files (types · services · mock · composables · stores · components/pages)
Customer App:    affected files (models · services · controllers · screens · tests)
Missing:         anything not found, stated exactly (endpoint / field / permission / doc)
Action needed:   Backend → … · Dashboard → … · Customer App → …
```

## Current state (verified 2026-10-03 — re-verify before relying on it)

- Backend implements: health, customer registration (6 steps) / login / new-device OTP / refresh / me /
  logout, customer uploads + identity-document submission; staff login + MFA / refresh / me / logout;
  dashboard customers list/show and identity-document list/show/image/review; Dashboard-managed staff
  roles/permissions and staff role assignment (`/dashboard/permissions`, `/dashboard/roles*`,
  `/dashboard/staff*`) and the customer verified gate (spec 002); customer data isolation by forced
  PostgreSQL row-level security (spec 003); reference data — karats, branches with weekly hours, closures,
  staff branch assignment, and the working-hours deadline resolver (spec 004, `/dashboard/karats`,
  `/dashboard/branches`, `/dashboard/branch-closures`, `/dashboard/staff/{staff}/branch`); pricing — settings with
  history, the gold price record fed every minute by the provider (`pricing:pull-feed`, credentials in `.env` only)
  or entered by hand while the feed is down, per-karat buy/sell adjustments, and the Part 3 §2 price calculator
  (spec 005, `/dashboard/settings*`, `/dashboard/gold-prices*`, `/dashboard/karats/{code}/adjustments`). The app and
  the PostgreSQL session run on Cairo time (`APP_TIMEZONE=Africa/Cairo`); the audit log viewer — list, details and
  CSV export, everything or own actions only (spec 006, `/dashboard/audit-log*`); the Customer file — the file with every
  document and the suspension, search by reference/phone, suspend/reinstate (seven reasons, back to the interrupted state),
  History and open sessions/devices (spec 007, `/dashboard/customers/{id}/suspend|reinstate|activity|sessions`), and the
  shared `Idempotency-Key` layer (`idempotent` middleware, `idempotency_key` table; on suspend/reinstate and every top-up POST);
  the ledger core (spec 008) — the double-entry EGP ledger of `03_schema_ledger.sql` (accounts per customer created by a
  trigger on `customer`, the six Dahab internal accounts, append-only balanced entries, forced RLS with a write-only
  `ledger` scope), the money service `PostLedgerEntryAction` / `ReverseLedgerEntryAction`, the customer's wallet and history (`/customer/me/wallet*`) and, behind `wallet.view`, the overview, customer
  wallet and Wallet statement with export (`/dashboard/wallets/overview`, `/dashboard/customers/{id}/wallet`,
  `/dashboard/wallet-statement*`). Signs: lines sum to zero, so money in is `bank −X`; the bank's cash is `−SUM(bank)`;
  wallet top-up (spec 009) — a manual transfer, never a gateway: Dahab's receiving accounts (`receiving_account`,
  `/dashboard/receiving-accounts*`, `topup.accounts.manage`), the customer's methods + reference `DAHAB-<display_ref>`,
  receipt upload (`purpose=topup_receipt`), notices, list and cancel (`/customer/me/wallet/topup-methods|topups*`, trade
  gate to add money; a suspended customer may list/cancel), and Incoming transfers — match (credits what arrived),
  hold/unhold, reject, credit by hand, receipt, export (`/dashboard/topups*`, `topup.match`) — the first endpoints that
  move money (`topup` entries; a suspended customer is credited only with `arrival_reference`);
  listings (spec 010) — selling a piece and the market: the public reference reads (`/reference/karats|piece-types|
  branches|legal-documents/{code}`) and market (`/market/listings*`, read-only `market` RLS scope, no view; never the
  seller or private media; `current_price` from the spec 005 calculator, indicative), the seller's listings
  (`/customer/me/listings*`: create, edit, submit, withdraw from live only; media through `/customer/me/uploads` with
  the purposes `listing_photo|listing_video|listing_invoice|stone_certificate`, stored encrypted in chunks), the
  review queue (`/dashboard/listings*`: approve, request changes, reject, take down; `listing.review`,
  `listing.request_changes`, `listing.takedown`), the `listing_state` machine (guard trigger + `listing_transition`,
  `illegal_listing_transition` 409, history in `listing_state_change`; `rejected` and `withdrawn` are final), SMS +
  email to the seller on each decision, and a suspended seller's live listings held and restored;
  buy requests (spec 011) — a buyer joins a piece's line (`/customer/me/buy-requests*`: send with the deposit terms
  `deposit_agreement`, list, leave), the deposit (`deposit.buyer_pct` of the locked price) held on the ledger
  (`deposit_hold` / `deposit_release`), price locked within `buyrequest.price_tolerance_pct`; the seller's line
  (`/customer/me/listings/{id}/buy-requests|accept|decline`: the head only; accept creates the `"order"` row with
  `DH-YYYY-NNNNNN` and the reach-branch deadline from the working-hours resolver, releasing the others); a per-minute
  sweep `buy-requests:expire`; take-down / withdrawal / seller suspension from `reserved` release the line; staff
  cancel an acceptance (`POST /dashboard/orders/{id}/cancel`, `order.cancel`, order `cancelled_staff`, refund, relist
  or withdraw). Queue operations run in the non-elevated `queue` RLS scope;
  orders (spec 012) — the life after acceptance: the customer's orders (`/customer/me/orders*`: list, detail with the
  owner's collection/return code, the seller's cancel before delivery, the buyer's decision on an adjusted price,
  pay-balance from the wallet, relist a returned piece), the branch work list and staff actions
  (`/dashboard/orders*`: list with groups and counts, detail with ledger/timeline/`can`, receive, inspection results
  with corrections, propose a price after a stone regrade, change branch, extend a deadline, hand over to the buyer
  or back to the seller against a 6-digit code with a 5-try lock; `/dashboard/inspections*`; `/dashboard/buy-requests`),
  eight permissions (`order.view|receive|price_adjust|change_branch|extend_deadline|handover`, `inspection.enter`,
  `buy_request.view`; branch scope from the staff member's assigned branch), settlement on the rates locked at the
  request (buyer) and at acceptance (seller) as one `balance_payment` through escrow, the no-pay `deposit_forfeit`,
  the per-minute `orders:sweep` (missed delivery, unanswered adjustment, no-pay, return and collection windows,
  reminders, suspension for repeated cancellations), history in `order_state_change` (guard SQLSTATE DH006
  `illegal_order_transition`), the audited non-elevated `order` RLS scope;
  withdrawals (spec 013) — payout accounts (`/customer/me/payout-accounts*`: add with the `payout_account_declaration`,
  several with exactly one in use, use / remove / keep; an account becoming the one in use — not the first ever — cancels
  every withdrawal not yet released and opens the `withdrawal.account_change_pause_hours` pause; staff verify or refuse
  (`/dashboard/payout-accounts*`, `payout_account.verify`, new final state `refused`)), the email second-check
  (`/customer/me/withdrawals/confirmations*` + the public `/withdrawal-confirmations/read|confirm`, 30-minute single-use
  link tied to amount and account), withdrawals (`/customer/me/withdrawals*`: submit holds available → held, cancel;
  `WD-{n}`) and the staff Withdrawals queue (`/dashboard/withdrawals*`, `withdrawal.release` — CEO + Finance, never COO;
  figures, signals, take for review, hold / unhold, release with the bank record (held → bank), reject, CSV export),
  guards DH007 / DH008, `trg_withdrawal_money`, `withdrawals:sweep`, the held split (`held_on_orders` +
  `pending_withdrawals`);
  disputes (spec 014) — a party reports a problem on their order (`/customer/me/orders/{id}/disputes`, six reasons, up to
  five photos with `purpose=dispute_photo`, one per party per order, 5 a minute) from `at_inspection`,
  `weight_adjust_pending`, `awaiting_balance` or `ready_to_collect`: the order freezes in `disputed` (every action and
  sweep refuses `order_frozen`; a waiting request for more time lapses); staff work the Disputes queue
  (`/dashboard/disputes*`, `dispute.handle`: list with counts, detail with the order, photos audited per view, pass on to
  a named colleague, resolve with a reply — resume, giving every running deadline back the frozen time, or, before
  payment and with `order.refund`, against the sale: deposit released, piece returned to its seller; optional
  compensation (`compensation.pay`, per-payment and per-day caps unless `compensation.uncapped`, `external_equity` →
  available) and seller suspension); guards DH009 (dispute) / DH010 (extension request); the seller's request for more
  time (`/customer/me/orders/{id}/extension-requests`, answered in `/dashboard/extension-requests*` with
  `order.extend_deadline`: 6/12/24/48 working hours through the spec 012 extend action, or refused); the buyer names
  someone else to collect (`/customer/me/orders/{id}/proxy|proxy/remove`, `purpose=proxy_id`, the
  `collection_proxy_authorisation` accepted; the handover takes `collector: proxy` + `proxy_id_checked`,
  `proxy_details_missing` 422; `GET /dashboard/orders/{id}/proxy-id` audited). Staff approval of customer messages was
  deferred (no source defines it); finance operations (spec 015) — the Compensation page (`/dashboard/compensation*`:
  every payment, totals, the viewer's caps, CSV, and paying outside a dispute under `compensation.pay`, same caps; a
  compensation's dispute/order/party are now optional), the wallet adjustment (`POST /dashboard/customers/{id}/wallet-adjustments`,
  `wallet.adjust` — no role, founders: kind `reversal` without a reversed entry, available ± against `external_equity`, never
  below zero, `wallet_adjustment` row; `GET /dashboard/wallet-adjustments`), the bank book (`bank.record`: staff proof upload
  `POST /dashboard/uploads`, `POST /dashboard/bank-movements` with the design's seven kinds — bank ↔ `external_equity`, own
  transfers are records only; reads `bank-movements*`, `bank-book*` with `bank.record|wallet.view`), the daily close
  (`day.close`: `GET|POST /dashboard/daily-close`, `GET /dashboard/daily-closes`; the typed statement balance against the
  ledger's bank cash at midnight Cairo, only ended days, 0 locks, a difference locks only with an explanation, a locked day
  never changes), `GET /dashboard/overview` (sections by permission), `GET /dashboard/orders/export`, `deposit_held` on buy
  requests and orders and `GET /customer/me/wallet/held`, and the public `GET /reference/gold-prices` and `/reference/quote`.
  Guards DH011; tax invoices and credit notes (spec 016) — the pay-balance settlement issues `DH-YYYY-NNNNNN-S` to the
  seller (net = the commission posted, its VAT) and `-B` to the buyer (the price paid, VAT 0) in the same transaction
  (`tax_invoice`, one per order and party, reconciled with the ledger by a deferred DH012 check; no backfill of earlier
  orders); a bilingual PDF per document (mPDF) made after commit and healed by `invoices:render-pending`, stored encrypted;
  Dahab's details from `config/dahab-invoices.php` (demo values for now — replace before production; empty details make documents wait); credit notes `CN-YYYY-NNNNNN` on
  a seller invoice by hand (`invoice.correct`, one balanced `credit_note` entry giving commission and VAT back, never above
  what is left, SMS + email to the seller); reads `invoice.view` (`/dashboard/invoices*`, `/dashboard/credit-notes*`, export,
  audited PDFs) and the customer's own (`/customer/me/invoices*`, `invoice` on orders, `invoice_id` on wallet movements).
  Nothing is filed with the Tax Authority (Part 4 §4 not integrated); the customer account (spec 017) — phone change by a
  code to the new number and email change by a single-use link on the public page (`/customer/me/phone-change*`,
  `/email-change`, `/contact-changes/email/read|confirm`): the old contact told, withdrawals not yet released cancelled and
  a withdrawal pause opened (`trigger_kind` `phone_change|email_change`), open withdrawal links stopped by an email change;
  password change and the customer's own sessions with sign-out of a device (`/customer/me/password`, `/sessions*`; tokens
  now record their device); a new-device sign-in alert; the in-app inbox (`customer_notification`, `/customer/me/notifications*`)
  written by an `inbox` notification channel for every customer notice except codes and confirmation links (EN + AR text,
  type, params, link; collection codes masked); saved pieces (`/customer/me/saved-pieces*`, setting `saved.max_per_customer`
  200); `/reference/legal-documents` and `/reference/support-contacts` (`config/dahab-support.php`, demo values); closing an
  account (`/customer/me/account/close-check|close`, status `closed`, final, blockers incl. `piece_at_branch`, nothing
  deleted, sign-in `403 account_closed`, guard DH013); listing reports (`/customer/me/listing-reports`, `RPT-n`; staff
  `/dashboard/listing-reports*` with `listing_report.handle`, take-down also `listing.takedown`; sweep
  `listing-reports:close-gone`); the Customer file's notifications (`/dashboard/customers/{id}/notifications`). Guards
  DH013–DH015. Nothing else yet.
- Dashboard: staff auth, customers/identity, staff and roles, Karats, Branches and hours, Gold pricing,
  Commission rates, Audit log, Customer file (with suspend/reinstate and, for `wallet.view`, the wallet panel) and the
  Wallet statement are live; on the Overview the safety figure, "Held on open orders" and Customer wallets are live
  (spec 008); Incoming transfers and Controls → Receiving accounts are live (spec 009); Listings to review is live
  (spec 010), with the read-only line, the order box, Cancel acceptance and the Buyers in line / Accepted chips
  (spec 011); Orders, Inspections (work list and results) and Buy requests are live (spec 012); the rest of the
  Overview and other sections are mock. Withdrawals (with Payout accounts to check), the Customer file's payout accounts
  and the held split are live (spec 013). Disputes and reports is live for disputes (queue, detail with the order, photos,
  Pass on, Resolve with compensation and suspension; listing reports are not built), and Orders shows the frozen
  banner, Requests for more time with Answer, the person named to collect with their ID, and the proxy handover
  (spec 014). Compensation, Bank movements and Daily closing are live, the Customer file has Adjust (wallet.adjust), the
  Orders list exports to CSV, and the whole Overview is live — no mock figure left on it (spec 015). Invoices is live:
  figures, invoices with their status from credit notes, export, credit notes, detail and PDF, Issue a credit note (spec 016).
  Disputes and reports has the Listing reports view (Dismiss, Take the piece down), the Customer file shows what the
  customer was sent, the closure and the pause trigger, Users has a Closed tab, Settings lists `saved.max_per_customer` (spec 017).
- Flutter: registration + sign-in (with device OTP), session restore, refresh and sign-out are live;
  a suspended customer sees a notice with the plain reason (spec 007); the wallet balance and history are live
  (spec 008, `ApiWalletRepository`); Add funds and Your top-ups are live (spec 009); Home / Browse / the piece page
  (public market, filtered on the device), the sell flow and My listings are live (spec 010; the on-form payout
  estimate and saved pieces are still mock); the buy flow is live (spec 011: Send buy request with the deposit terms,
  Request sent, You need a little more, the place in line and Leave the queue on the piece page, the seller's
  Accept with the branch pick / Decline on their piece); Orders are live (spec 012: the orders and the requests not
  accepted on the Orders tab, and the order screen — bring the piece with a countdown, cancel the sale, the
  inspection result, accept/decline a new price, pay the balance with You need a little more → Add funds, the
  collection code, the returned piece with its code and Put it back on the market); the prototype's other order
  screens, notifications, etc. run on mock repositories (`lib/services/mock_repositories.dart`); Bank accounts,
  Add a bank account, Your details → Payout account, Withdraw with the email step, the `#/withdraw-confirm` page and the
  wallet's pending withdrawals are live (spec 013); Report a problem with photos, the order on hold with your report and
  Dahab's answer, Ask for more time and Someone else collects are live on the order screen (spec 014; the prototype's
  standalone Inspection, Pay and Collection-code screens were removed: their routes open the live order); the rate strip,
  the splash rate cards, the home calculator and the sell estimate read the backend's prices and quote, and Held on open
  orders lists what each request and order holds (spec 015); Transactions and invoices and the invoice screen with its PDF and
  credit notes are live, with View invoice on a paid order and Open the invoice on the wallet line (spec 016). Change phone
  (code) and email (link, `#/email-confirm`), the password and devices, the inbox with the bell count, notification settings
  (always-on rows), saved pieces and Save, Report this listing, Terms and privacy with the published documents, Contact us and
  Close my account are live (spec 017); Help's FAQ is still mock (spec 019).
- Flutter's `API_BASE_URL` defaults to `http://127.0.0.1:8000/api/v1` (`lib/core/config/app_config.dart`); the production host is passed
  with `--dart-define` only when building a deploy version.

---

# Part 2 — Backend repo (dahab-backend)

Laravel 12 API (PHP 8.3+, PostgreSQL 16, Redis, Sanctum, Horizon, Pest 3, l5-swagger).
The rules are in `.specify/memory/constitution.md`, and the specs live in `docs/`.

## Spec Kit + Laravel skills

Non-trivial work goes through the Spec Kit lifecycle (`/speckit-specify → /speckit-clarify → /speckit-plan → /speckit-tasks → /speckit-implement → /speckit-analyze`).
Use the Laravel skills in `.claude/skills/laravel-*` inside those phases:

| Spec Kit phase | Laravel skills to apply |
|---|---|
| `/speckit-plan` (data-model, contracts) | `laravel-migrations`, `laravel-eloquent-models`, `laravel-api-endpoints`, `laravel-auth-authorization` |
| `/speckit-tasks` | Split each endpoint into tasks along the skill boundaries: migration → model/factory → Action → FormRequest/Resource/controller/OpenAPI → Pest feature test |
| `/speckit-implement` | The skill for each task's layer: `laravel-migrations`, `laravel-eloquent-models`, `laravel-actions-services`, `laravel-api-endpoints`, `laravel-auth-authorization`, `laravel-queues-notifications`, `laravel-pest-testing` |
| Before marking tasks done, `/speckit-analyze`, or a PR | `laravel-quality-gates` |

## Postman collection

`postman/Dahab-Backend.postman_collection.json` mirrors every route in `routes/api.php`.
Whenever a task adds, changes, or removes an API endpoint, update the matching request in
that collection (and the environment file if new variables are needed) as part of the same
task — see `postman/README.md` for the exact steps. Do this before marking the task done.

## Commands

```bash
composer test               # Pest
./vendor/bin/pint           # format
composer swagger:generate   # OpenAPI
php artisan migrate:fresh --seed
```

## This repo is the source of truth for

API endpoints and HTTP methods · request parameters and validation · authentication ·
authorization (permissions) · response structures · pagination · filtering · sorting ·
error responses and `code`s · API Resources · API field names.

The contract lives in code and is generated from it — do **not** hand-write a parallel
`openapi.yaml`:

| Artefact | Role |
|---|---|
| `routes/api.php`, controllers, `app/Http/Requests`, `app/Http/Resources`, `app/Actions` | The behaviour |
| `#[OA\…]` attributes on controllers/Resources/Requests | The documented contract |
| `storage/api-docs/api-docs.json` (gitignored) | Generated OpenAPI — refresh with `composer swagger:generate` |
| `postman/Dahab-Backend.postman_collection.json` | Manual-testing mirror of every route — see "Postman collection" above |
| `specs/*/contracts/` | Spec Kit planning artefacts; may lag the code. Code wins on disagreement |

## When you add, change, or remove an endpoint or an API field

1. **Inspect existing consumers first** — `../dahab-dashboard/src` for `/dashboard/*`,
   `../dahab-flutter/lib` (+ `test/`) for `/customer/*`: the path, the field (search both
   `snake_case` and the mapped casing), the enum values and the permission strings.
2. **Classify the change** using the table in Part 1. Breaking changes ship under a new version
   prefix per the Constitution.
3. **Update the contract in the same task**: `#[OA\…]` attributes, then
   `composer swagger:generate`; the Postman request (body kept in sync with the FormRequest
   rules, auth, saved-token test scripts); Pest feature tests.
4. **Update or report consumer impact** — for a feature, implement the frontend changes per the
   multi-project workflow in Part 1; for a Backend-only task, report impact with the impact-report
   shape. Never silently break a consumer.

Do not invent frontend behaviour. Don't add fields, filters or endpoints "because the UI
might want them" — build what the spec/docs and the user's request require, and when a frontend
needs something the API lacks, state it as a Backend requirement for the user to approve (ideally
through the Spec Kit lifecycle above).
