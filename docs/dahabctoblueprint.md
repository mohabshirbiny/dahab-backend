# Dahab — CTO Technical Blueprint & Implementation Roadmap

*Prepared as CTO / Principal Solution Architect · Version 1.0 · 2026-09-10*
*Scope: production-ready blueprint. No implementation code. Locked business decisions are honoured, not re-litigated.*

---

## 1. Executive Summary

Dahab is a **regulated custodial marketplace for physical gold and diamond jewellery in Egypt**, not an e-commerce site. Its business model is a **peer-to-peer trade under Dahab's escrow**, mediated by an independent IGI inspection. Financial correctness, custody liability, and auditability are the three properties that must never be compromised.

The documentation set provided (SQL schemas 01–05, Technical Spec parts 1–3, business blueprint, admin roles, open-questions, terms draft, prototype HTML) is **unusually mature for a pre-development handoff**. It resolves most of the hard business questions, embeds the invariants at the database layer (double-entry ledger, append-only audit/ledger/inspection, deferred balancing constraints, RLS, role-scoped grants, state-transition tables as data), and has already been reviewed against itself for internal contradictions.

**My core recommendations:**

1. **Do not redesign the schema.** It is one of the strongest SQL handoffs I have seen for a fintech-adjacent product. Ninety-plus percent is KEEP; a small ADD list is at §8.
2. **Retain the specified stack** (Laravel 11 + PHP 8.4 + PostgreSQL 15 + Redis + Horizon + Vue 3/Vuetify + Flutter + S3). It is well-matched to the invariants: Postgres carries the correctness, Laravel carries the workflow, Horizon carries the deadline sweeps.
3. **Adopt a Modular Monolith** organised by bounded context (Identity, Marketplace, Orders, Inspection, Wallet, Ledger, Settlement, Notifications, Administration, Reports), with **domain events → queued jobs** as the seam that lets any module later become a service without a rewrite.
4. **Money is a single service, not a scatter of controllers.** All ledger writes go through one `MoneyService` that constructs balanced sets in one DB transaction. Any code path that writes a `ledger_posting` outside that service is a bug.
5. **Authentication: Laravel Sanctum** for both mobile and admin (per-device tokens fit the "new device → held pending OTP" flow perfectly; revocable; simpler than Passport for this shape).
6. **Postgres role-per-staff-role is not optional.** The Part 1 §5.2 wallet-narrowing (COO cannot see balances) must be enforced by DB grants, not app code. The Laravel connection layer must switch roles per authenticated staff session — a real infra requirement, called out at §17.
7. **MVP is the minimum-complete transaction:** list → queue → accept → inspection → pay-balance → handover, plus the wallet/ledger, plus the deadline sweeps, plus the operator screens for review/release/inspection. Cut everything else from MVP. Realistic timeline: **MVP 18 weeks; Production V1 24 weeks** with the team sized in §21.
8. **Ten open items (OI) block specific endpoints.** Most are legal-clinic decisions the code can leave a hook for; two (§30) are wiring decisions (alert routing, founder recovery) that must be answered before go-live.

The remainder of this document is the detailed plan.

---

## 2. Understanding of the Dahab Business

### 2.1 What the platform is (and is not)

- **Is:** an escrow-and-inspection marketplace where a private seller and a private buyer trade a physical piece, with Dahab holding the money and IGI verifying the piece.
- **Is not:** a cart/checkout e-commerce site; a broker that buys/sells inventory; a jeweller. Dahab **does not own gold at any point**. Even the first-sale advance is a fronted payment against the buyer's eventual payment, not a purchase.
- **Revenue:** commission on the making charge (gold) / on value-above-gold (stones); spread on the gold rate (gold category only); nothing from the customer's gold value; VAT applies to commission only.

### 2.2 The canonical transaction (twelve steps, from blueprint §2)

```
1  Seller lists piece (photos, karat, weight, making charge, branch options)
2  Dahab reviews the listing (Operations/Founders)
3  Piece goes live; price moves with the gold rate
4  Buyer requests → 20% deposit held, price locks per-buyer; buyers queue FIFO
5  Seller accepts head of queue → all other queued buyers refunded atomically
6  Seller chooses branch (from her named options); 12 working-hours to deliver
7  IGI inspects (karat, weight, stones)
8  Karat mismatch → cancel + suspend; weight within 1.5% → auto-adjust; above → buyer approves
9  On approval → order enters awaiting_balance
10 Buyer pays balance within 10 days → THIS IS SETTLEMENT
     - full price transits escrow (instantaneous pass-through)
     - seller receives seller_proceeds
     - Dahab receives commission + spread (gold) + VAT
     - tax invoices auto-issued (ETA e-invoicing)
     - collection code generated (hashed at rest)
11 Buyer collects within 3 weeks (calendar) at the branch, or via proxy w/ authorisation
12 Handover is physical only — no money moves
```

### 2.3 What is NOT in the canonical flow (and why)

- **No add-to-cart, no checkout, no shipping.** The "order" is a single buy-request that was accepted; only one buyer per accepted order; no basket.
- **No delivery to buyer.** Physical handover is always at the branch, either by the buyer or by an authorised proxy whose ID they upload.
- **No auto-disposition after windows.** Uncollected-paid and unclaimed-return are **manual operator decisions** on contact (decisions #3 and #4). The system provides state + notification + the two manual actions; it does not auto-hand-over or auto-refund.
- **No standby queue behind an accepted buyer.** Once the seller accepts, everyone else is released. Buyers can request `notify_when_free` and rejoin at the back if the piece returns.

### 2.4 The two afterlife paths (buyer no-pay / buyer no-collect)

These are the subtlest parts of the business logic and must not be conflated:

- **Buyer never pays** → order terminal `cancelled_buyer_nopay`; deposit forfeited 50/50 (seller/Dahab); piece **returns to the seller** (listing → `awaiting_seller_return`). Because settlement never fired, there is no seller settlement to reverse.
- **Buyer pays but never collects** → order stays paid; piece stays at IGI; listing → `uncollected_expired`; disposition is a manual operator decision on buyer contact (hand over vs refund from escrow). This is why escrow remains on the settlement path even as an instantaneous pass-through — it is the clean source for a post-settlement refund.

Both windows are 3 weeks (calendar) and are storage limits, not penalties.

### 2.5 The three external parties

- **Evolve** — live gold rate feed. Manual price is the fallback (Finance/CEO) with a 10% deviation confirmation.
- **Rapaport** — weekly diamond matrix upload; guidance only, never a valuation.
- **IGI** — independent lab, one shared branch account per branch (logs the branch), inspects every piece.

---

## 3. Current Documentation Assessment

### 3.1 Strengths (unusual for a pre-build handoff)

- **Financial invariants pushed to the database:** double-entry ledger with a deferred `SUM(amount) = 0` trigger per transaction, non-negative-wallet deferred trigger, append-only triggers on ledger/audit/inspection, single-row-per-customer-per-account-kind uniqueness, singleton internal accounts by partial unique index.
- **State machines as data:** `order_transition`, `listing_transition`, `buy_request_transition`, `withdrawal_transition` tables, with a generic guard trigger. Adding a transition is a reviewed row, not a scattered code change.
- **Deadlines as data:** every duration lives in the `setting` table with an audited `setting_history`.
- **Karat rule is structural:** `karat_mismatch_forces_cancel` CHECK plus `karat_rule` CHECK — the "no tolerance on karat" business rule is impossible to violate.
- **Row-level security + role-scoped grants** already designed for both customer isolation and the wallet-narrowing exception.
- **Every material decision has been argued, resolved, and recorded** (open-questions §3).

### 3.2 Gaps I identified

| # | Gap | Where addressed in this document |
|---|---|---|
| G1 | No Laravel application architecture (module layout, service boundaries) | §5, §7 |
| G2 | No mobile architecture (state management, offline model, FCM integration, iOS/Android specifics) | §16 |
| G3 | No admin architecture beyond the roles matrix (routes, guards, state stores) | §15 |
| G4 | No infrastructure/CI/CD plan | §17, §18 |
| G5 | No testing strategy for the financial subsystem | §19 |
| G6 | No development sequencing / milestones / team plan | §21–§24 |
| G7 | Ten open items unresolved (OI-1.1, 1.2, 1.3, 2.2, 3.1–3.5, plus the terms-clinic items) | §30 |
| G8 | No dev-env decisions (PgBouncer mode, S3 flavour, secret manager, log pipeline) | §17 |
| G9 | Part 4 of the spec (integrations: Evolve/Rapaport/IGI/ETA) not written yet | §11, §30 (blocking Rapaport/ETA specifics) |
| G10 | No dispute workflow depth beyond the `dispute` table | §12 |

### 3.3 Contradictions surfaced (already resolved, kept explicit)

- **OI-1.4** — commission/spread rate authority: resolved **CEO + Finance** (roles matrix vs blueprint tie-break; wallet narrowing wins on the COO exclusion).
- **OI-2.3** — withdrawal release authority: resolved **CEO + Finance** (not CEO-only).
- **Admin-roles §4.3 wallet-narrowing wording** vs the "CEO/COO = Yes" row rendering: authoritative reading is *"COO has full permissions except wallets, and except the market-maker / category-pause / customer-visibility rows"*. Codified in Part 1 §4.

### 3.4 The prototype (dahab-app-prototype.html, dahab-admin-dashboard.html)

I have not code-walked the two HTML prototypes in this pass; they should be treated as **UX intent**, not spec. Any divergence between prototype and spec is resolved in favour of the spec. A UX pass in Phase 8 will re-anchor mobile screens on the prototype's visual language while keeping the spec's data contracts.

---

## 4. Recommended Technology Stack

**Recommendation: keep the proposed stack. No substitutions.** The rationale for each:

| Layer | Choice | Why (specific to Dahab) |
|---|---|---|
| Language | PHP 8.4 | Native readonly properties, typed enums, property hooks help express Value Objects (Money, Weight, Karat) safely. |
| Framework | Laravel 11 | Mature queue/scheduler; Horizon covers deadline sweeps; broad hiring pool in EG/MENA. |
| DB | PostgreSQL 15+ | Schema *depends* on Postgres-only features: RLS, partial unique indexes, DEFERRABLE constraint triggers, `citext`, `btree_gist`, `INET`, `TIMESTAMPTZ`, native JSONB. **Migration off Postgres is not an option** — that is a design-level lock-in and the correct one. |
| Cache/Queue | Redis 7 + Laravel Queue + Horizon | Deadline sweeps, notifications, OTP throttling, idempotency-key cache all fit Redis primitives. |
| Scheduler | Laravel Scheduler (systemd/cron-driven) | Runs the sweeps in §11. |
| Admin FE | Vue 3 + TypeScript + Vuetify 3 + Pinia + Vue Router + Axios | Vuetify's data-table/forms match a heavy operator UI; TS + Pinia give the type-safe state layer the money-touching screens deserve. |
| Mobile | Flutter (Android + iOS, single codebase) | Right for a small team; one code path for both platforms; Riverpod for state (see §16). |
| Push | Firebase Cloud Messaging | Native FCM covers both platforms; use Laravel Notifications' FCM driver. |
| Storage | S3-compatible (AWS S3 in prod, MinIO in dev/staging) | Every ID/photo/certificate is private + pre-signed; §21. |
| Infra | Docker + Docker Compose (dev), managed containers (prod, e.g. AWS ECS Fargate or a Hetzner + Coolify baseline) | Dahab's data must live in-region; see §17. |
| CI/CD | GitHub Actions | Standard, cheap, easy hire pool. |
| Observability | Sentry + Loki/Grafana + Prometheus for Horizon/PG metrics; audit log is the app-level ledger of record | See §20. |
| Feature flags | None initially. Reintroduce via `setting` table if needed | The `setting` table already carries every operational tunable. |
| ORM | Eloquent | With disciplined use for aggregates; complex reads via query builder / native SQL. |

**Where I would decline a common recommendation:**

- **Laravel Passport** — reject in favour of Sanctum (see §14). Passport (OAuth2) is heavier than the flows require.
- **Microservices / event-sourcing from day 1** — reject. The domain needs *transactional* consistency (settlement is atomic across seller+Dahab+VAT+escrow legs). A distributed system trades that for eventual consistency; the business rule "the ledger must always sum to zero" would become a nightmare. Modular Monolith preserves atomicity; the events → jobs seam preserves the option to split later.
- **NoSQL for the ledger** — reject flatly. Everything about the ledger design depends on atomic, constraint-guarded, append-only Postgres semantics.
- **Redis-only rate limiting for auth** — augment with DB counters for the 5-attempts-then-lock rule (staff §3.3 Part 1) so lockout survives Redis flush.
- **Client-side price computation** — reject. The buyer's `confirm_locked_price` is only a UI reference; the server always recomputes and rejects on drift.

---

## 5. Architecture

### 5.1 Style: Modular Monolith, domain-oriented

One deployable Laravel application. Internal boundaries by bounded context. Cross-module coupling only through:

1. **Public interfaces** on each module (application services exposed to controllers).
2. **Domain events** dispatched inside the request transaction, handled by **queued listeners** *after commit* (never synchronously across modules — otherwise a listener failure aborts settlement).
3. **A shared kernel** for cross-cutting types: `Money` (NUMERIC(18,4) EGP), `Weight`, `Karat`, `BranchTime`, `DisplayRef`.

### 5.2 Why not microservices

- **Settlement atomicity.** Seller-proceeds + commission + spread + VAT + escrow-in + escrow-out + buyer-hold-release must commit or roll back as one transaction. Split them across services and you either need a saga (which is a distributed lock in disguise on the ledger row lock we already have for free) or you accept the ledger can be temporarily inconsistent — which contradicts the entire schema design.
- **Team size.** A microservice architecture is a coordination tax paid to allow parallel independent deploys. Dahab's team is under 10 engineers. The tax exceeds the benefit.
- **Extraction path.** The module structure below is *ready* to be split: each module owns its tables, dependencies are events. When one module (e.g. Notifications) genuinely benefits from an independent scale/lifecycle, it can be extracted without touching the others.

### 5.3 High-level system diagram

```
┌────────────────────────────────────────────────────────────────────┐
│  Mobile (Flutter)         Admin (Vue3+Vuetify)      Public (SSR)   │
│      buyer & seller          operators                marketplace  │
└──────────┬───────────────────────┬───────────────────────┬─────────┘
           │ Sanctum bearer tokens │ Sanctum bearer tokens │  (no auth)
           ▼                       ▼                       ▼
        ┌─────────────────────────────────────────────────────────┐
        │  Laravel API (/v1)  — Nginx + PHP-FPM + Octane optional │
        │   ┌────────────────────────────────────────────────┐    │
        │   │  Modules/                                       │   │
        │   │   Identity/  Marketplace/  Orders/  Inspection/ │   │
        │   │   Wallet/    Ledger/       Settlement/          │   │
        │   │   Notifications/  Admin/   Reports/  Audit/     │   │
        │   └────────────────────────────────────────────────┘    │
        └────────────┬───────────────────────┬────────────────────┘
                     │                       │
                     ▼                       ▼
           ┌──────────────────┐    ┌─────────────────────┐
           │ PostgreSQL 15    │    │ Redis 7 (queues,    │
           │  RLS + grants    │    │  cache, throttling) │
           │  role per staff  │    └─────────┬───────────┘
           │  session mode    │              │
           │  PgBouncer       │              ▼
           └──────────────────┘    ┌─────────────────────┐
                                   │ Horizon workers     │
                                   │  deadlines / notify │
                                   │  ETA / FCM / email  │
                                   └─────────────────────┘

External: Evolve (gold rate), Rapaport (weekly upload), IGI (in-app), ETA (e-invoicing)
```

### 5.4 Directory layout (proposal)

```
app/
├── Modules/
│   ├── Identity/
│   │   ├── Domain/              # Customer, Staff, Verification, entities & value objects
│   │   ├── Application/         # Use cases (RegisterCustomer, ApproveIdentityDocument…)
│   │   ├── Infrastructure/      # Eloquent models, repositories, external services
│   │   └── Http/                # Controllers, Requests, Resources, Routes
│   ├── Marketplace/             # Listing, ListingMedia, BuyRequest queue
│   ├── Orders/                  # Order aggregate, transitions, deadline extension
│   ├── Inspection/              # InspectionResult, SettlementDecision
│   ├── Wallet/                  # Customer-facing wallet reads, top-up, payout accounts, withdrawal
│   ├── Ledger/                  # MoneyService (single writer), accounts, postings, transaction builder
│   ├── Settlement/              # Balance payment, first-sale advance, refund, deposit forfeiture
│   ├── Notifications/           # Push (FCM), SMS, Email, In-app; unified NotificationRouter
│   ├── Admin/                   # Admin-only endpoints, staff sessions, permission resolver
│   ├── Reports/                 # Daily close, solvency, statements
│   ├── Audit/                   # AuditLog writer, DocumentViewLog writer, helpers
│   └── Reference/               # Karat, PieceType, Branch, BranchHours, Setting, WorkingHoursResolver
├── Shared/
│   ├── Money/                   # Money value object (NUMERIC(18,4))
│   ├── Weight/                  # Weight VO (NUMERIC(10,3))
│   ├── Result/                  # Result/Either types for controllers
│   ├── Idempotency/             # Idempotency-Key middleware and store
│   └── DB/                      # Role switcher, RLS binder (SET LOCAL app.current_customer_id)
├── Http/
│   ├── Middleware/              # Auth, RLS binder, idempotency, staff role connection switch
│   └── Kernel.php
└── Providers/
    └── ModulesServiceProvider.php  # Auto-registers each module's routes/events/policies
```

Each module has **one public interface class** (`Module\Public\ModuleApi`) that other modules import. Cross-module DB writes are forbidden; cross-module reads via queries are permitted but discouraged in favour of the public API. Enforced by a static analysis pass in CI.

---

## 6. Domain Modules (bounded contexts)

For each: purpose · main entities · main operations · key invariants · dependencies · events · security.

### 6.1 Identity

- **Purpose:** who someone is and whether they may act.
- **Entities:** `Customer`, `IdentityDocument`, `Staff`, `LegalDocument`, `AgreementAcceptance`, `FounderDeviceApproval`, `AccountFreeze`.
- **Ops:** register, sign in (+OTP on new device), password reset, upload ID, review ID (admin), suspend/reinstate (admin), staff CRUD (admin), founder freeze/unfreeze, device approvals.
- **Invariants:** phone unique; passwords Argon2id; suspended customers can read but not trade; suspension needs actor + reason (CHECK); every legal-doc acceptance immutable.
- **Events:** `IdentityVerified`, `CustomerSuspended`, `CustomerReinstated`, `StaffCreated`, `PermissionChanged`.

### 6.2 Marketplace

- **Purpose:** the listing lifecycle and the buyer queue.
- **Entities:** `Listing`, `ListingMedia`, `ListingBranchOption`, `ListingOwnershipDeclaration`, `BuyRequest`, `ListingQueueSeq`.
- **Ops:** create/edit draft, submit for review, admin approve/request-changes/takedown, join queue, leave queue, seller sees queue, accept-head-of-queue.
- **Invariants:** one active request per (listing, buyer); `queue_position` monotonic per listing; branch chosen at acceptance must be in listing's named options; gold/gold-with-diamond require karat+weight.
- **Events:** `ListingApproved`, `ListingWithdrawn`, `BuyRequestQueued`, `BuyRequestReleased`, `BuyRequestAccepted`.

### 6.3 Orders

- **Purpose:** one accepted buy → the delivery + inspection + payment lifecycle.
- **Entities:** `Order`, `OrderBranchChange`, `OrderDeadlineExtension`, `SellerCancellation`.
- **Ops:** create (from accept), seller-cancel, admin change-branch, admin extend-deadline; state machine driven by `order_transition`.
- **Invariants:** `assert_order_transition` guards every state change; `trg_order_branch_subset` restricts branch to the listing's options; `order_ref` unique.
- **Events:** `OrderCreated`, `OrderCancelled`, `OrderBranchChanged`, `OrderDeadlineExtended`.

### 6.4 Inspection

- **Purpose:** IGI's role and the immutable inspection record.
- **Entities:** `InspectionResult`, `SettlementDecision`.
- **Ops:** IGI receive, IGI submit result (with server-derived outcome), buyer accept/decline adjustment.
- **Invariants:** append-only; karat rule structural; `karat_mismatch_forces_cancel`; branch-scoped (`inspection.branch_id = session.branch_id`).
- **Events:** `InspectionReceived`, `InspectionResultRecorded`, `KaratMismatchDetected`, `WeightAdjustmentRequired`, `SettlementDecisionRecorded`.

### 6.5 Wallet

- **Purpose:** customer-facing money surface (their two figures, their history, their payouts).
- **Entities:** `Account` (per-customer available/held, singleton internal accounts), `PayoutAccount`, `Withdrawal`, `WithdrawalPause`.
- **Ops:** get wallet, get transactions, top-up (via payment gateway integration in Part 4 of spec — MVP note in §26), add payout account, withdraw, cancel withdrawal.
- **Invariants:** `assert_customer_account_nonneg`; withdrawal ⇒ email second-check; account change ⇒ 48h pause + cancel in-flight withdrawal.
- **Events:** `TopupSettled`, `WithdrawalRequested`, `PayoutAccountChanged`.

### 6.6 Ledger (the writer)

- **Purpose:** the single writer of `ledger_transaction` + `ledger_posting`.
- **Entities:** `MoneyService` (application service), `LedgerTransactionBuilder` (fluent builder that composes balanced sets), `AccountResolver`.
- **Ops:** every `event_kind` in `ledger_event_kind` has one constructor method on `MoneyService`.
- **Invariants:** append-only (DB trigger); balanced-per-transaction (deferred trigger); actor required (CHECK); no float, no rounding drift (residue posted to a `dahab_*` account, §9).
- **Events:** `LedgerTransactionPosted{event_kind, txn_id}` (used by Reports).

### 6.7 Settlement

- **Purpose:** orchestrates the money moves the ledger writes, at the right business events.
- **Entities:** `BalancePayment`, `FirstSaleAdvance`, `DepositForfeiture`, `AdminRefund`, `AdminCompensation`.
- **Ops:** `payBalance(order)`, `advanceFirstSale(order)`, `forfeitDeposit(order)`, `refundInFull(order)`, `payCompensation(customer, amount)`.
- **Invariants:** IGI-confirmed weight is the settlement basis; escrow pass-through preserved; first-sale reconciliation reduces settlement leg by advance.
- **Events:** `OrderSettled`, `AdvanceIssued`, `DepositForfeited`, `RefundIssued`.

### 6.8 Notifications

- **Purpose:** unified in-app + push (FCM) + SMS + email fan-out.
- **Entities:** `NotificationTemplate`, `NotificationSend`.
- **Ops:** `notify(customer, template, context, channels)`. Channels selected per template + user preference.
- **Invariants:** every important event routes through here (never `Mail::send()` scattered).
- **Events:** consumes almost all domain events.

### 6.9 Admin

- **Purpose:** the staff-facing surface — reviews, releases, controls.
- **Entities:** none of its own; orchestrates other modules.
- **Ops:** permission resolver reads the matrix (Part 1 §4); role-per-staff DB connection switch (Part 1 §5.2); founder security sublayer.
- **Invariants:** `authorize()` runs matrix check *and* the DB grant is the backstop.

### 6.10 Reports

- **Purpose:** the numbers a founder needs to see and to file.
- **Entities:** `TaxInvoice`, `BankMovement`, `DailyClose`, `MarketMakerApproval`.
- **Ops:** solvency read, daily close, statements, exports, tax-invoice issuance and ETA filing hook.
- **Invariants:** locked `daily_close` cannot reopen.

### 6.11 Audit

- **Purpose:** the append-only truth of who did what.
- **Entities:** `AuditLog`, `DocumentViewLog`.
- **Ops:** `AuditLogger::log(actor, action, entity, before, after, reason)`.
- **Invariants:** append-only (DB trigger); actor required (CHECK); reason required for reason-mandatory actions (Part 1 §6); doc view is written *in the same transaction* as the read.

### 6.12 Reference

- **Purpose:** the data-not-code layer.
- **Entities:** `Karat`, `PieceType`, `Branch`, `BranchHours`, `BranchClosure`, `Setting`, `SettingHistory`.
- **Ops:** read live (never cached across requests for money-affecting keys); admin edits with audit and history.
- **Contains:** the shared `WorkingHoursResolver` (Part 3 §1) — used by Orders and Settlement, implemented once.

---

## 7. Laravel Architecture (Application Layer)

### 7.1 Where business logic lives (per request)

```
HTTP Request
     │
     ▼
Middleware pipeline
  · Sanctum auth  →  authenticated actor (customer or staff)
  · IdempotencyKey middleware  (money-moving / state-creating POSTs)
  · RLS binder  (SET LOCAL app.current_customer_id for customer)
  · Staff DB role switch  (SET ROLE dahab_finance for staff)
  · OpenApi request validation  (contract-first — see §16)
     │
     ▼
Controller (thin — orchestrates only)
     │
     ▼
FormRequest (Laravel FormRequest)
  · syntactic validation only (types, required, ranges)
     │
     ▼
Application Service / Use Case
  · reads live actor row (never token claims)
  · runs the authorization check against the permission matrix
  · opens the transaction:  DB::transaction(function () { … })
  · calls Domain Service(s)
  · records AuditLog + fires domain events (in-transaction)
     │
     ▼
Domain Service  (aggregate-scoped)
  · enforces invariants
  · returns a Result / throws a DomainException
     │
     ▼
Repository (Eloquent-backed)
  · single-aggregate persistence
  · complex reads bypass Eloquent for a query builder
     │
     ▼
Postgres
  · deferred constraints fire at COMMIT
  · state-transition trigger fires on any state change
  · balanced-ledger trigger fires per ledger_transaction
```

**One transaction rule:** every state change writes its ledger, its audit, and its state update **inside one DB transaction**. Events dispatched inside are collected by Laravel and fired *after* commit via queued listeners (`ShouldQueueAfterCommit` in Laravel 11). A queued listener failing does not corrupt the state.

### 7.2 Controllers stay thin

- HTTP concerns only: bind request → call use case → shape response.
- No business rules in controllers, no DB queries in controllers.
- API resources (`JsonResource`) shape the response and enforce the "never leak seller_id" rule at the last line of defence.

### 7.3 Repositories are optional and specific

We use repositories only where they earn their keep — the `BuyRequestQueueRepository` (which owns the `FOR UPDATE` locking dance around `listing_queue_seq`) is a good example. Bare CRUD does not need a repository; Eloquent is enough. Do not build a `UserRepository` for `User::find()`.

### 7.4 Domain events → queued listeners

- Emit events *inside* the transaction that produced them (Laravel's event dispatcher).
- Listeners implement `ShouldQueueAfterCommit`. If the transaction rolls back, no listener runs.
- Handlers write to `notifications`, external APIs, denormalised read tables, and non-critical side effects only.
- **Never** put critical financial state into an event listener. Anything that must be true after the request is over must be in the same transaction as the state change.

### 7.5 Jobs and workers

- Deadline sweeps (§11) are `Command`s dispatched by the Scheduler and processed by Horizon queues.
- Each sweep is per-row idempotent: it re-reads the current state under a lock, applies the transition only if still applicable, and commits.
- The **system actor** for these jobs is a dedicated `staff` row with role `operations` (or a new synthetic role — see §30 OI-4.1 if we want a distinct `system` role in the enum).

### 7.6 Policies and Gates

- Customer policies enforced by RLS in Postgres (schema §19).
- Staff authorization enforced by a single `PermissionMatrix` service that reads the matrix (Part 1 §4) — Laravel Policies are the entry points but delegate to this service.
- Wallet-touching actions run the matrix check AND rely on the DB grant as the backstop.

### 7.7 Notifications routing

- `Notification` classes per event (idiomatic Laravel).
- Channels chosen at the template level: `fcm`, `mail`, `sms` (Twilio or a local Egyptian aggregator — Vodafone SMS gateway or JorAK), `database` (in-app).
- Locale from `customer.preferred_lang` ('ar' | 'en').

### 7.8 Do-not-over-engineer list

- No CQRS unless a read genuinely can't be served by a query.
- No Event Sourcing. The ledger already gives us the state-derived-from-events property where it matters.
- No hexagonal / ports-and-adapters abstraction on every module. Use it in Ledger and Notifications (where an external system might change), skip it elsewhere.
- No repository-per-Eloquent-model reflex. Use Eloquent directly for straightforward CRUD.
- Feature flags: use the `setting` table only when a flip is expected.

---

## 8. Database Strategy

### 8.1 Overall classification

- **KEEP:** ~95% of the schema. It is production-grade. The design invariants (double-entry, append-only, deferred balancing, RLS, transitions-as-data, role grants) are the exact right choices for this domain.
- **MODIFY:** small clarifications only (§8.2).
- **REMOVE:** none.
- **ADD:** the operational tables listed in §8.3.
- **INVESTIGATE:** the items flagged in §8.4.

### 8.2 MODIFY (small)

| Change | Why |
|---|---|
| Add `staff_role` enum value `'system'` OR designate a specific staff row with role `operations` as the canonical system actor for sweep jobs. Choose one and document it. | Sweep jobs need an attributable actor (`ledger_txn_has_actor`, `audit_has_actor`). Muddling with a real operator's row is wrong. Adding a `'system'` role is cleaner but is a schema change. **Decision to make in Phase 0.** |
| `customer.email` currently `CITEXT UNIQUE` but nullable — verify with clinic whether the email second-check on withdrawal requires the email to be **verified**, not just present. Add `email_verified_at TIMESTAMPTZ` if so. | Part 1 §2.4 already implies verification; make it explicit. |
| `withdrawal` table lacks the "pending hold" account balancing at request time made explicit. The spec says "move from `cust_available` into a pending state" but no `pending_withdrawal` account kind exists in `account_kind`. Two options: (a) hold in `cust_held` with a discriminator; (b) add a new account_kind `cust_pending_withdrawal`. Recommend (b) for clarity. | The current `cust_held` semantics is "reserved for a specific open order". Piggybacking withdrawal onto it conflates purposes. |
| `order.locked_total_price` — clarify comment to say "locks the per-gram rate and formula, not the final total; final trues up on IGI weight" (already in Part 2/3 spec, mirror in schema comment). | Prevents future misreading. |

### 8.3 ADD

| Table / feature | Purpose |
|---|---|
| `idempotency_key` (`key TEXT PK`, `actor_id UUID`, `request_hash TEXT`, `response_json JSONB`, `created_at`, `expires_at`) | Server-side idempotency store (Part 2 §0). Redis is a cache; the DB is the source of truth for money-affecting replay. |
| `otp_challenge` (short-lived, or Redis-only) | New-device OTP challenge; Redis is acceptable if lockout is DB-backed. |
| `notification_send` (`id`, `customer_id`, `template_code`, `channel`, `payload_json`, `status`, `sent_at`, `error`) | Audit trail of what went out. |
| `refresh_token` / `personal_access_token` from Sanctum | Standard. |
| `session_device` (per customer/staff, trusted devices) | Backs the "known device" logic (Part 1 §2.3, §3.3). Fingerprints are hashed. |
| `first_sale_advance` (`id`, `order_id`, `seller_id`, `amount`, `promo_code`, `enabled_by`, `ledger_txn_id`) | Explicit record beyond the ledger event; makes reconciliation reports fast. |
| `exchange_rate_snapshot` (rate, source='evolve'|'manual', taken_at, taken_by (nullable), corrections_applied {buy,sell}) | Every price shown to a customer must be reproducible. Snapshots at `join queue` and at `pay-balance`. |
| `notification_preference` (`customer_id`, `channel`, `event_family`, `enabled`) | Users can toggle non-critical notifications. Critical ones (KYC, money) are non-optional. |
| `job_run_log` (`job`, `system_actor_staff_id`, `started_at`, `finished_at`, `rows_processed`, `error`) | Sweeps must be observable. Not strictly required but strongly recommended. |
| Partial indexes | `CREATE INDEX ON "order"(reach_branch_deadline) WHERE state='awaiting_delivery'`, `ON "order"(balance_due_deadline) WHERE state='awaiting_balance'`, `ON "order"(collect_deadline) WHERE state='ready_to_collect'`, `ON buy_request(seller_reply_deadline) WHERE state='queued'`. Feeds the sweeps efficiently. |
| Materialised views (optional) | `mv_solvency` refreshed at daily close for the dashboard; live `solvency_check` remains the truth. |

### 8.4 INVESTIGATE

- **Table partitioning for `ledger_posting` and `audit_log`.** At any material transaction volume, these grow monotonically. Partition by month (RANGE on `created_at`) once we're at ~5M postings; not day-one. Add the tooling now, flip the switch later.
- **`identity_document.storage_ref` encryption path.** The comment says "encrypted at rest (application-side or pgcrypto)". Prefer application-side envelope encryption with a KMS-held key (AWS KMS or equivalent). Details in §18.
- **`document_view_log` retention.** Legal-clinic OI-1.5 (audit retention). Until then, keep everything.
- **Full-text search on listings** (search by seller-provided description, piece type). Postgres `tsvector` is enough at MVP scale; no Elasticsearch.

### 8.5 Concurrency and locking

- Queue mutations serialise on `listing_queue_seq` (`FOR UPDATE`). Two joins can never collide.
- Order state changes: `SELECT … FOR UPDATE` on the order row inside the use case; combined with `assert_order_transition` trigger, illegal moves surface as `illegal_order_transition` even under a race.
- Withdrawal release: transactional row-lock plus a re-check of `withdrawal_pause` (a pause opened after review-claim must still block release).
- Ledger posting: DEFERRED constraint trigger means we can insert all legs and let commit validate. No explicit locking of `dahab_*` accounts is needed because they are single-row targets of many concurrent atomic inserts, and Postgres MVCC handles that natively — the only invariant is per-transaction balance, which is enforced.

### 8.6 Migrations

- Laravel migrations wrap the existing SQL files, executed in order. First migration is a `DB::unprepared(file_get_contents('database/sql/00_schema_full.sql'))`. This is fine for a greenfield build.
- After that, all migrations are ordinary Laravel migrations, small, reversible where cheap.
- Every schema change ships with an audit table entry in the release notes.

---

## 9. Wallet & Ledger Strategy

The schema already provides the correct model; this section states the *application-layer contract* for the single ledger writer.

### 9.1 The invariants (already enforced in DB — restated for clarity)

1. Sum of postings per `ledger_transaction` = 0 (deferred trigger).
2. Sum of all postings across all accounts = 0 (invariant, testable via `ledger_global_zero` view).
3. Customer accounts (`cust_available`, `cust_held`, and the new `cust_pending_withdrawal`) never go negative (deferred trigger). Internal accounts (escrow, bank, equity) can be any sign.
4. Every `ledger_transaction` has at least one actor (CHECK).
5. `ledger_posting` and `ledger_transaction` are append-only (triggers).
6. Balances are **derived**, never stored. `customer_wallet` view is the read.

### 9.2 The application-layer contract: `MoneyService`

- **The only class in the codebase** that writes `ledger_posting`. Enforced by:
  - Postgres grants: only the DB role bound to the app can write, and even inside the app, all writes go through this service.
  - Static analysis rule in CI (PHPStan custom rule) that fails a build if `LedgerPosting::create` or `ledger_posting` insert appears anywhere else.
- Public methods, one per `event_kind`:

```
holdDeposit(BuyRequest)
releaseDeposit(BuyRequest, releaseKind)
forfeitDeposit(Order)        // split per setting
settleOrder(Order)           // the big one — see §11
advanceFirstSale(Order)
withdrawalRequest(Withdrawal) // available → cust_pending_withdrawal
withdrawalRelease(Withdrawal) // cust_pending_withdrawal → bank
withdrawalReject(Withdrawal)  // cust_pending_withdrawal → available
withdrawalCancel(Withdrawal)  // cust_pending_withdrawal → available
topupSettle(Customer, amount)
compensation(Customer, amount, actor)
walletAdjustment(Customer, delta, actor, reason)  // CEO only
recordBankMovement(kind, amount, actor, reason)
reverseTransaction(originalTxnId, actor, reason)
```

Each method:

1. Opens the DB transaction (or asserts inside one).
2. Loads live settings (rates, deposit percentages, corrections).
3. Constructs a balanced `LedgerTransactionBuilder`.
4. Sets `customer_id` and/or `staff_id` (actor).
5. Persists in one insert-set.
6. Writes the corresponding `audit_log` row.
7. Returns the `ledger_txn_id`.

### 9.3 Idempotency and duplicate prevention

- `Idempotency-Key` header required on every money-moving POST (§16).
- The store (`idempotency_key` table) records the request fingerprint + response. Replay = return the cached response, without side effects.
- Deposit hold uses a partial unique index (`one_active_request_per_buyer_listing`) as a second line of defence.
- Withdrawal release is guarded by a row-level lock + state check inside the transaction.
- Settlement is guarded by an order-state precondition (`awaiting_balance` → `ready_to_collect`); a replay after commit finds `ready_to_collect` and returns idempotent success without a second ledger write.

### 9.4 Race conditions and concurrent requests

- Queue joins serialise on `listing_queue_seq` (§8.5).
- Two `pay-balance` from a double-tap: idempotency key rejects the second; without a key, the order-state check inside the transaction (only `awaiting_balance` allowed) rejects it.
- A seller cancel racing with a buyer accept: rare — accept locks the buy_request row, cancel targets the order that does not yet exist. Once acceptance commits, the order is there and the cancel path applies. Once cancel commits, the buy_request has moved out of `queued`. Either way, the loser gets `illegal_order_transition`.
- Race on the wallet non-negative constraint: the deferred trigger sees the *final* state at commit. Two concurrent holds against the same wallet may both look valid mid-transaction; whichever commits second gets the exception. The caller retries or reports `insufficient_funds`.

### 9.5 Recovery, reconciliation, and auditability

- **Failed transactions:** roll back atomically. There is no partial state. Application logs and Sentry capture the exception.
- **Reconciliation:** daily close (§10 admin, schema §17) compares bank + owed. Any diff opens a case. `ledger_global_zero` is CI-monitorable.
- **Corrections:** only via `reverses_txn_id` — a new transaction that undoes the previous. Nothing is ever edited or deleted.
- **Audit trail:** every posting sets carries its `ledger_txn_id`, its event, its actor, and (via triggers/logs) the request that produced it.

### 9.6 Rounding rule (from spec §3.5)

- Round-half-up to 4 dp.
- Residue posts to `dahab_spread` on gold sales, `dahab_commission` otherwise.
- No customer leg ever carries a rounding artefact.
- Every worked example in tests asserts the residue posting explicitly.

---

## 10. Buy Request State Machine

*Derived from the schema `buy_request_state` enum and the `buy_request_transition` seed rows.*

### 10.1 States

- `queued` — deposit held, price locked, waiting for the seller
- `accepted` — seller took this request; an `order` now exists
- `released_not_chosen` — seller accepted someone ahead in the queue; refunded
- `released_declined` — seller explicitly declined; refunded
- `released_expired` — seller reply deadline passed; refunded
- `withdrawn_by_buyer` — buyer left the queue; refunded

### 10.2 Transitions (only these five)

```
queued → accepted              (seller.accept: only the head)
queued → released_not_chosen   (seller accepted someone else — atomic with their accept)
queued → released_declined     (seller decline)
queued → released_expired      (deadline sweep, per request)
queued → withdrawn_by_buyer    (buyer.withdraw)
```

All terminal states are one hop from `queued`. No back-edges.

### 10.3 Who triggers what

| Transition | Actor | Trigger |
|---|---|---|
| `queued → accepted` | Seller | POST /listings/{id}/accept (only for the head request) |
| `queued → released_not_chosen` | Seller | Side effect of accepting a *different* request — atomic with the accept |
| `queued → released_declined` | Seller | POST /listings/{id}/decline-request/{req_id} (optional; see §30 OI-4.2) |
| `queued → released_expired` | System | Seller-reply sweep past `seller_reply_deadline` |
| `queued → withdrawn_by_buyer` | Buyer | POST /me/buy-requests/{id}/withdraw |

### 10.4 Side effects on transition

- Every terminal transition **releases the deposit** via `MoneyService::releaseDeposit()` in the same transaction.
- Every transition writes an audit row (the buyer/seller/system as actor).
- `trg_sync_queue` recomputes `listing.active_queue_count` and, when the queue empties, flips listing `reserved → live`.
- On `queued → accepted`, an `order` is inserted and all *other* `queued` rows for the same listing move to `released_not_chosen` — atomically.
- On `withdrawn_by_buyer` with `notify_when_free = true`, the buyer is enrolled in the notify-when-free workflow (fires only when the listing returns to `live` with zero active requests).

### 10.5 Order-creation timing (validated)

An `Order` is **created at seller acceptance**, not earlier. Rationale: only at acceptance are (a) the specific buyer chosen, (b) the branch selected, (c) the reach-branch deadline computable. The buy_request carries the locked price *before* an order exists, which is why the deposit and price-lock live on the buy_request, not the order.

### 10.6 Expiration and cancellation logic

- **Buy-request expiration** (`seller_reply_deadline`): swept per request. FIFO integrity preserved — if the head expires, the next queued becomes the new head.
- **Buyer cancellation** = withdraw. No penalty. Deposit refunded in full.
- **Seller decline** = individual `queued → released_declined`. Not a cancel-all.
- **Listing takedown** by admin: releases *all* active requests atomically.

---

## 11. Pricing & Price Lock

*Cross-references Part 3 spec §2, §3. Values here are the schema seeds; every one is a `setting`.*

### 11.1 Inputs

| Input | Source |
|---|---|
| Gold rate `R` | Evolve API (Part 4 of spec, not yet written); fallback manual price |
| `price_correction.buy_side`, `.sell_side` | Setting (EGP/g, seeded −15 / +15) |
| Stated weight `W`, karat | Seller at listing, IGI at inspection (IGI wins for settlement) |
| Purity `p` | `karat.purity_ratio` |
| Making charge | Seller (gold only) |
| Asking price | Seller (stones only) |
| Commission | `setting: commission.gold_pct (20%)`, `commission.stone_pct (5%)`, `commission.minimum_egp (200)` |
| VAT | `setting: vat.pct (14%)` on commission only |
| Deposit % | `setting: deposit.buyer_pct (20%)` |

### 11.2 Two rates, one formula (gold)

```
R_sell = R + price_correction.sell_side
R_buy  = R + price_correction.buy_side
buyer_total  = R_sell × W × p + making_charge_per_g × W
seller_gross = R_buy  × W × p + making_charge_per_g × W
spread       = (R_sell − R_buy) × W × p
commission   = max(commission.gold_pct × (making_charge_per_g × W), commission.minimum_egp)
vat          = vat.pct × commission
seller_proceeds = seller_gross − commission − vat
```

### 11.3 Diamond / gold-with-diamond

```
buyer_total  = asking_price                            # (no split, no spread)
commission   = max(commission.stone_pct × value_above_gold, commission.minimum_egp)
vat          = vat.pct × commission
seller_proceeds = asking_price − commission − vat      # (gold value protected; commission on stone value only)
```

### 11.4 Price lock behaviour

- `locked_total_price` on `buy_request` locks **the per-gram rate + the formula**, not a frozen scalar.
- Deposit is `deposit.buyer_pct × locked_total_price` at request time.
- At `pay-balance`, everything recomputes on the IGI-confirmed weight. If the new total is below the deposit, the excess deposit is refunded to `cust_available` in the same settlement transaction.
- Price expiration = seller-reply deadline. If not accepted in time, the lock dies with the request.

### 11.5 Reproducibility

- Every buy_request snapshots `locked_unit_rate` (the exact `R` at that instant) and `locked_total_price`.
- Every settlement recomputes on IGI weight and posts the exact figures.
- `exchange_rate_snapshot` (ADD, §8.3) preserves the corrections applied at both moments.
- Any historical order can be re-derived exactly from the ledger and these snapshots.

### 11.6 Manual price fallback (Evolve down)

- `POST /admin/gold-price/manual` — CEO or Finance.
- Deviation > 10% → `manual_price_confirm_required` (second confirmation).
- While active, banner shown to customers.
- System reverts to Evolve automatically on recovery.

---

## 12. Inspection Workflow

*From schema §10 and Part 3 §7.*

### 12.1 Actors

- **IGI branch account** (shared, branch-scoped). Sees no prices, no wallets, no contact details beyond what handover requires.
- **Buyer** (approves/declines an above-tolerance adjustment).
- **Founders/CEO** (can also enter results, per matrix — for exceptional cases).

### 12.2 Flow

```
Order awaiting_delivery
   │  (seller arrives with piece; IGI receive)
   ▼
Order at_inspection ── inspection_result inserted (immutable) ───┐
   │                                                             │
   ▼  outcome=pass                                                │
Order inspection_passed → awaiting_balance   (fire first-sale advance if eligible)
   │
   ▼  outcome=weight_adjust | stone_regrade
Order weight_adjust_pending
   │  buyer accepts → awaiting_balance
   │  buyer declines → cancelled_inspection (refund deposit; seller NOT suspended)
   ▼
   ▼  outcome=karat_cancel | fake_cancel
Order cancelled_inspection
   · refund deposit in full
   · SUSPEND SELLER
   · piece returns to seller (no compensation to seller)
```

### 12.3 Karat rule (structural)

Any karat difference → `karat_cancel`. Enforced by CHECK constraints. No role can override, no setting can loosen. Seller is suspended immediately.

### 12.4 Weight tolerance

`≤ 1.5%` → auto-adjust; all figures recompute on `measured_weight_g`. Above → buyer approval required. No settlement can fire on stated weight; IGI weight is always the settlement basis.

### 12.5 Corrections (super seding)

- New row with `supersedes_id` set. Original never edited (`inspection_no_update` trigger).
- Settlement reads the **latest non-superseded** result.
- The correcting actor must be a valid `igi_branch` for the order's branch.

### 12.6 Evidence

- `certificate_number` for stone certificates.
- Optional inspection notes; free text.
- Photos at inspection are stored via the same S3 pipeline; access requires the actor's role scope.
- The signed IGI certificate PDF (Part 4 integration) attaches once received.

### 12.7 Consequences by outcome

| Outcome | Buyer | Seller | Ledger |
|---|---|---|---|
| `pass` | proceeds to pay-balance | (waits for buyer payment, or advance fires) | first_sale_payout if eligible; nothing else here |
| `weight_adjust` accepted | pays adjusted balance | (waits) | nothing here |
| `weight_adjust` declined | refunded in full | not suspended | deposit_release |
| `stone_regrade` accepted | pays adjusted balance | (waits) | nothing here |
| `stone_regrade` declined | refunded in full | not suspended | deposit_release |
| `karat_cancel` / `fake_cancel` | refunded in full | **suspended**; collects piece back (no compensation) | deposit_release; suspension record |

---

## 13. Settlement Workflow

*From Part 3 §3. Every leg is a `ledger_posting` in one balanced transaction.*

### 13.1 When settlement fires

**At `POST /orders/{id}/pay-balance`** — not at handover. Handover is physical only.

### 13.2 The one-transaction ledger set (gold, no advance)

```
buyer  cust_available   − balance
buyer  cust_held        − deposit               (deposit released from hold)
escrow                  + buyer_total           (pass-through in)
escrow                  − buyer_total           (pass-through out)
seller cust_available   + seller_proceeds       (settlement_seller)
dahab_commission        + commission            (commission)
vat_payable             + vat                   (vat)
dahab_spread            + spread                (spread; gold only)
============================================
sum = 0  ✓
```

### 13.3 With first-sale advance (previously advanced at IGI receive)

The seller has already received `advance` from `first_sale_payout` at IGI receive. At settlement:

```
buyer  cust_available   − balance
buyer  cust_held        − deposit
escrow                  + buyer_total
escrow                  − buyer_total
seller cust_available   + (seller_proceeds − advance)   # net of what they already got
external_equity         + advance                        # Dahab recovers its front
dahab_commission        + commission
vat_payable             + vat
dahab_spread            + spread                         # gold only
```

If IGI weight makes `seller_proceeds < advance` (shortfall), Dahab absorbs the difference as the incentive's realised cost; no clawback from the seller.

### 13.4 Refund after payment (uncollected-paid disposition, dispute)

Source is `escrow` (deliberately preserved for this). Constructed as a reversal transaction with a named actor and reason. Splits between `escrow` (buyer's balance portion) and `dahab_*` (commission/spread/vat portions) if the resolution requires undoing Dahab's take too.

### 13.5 Buyer no-pay (deposit forfeiture)

No settlement fires (buyer never paid). Deposit split 50/50:

```
buyer  cust_held             − deposit
seller cust_available        + (deposit × 50%)      # deposit_forfeit (seller portion)
dahab_commission (or dahab_forfeit_income if we add one)
                             + (deposit × 50%)
```

Piece returns to seller (listing state; §14).

### 13.6 Atomicity, idempotency, retry

- Whole set writes in one `DB::transaction`.
- Idempotency-Key at the endpoint prevents double execution.
- State precondition (`awaiting_balance`) rejects retries after commit.
- Deferred triggers validate at commit; any imbalance rolls back atomically.

### 13.7 Reconciliation

- Every tax invoice `tax_invoice` row for the order references the same `ledger_txn_id` for both `party_role`s.
- `daily_close` compares bank balance vs owed vs Dahab wallet.
- `solvency_check` view is a real-time invariant read; on-call monitoring alerts on `headroom < 0`.

---

## 14. Authentication & Authorization

### 14.1 Choice: Laravel Sanctum (both mobile and admin)

**Why not Passport:** OAuth2 flows (authorization code, client credentials) do not fit a phone+password + OTP mobile app or a first-party admin dashboard. Passport is heavier for no benefit.

**Sanctum fits because:**
- Per-device personal-access tokens map cleanly to Part 1 §2.3 "device fingerprint recognised".
- Tokens carry abilities (scopes) that can encode role for staff and customer flag for buyers.
- Revocable per device (freeze the token when a device is untrusted; freeze all when a founder freezes an account).
- Simpler mental model, less code surface, easier audit.

Access tokens: 15 min TTL. Refresh via a separate `POST /auth/refresh` that rotates the token and issues a new one. Sessions live in `personal_access_tokens` with `expires_at`.

### 14.2 Customer flow

```
POST /auth/register (phone+password)
POST /auth/login    (phone+password, device_fp)
   ├── known device → 200 with tokens
   └── new device   → 202 with challenge_id (session withheld pending OTP)
POST /auth/otp/verify (challenge_id, otp, device_fp)
   → 200 with tokens; trust device
POST /auth/refresh, POST /auth/logout
POST /auth/password/reset-request → SMS/email link
POST /auth/password/reset-confirm → single-use token
```

> **As built (feature `001-auth-customer-staff`):** the customer endpoints above are served under `/api/v1/customer/auth/*` (Sanctum guard `customer`, `auth:customer`) — there is no generic `/api/v1/auth/*`. Every token carries exactly one ability (`customer:access` / `customer:refresh`); refresh is `POST /api/v1/customer/auth/refresh` and requires the refresh token. Passwords are Argon2id. Built so far: register, login (known device), refresh, me, logout, logout-all; OTP and password reset are not built yet. Source of truth: `specs/001-auth-customer-staff/`.

### 14.3 Staff flow

```
POST /admin/auth/login (email+password) — no OTP by default; consider TOTP as Phase-9 hardening (OI-4.3)
   → issues token bound to a database role (dahab_finance, dahab_operations, …)
```

Session timeout 30 min idle (matrix §4). Failed sign-in: 5 attempts / 30 min lock (DB-backed).

> **As built:** the staff surface is `/api/v1/dashboard/auth/*` (Sanctum guard `staff`, `auth:staff`), not `/admin/auth/*`. Staff tokens carry `staff:access` / `staff:refresh`; a customer token is `401` there and a staff token is `401` on the customer API. Authorization is Spatie roles/permissions (`staff.permission:<code>`). Built so far: `GET /dashboard/auth/me` and `POST /dashboard/auth/refresh`; staff login, MFA and logout are not built yet.

### 14.4 Founder sublayer

- `POST /admin/auth/login` from a new device on a founder account: session held pending the **other founder**'s approval (`founder_device_approval`).
- `POST /admin/founder/freeze` — freeze the other founder instantly.
- `POST /admin/founder/unfreeze` — requires *both* confirmations (`unfreeze_confirm_1/2`).
- Real-time notifications to both founders on sensitive actions (see OI-1.3 routing).

### 14.5 Authorization

- **Customer trade actions:** `trade_allowed = is_verified AND NOT is_suspended`, re-read from DB inside the request transaction.
- **Staff actions:** `PermissionMatrix::authorize($staff, $action)` — reads the matrix (Part 1 §4), plus the DB grant is the backstop for wallet-touching actions.
- **RLS:** `SET LOCAL app.current_customer_id = ?` inside every customer-facing transaction.

### 14.6 Devices, sessions, and revocation

- One token per device per user.
- User can list devices, revoke individual devices.
- Admin can revoke all a customer's or a staff's tokens (part of suspension / freeze).
- Refresh rotates on use (single-use refresh token).

### 14.7 Password rules

- Argon2id (Laravel default hasher).
- Minimum entropy check (zxcvbn score ≥ 3 or a length ≥ 10 with mixed classes).
- Never returned, never logged, never in error messages.
- Reset path issues a one-time signed token to the user's channel; the same phone as sign-in.

---

## 15. Admin Dashboard Architecture

Stack: **Vue 3 + Vite + TypeScript + Vuetify 3 + Pinia + Vue Router + Axios**.

### 15.1 Modules (screens map)

| Module | Screens | Permission gates |
|---|---|---|
| Dashboard | Overview (solvency headline, active queues, pending reviews, alerts) | All staff (own-view subset for non-CEO/Finance for wallet card) |
| Users | List, detail, KYC review, suspension | Operations/Verification/Founders |
| Listings | Queue, detail, approve, request changes, takedown | Operations/Founders |
| Buy Requests | (read-only) | Operations/Founders |
| Orders | List, detail, change-branch, extend-deadline, cancel, freeze, refund | Operations/Founders; refund CEO+Finance |
| Inspection | IGI-scoped list (branch-only), receive, submit result, handover confirm | IGI (branch), Founders |
| Wallets | Wallet balance, statement, adjustment, compensation | **CEO + Finance only** |
| Withdrawals | Review queue, release, reject | CEO + Finance |
| Deposits/Top-ups | Match incoming transfer | CEO + Finance |
| Bank movements | Record capital/rent/fees/profit | CEO + Finance |
| Daily Close | Solvency, close+lock | CEO + Finance |
| Gold Price | Live rate, manual price, corrections | CEO + Finance |
| Karats | Enable/disable | CEO + Finance |
| Commission/Spread rates | Change (audited) | CEO + Finance |
| Rapaport | Weekly upload | CEO + Finance |
| Categories | Stop new listings, pause, stop everything | Graduated (CEO for the strongest) |
| Market Makers | Approve piece, manage codes | CEO + Finance |
| Promo Codes | CRUD | CEO + Finance |
| Notifications | Templates, in-app broadcasts | Founders |
| Roles & Permissions | Staff CRUD, permission changes | Founders |
| Audit Log | Full log; own-actions for non-founders | Founders full; others own |
| Documents | Legal versions, publish | Founders |
| Reports | Sales, commissions, VAT, inspection outcomes, seller/buyer stats | CEO + Finance |
| Case Files | Build for law enforcement | CEO/COO/Finance |
| Disputes | Open list, resolve, pass on | Operations/Founders |
| Settings | Every tunable in `setting` | Per matrix per key |

### 15.2 Screen anatomy (standard shell)

Each screen must define: purpose, data, filters, actions, permissions, API deps, validation, empty/loading/error states. A generic `DataView` component enforces this — no hand-rolling.

### 15.3 State management (Pinia)

- One store per module (`useListingsStore`, `useOrdersStore`, `useWalletStore`, …).
- Stores never persist state that the server owns; they cache reads and reflect writes optimistically only for non-money actions.
- **Money-touching stores** always re-fetch after a mutation — no optimistic UI on wallet screens.

### 15.4 Guards and route protection

- Vue Router `beforeEach` reads a `me` claim (role) and blocks routes at the router.
- Server is the source of truth; a 403 from the API deposes the route.
- The COO literally has no route to `Wallets` — hidden and unreachable.

### 15.5 Design system

- Vuetify 3 with a small custom theme (Dahab brand colours; Arabic/English typography).
- RTL support at framework level (Vuetify supports it out of the box).
- Bilingual UI (`ar` / `en`) driven by `customer.preferred_lang` (staff choose their own via user setting).
- All money and weight displayed via shared formatters that respect precision (never `Number()` on a NUMERIC string).

### 15.6 Real-time updates

- Founder security alerts and daily-close deltas via Laravel Broadcasting on Redis + Laravel Echo (WebSocket via Reverb).
- Non-critical updates via polling; do not use WebSockets for the whole app.

---

## 16. Mobile Architecture (Flutter)

### 16.1 Single app, two experiences

Recommend **one Flutter app** that shows buyer and seller experiences based on the verified customer's actions. Rationale:

- A customer can be both (list one piece, buy another). Two apps means two accounts.
- Play/App Store review overhead ×2.
- Documentation confirms verification gate is per action, not per app.

Seller-only features (create listing, seller queue view, seller-return codes) are behind a "Sell" tab and gated by `trade_allowed`.

### 16.2 State management: Riverpod

Preferred over Provider (better testability) and BLoC (excessive ceremony for this app's shape).

### 16.3 Networking

- Dio for HTTP.
- Auto-attaches Sanctum bearer token.
- Interceptor generates and attaches `Idempotency-Key: <uuidv4>` on every POST/PATCH.
- On 401, kicks off refresh + retry once; on repeated 401 → logout.

### 16.4 Secure storage

- Tokens in flutter_secure_storage (Keychain on iOS, EncryptedSharedPreferences on Android).
- Never in SharedPreferences.
- Device fingerprint computed from a stable random UUID persisted in secure storage.

### 16.5 Push notifications

- Firebase Cloud Messaging (FCM) end-to-end.
- Two channels: transactional (order state, inspection, settlement) and marketing (rare, opt-in).
- Server routes via Laravel's `fcm` notification channel; token registered per device via `POST /me/devices`.

### 16.6 Screens (buyer + seller unified)

| Section | Screens |
|---|---|
| Auth | Splash, Onboarding, Register, Login, OTP, Password Reset |
| Profile | Me, Preferences, Language toggle, ID upload, ID status |
| Marketplace | Browse, Filters, Search, Listing detail, Wishlist |
| Buy | Buy request confirm, Deposit legal acceptance, My requests, Queue position, Notify-when-free |
| Sell | Sell a piece (wizard: photos, karat, weight, making charge, branches), Ownership declaration, My listings, Seller queue view, Accept flow (choose branch) |
| Orders | My orders (buyer+seller), Order detail, Inspection status, Adjustment approval, Pay balance, Collection code |
| Wallet | Balance, History, Top-up, Payout accounts, Withdrawal request (with email confirmation flow) |
| Notifications | Inbox, Preferences |
| Support | Terms, Privacy, FAQ, Contact |

### 16.7 Offline behaviour

- Read-only browsing may cache last-good listings for graceful degradation.
- No offline writes for money-affecting actions. Show an explicit "you are offline" and disable the submit.

### 16.8 Localisation

- Arabic and English out of the box.
- ARB files for translations.
- RTL support (Flutter's `MaterialApp.locale` + `Directionality`).

### 16.9 Build and release

- Android: Play Store, staged rollout.
- iOS: App Store, phased release.
- CI: Codemagic or GitHub Actions with fastlane; symbol upload to Sentry.

---

## 17. API Architecture

### 17.1 Style and conventions

- REST + JSON, `/v1` prefix, camelCase in resource fields (or snake_case — pick one and enforce with a linter; recommend **snake_case** to match the Postgres columns and the spec's JSON examples).
- ISO 8601 timestamps with offset.
- Money as decimal strings (never floats).
- Weights as decimal strings to 3 dp.
- Karat as integer.

### 17.2 Contract-first: OpenAPI

- The spec (Part 2) becomes an OpenAPI 3.1 document.
- Generated in `docs/openapi.yaml`; the source of truth for both the app codegen and the mobile client.
- CI validates every response against the schema.
- Mobile and admin clients generate from it (Dio + retrofit for Flutter; openapi-typescript for admin).

### 17.3 Authentication and authorization

- Sanctum bearer token in `Authorization: Bearer …`.
- Public browse routes don't require it.
- Every state-changing endpoint requires `Idempotency-Key` (UUID v4).
- Rate limits: 60 rpm per user for reads, 30 rpm for writes; auth endpoints have their own tighter limits.

### 17.4 Pagination, filtering, sorting

- Keyset pagination (`?limit=N&cursor=…`), never offset.
- Filters as `?category=gold&karat=21`.
- Sort explicit (`?sort=newest|price_asc|price_desc`).
- Full-text search: `?q=…` (Postgres tsvector at MVP).

### 17.5 Error envelope

```json
{ "error": { "code": "not_verified", "message": "…", "details": {} } }
```

Code catalog: Part 1 §9 (auth) + Part 2 §12 (domain).

### 17.6 File uploads

- Two-step: request a pre-signed URL via `POST /me/uploads`; upload directly to S3; return upload token; attach the token to the entity that owns it.
- Never proxy files through the Laravel app.

### 17.7 Idempotency

- Middleware records the key + response hash on first execution.
- Replay with same key: same response, no side effect.
- Key retained 24h (setting).

### 17.8 Rate limiting

- Redis-backed `RateLimiter` per user + per IP for auth endpoints.
- Public browse capped tighter to blunt scraping.

### 17.9 Versioning

- `/v1` today. `/v2` is the next major that we cannot make backward compatible. Small non-breaking additions ship inside `/v1`.

---

## 18. Security Architecture

### 18.1 Defense-in-depth stacks

| Layer | Guard | Fails-closed when |
|---|---|---|
| WAF (Cloudflare / AWS WAF) | Basic OWASP rules, rate limits, geo policy if applicable | External |
| Reverse proxy (Nginx) | TLS 1.2+, HSTS, HTTP/2 | Certificate expiry alerts |
| Laravel middleware | Auth, idempotency, CSRF (browser), RLS binder, staff DB role switch | Missing session |
| Application service | Permission matrix, `trade_allowed`, reason enforcement | Missing reason on reason-mandatory |
| Repositories | Ownership checks (buyer_id/seller_id) | Wrong actor |
| Postgres | RLS policies, DB grants, deferred triggers, append-only triggers, karat CHECK | Cross-customer read, over-privileged role, unbalanced ledger, negative wallet, karat mismatch with non-cancel outcome |

### 18.2 Sensitive data

- ID/passport images: encrypted at application layer with a KMS-held DEK; storage key alone is useless.
- View a document → writes `document_view_log` in the same transaction; a view that can't log fails.
- Signed URLs for reads, short TTL (60s).
- Every image bucket is private; no public access.

### 18.3 Password + OTP

- Argon2id.
- OTP: 6-digit, TTL 5 min, hashed in Redis, single-use.
- Lockout: DB-backed (5/30 for staff; customer TBD — OI-1.1).

### 18.4 Secrets management

- Never in code, never in `.env` committed to git.
- Production: AWS Secrets Manager (or equivalent). Rotation for DB creds.
- Application reads via config at boot; a secret rotation restarts the app.

### 18.5 File storage security

- Buckets private only.
- Server-side encryption at rest (S3 SSE-KMS).
- All uploads virus-scanned (ClamAV in a worker); a failed scan quarantines and alerts Operations.
- File-type whitelist + magic-number check.
- Max file size 15 MB per image, 100 MB per video.

### 18.6 API security

- HTTPS-only, HSTS.
- Sanctum tokens revocable per device.
- Sensitive endpoints require re-auth or elevated (email token for withdrawal).
- Structured logging redacts PII, tokens, and password fields.
- Sentry scrubs sensitive keys before ingest.

### 18.7 Database security

- Role per staff role. App connects as `dahab_app` for customer routes; per-request `SET ROLE dahab_<role>` for staff routes (Part 1 §5.2 mandate).
- PgBouncer in **session** or **transaction** mode: transaction mode is what the RLS `SET LOCAL` design requires (session leaking across requests is fatal here). We must use **transaction pooling with `SET LOCAL`** — do not use session pooling for the customer role.
- No superuser access from app.
- Backups encrypted at rest; point-in-time recovery.
- Daily automated backup + monthly restore drill.

### 18.8 Admin security

- MFA on all staff accounts (TOTP; hardware keys for founders — OI-4.4).
- IP allowlist for admin routes (staff VPN or office IPs; discuss with Ops).
- Founder freezes cascade to all their tokens.

### 18.9 Mobile security

- Certificate pinning (Dio + platform).
- Root/jailbreak detection with graceful degradation (warn, don't block, unless very confident).
- Secure storage for tokens (§17.4).
- Screenshot protection on sensitive screens (Flutter's `FLAG_SECURE` on Android, view-controller override on iOS).

### 18.10 OWASP Top 10 stance

- A01 Broken Access Control → RLS + policies + DB grants.
- A02 Cryptographic Failures → Argon2id, KMS, TLS.
- A03 Injection → Eloquent parameter binding; no raw string concatenation in queries.
- A04 Insecure Design → ledger design and this document are the mitigation.
- A05 Security Misconfiguration → infra-as-code review; secret scanning in CI.
- A06 Vulnerable Components → Dependabot + Renovate on both PHP and JS/Flutter.
- A07 Identification & Auth → Sanctum + OTP + email second-check + founder sublayer.
- A08 Software Integrity → signed releases, provenance in CI.
- A09 Logging & Monitoring → Sentry + audit log + Horizon + Prometheus.
- A10 SSRF → all outbound to Evolve/ETA/FCM via allowlisted egress.

### 18.11 Fraud considerations

- Pattern flag (`flag.pattern_txn_threshold`) for unusual activity.
- Manual review remains the norm through V1; automation is not the answer at this scale.
- Sanctions/PEP screening: not built into MVP. Legal-clinic OI whether required for gold trading in EG at this size.

---

## 19. Notifications

### 19.1 Channels

FCM (push), SMS, Email, In-App. Language from `customer.preferred_lang`.

### 19.2 Events → notifications

| Event | Buyer | Seller | Admin |
|---|---|---|---|
| BuyRequestQueued | in-app, push | in-app, push | — |
| BuyRequestReleased (any reason) | in-app, push | — | — |
| BuyRequestAccepted | in-app, push, email | in-app, push | — |
| OrderCreated | in-app | in-app, push | — |
| ReachBranchDeadlineNearing (T-2h) | — | push (twice) | — |
| InspectionResultRecorded | in-app, push | in-app, push | operations digest |
| WeightAdjustmentPending | in-app, push | in-app | — |
| KaratMismatch | in-app, push | in-app, push | operations alert |
| OrderSettled | in-app, push, email (with tax invoice) | in-app, push, email (with tax invoice) | — |
| PayBalanceReminder (T-2d, T-6h) | push, sms | — | — |
| CollectionCodeIssued | in-app, push, sms | — | — |
| CollectionWindowClosing (T-3d) | push | — | — |
| UncollectedExpired | in-app, push, sms | — | operations alert |
| DepositForfeited | in-app, push, email | in-app, push, email | — |
| SellerReturnCodeIssued | — | in-app, push, sms | — |
| SellerReturnWindowClosing | — | push, sms | — |
| SellerUnclaimed | — | in-app, push | operations alert |
| WithdrawalRequested / Released / Rejected | — | — | Finance |
| WithdrawalPauseOpened | in-app, email | — | — |
| PayoutAccountChanged | in-app, email | — | Finance |
| CustomerSuspended / Reinstated | in-app, email | in-app, email | Founders |
| FounderSensitiveAction | — | — | **both founders, real-time** |
| CapHit / PatternFlag | — | — | **routing = OI-1.3** |

### 19.3 Implementation

- Laravel Notifications; each event → a `Notification` class per party.
- Channel selection consults `notification_preference` (defaults to all channels for critical, in-app+push for standard).
- Templates in DB or blade (recommend DB for admin-editable "edit app text" permission from the matrix).
- Idempotent send via a `send_id` per (event_id, party, channel).

---

## 20. Background Processing

### 20.1 Redis + Laravel Queue + Horizon

- One Redis instance for cache + queue (dev/staging); separate Redis for prod queue if scale demands.
- Horizon config: queues by priority (`critical`, `default`, `low`).
- Workers per queue; autoscaling via Kubernetes/ECS if needed.

### 20.2 Jobs (comprehensive list)

| Job | Trigger | Queue | Retry | Idempotency |
|---|---|---|---|---|
| Seller-reply sweep | Scheduler every 1 min | critical | 3 with backoff | Row-level state check |
| Reach-branch sweep | Scheduler every 5 min | critical | 3 | Row-level |
| Balance-payment sweep | Scheduler every 15 min | critical | 3 | Row-level |
| Seller-return sweep | Scheduler every 1 h | default | 3 | Row-level |
| Collection sweep | Scheduler every 1 h | default | 3 | Row-level |
| Withdrawal-pause expiry | Scheduler every 15 min | default | 3 | Row-level |
| Notify-when-free | Event-driven (listing → live with 0 requests) | default | 3 | notification `send_id` |
| Pattern/cap flag | Event-driven | default | 3 | flag id |
| OTP send | Event-driven | critical | 3 | otp id |
| FCM/SMS/Email send | Event-driven | default | 5 | notification send id |
| Evolve rate poll | Scheduler every 1 min | default | 5 | rate snapshot |
| Rate cache warm | Scheduler every 15 min | low | 2 | key |
| Daily close preparation | Scheduler at 23:00 Africa/Cairo | default | 3 | date |
| Reports refresh | Scheduler nightly | low | 2 | report id |
| Tax invoice ETA filing | Event-driven | critical | many with backoff | ETA idempotency ref |
| Document malware scan | Event-driven on upload | default | 3 | file hash |
| Log rotation / retention | Scheduler daily | low | — | — |

### 20.3 Reliability

- Each job assumes it can be re-executed; the DB-side row state guards correctness.
- Failed jobs land in `failed_jobs`; Horizon dashboard visible to Ops.
- Alerts on failure rate spikes.

### 20.4 System actor

Sweeps run as a dedicated `staff` row (`role='operations'` for now; consider adding `'system'` — see §8.2). All their audit rows and ledger transactions carry this actor.

---

## 21. File & Document Management

### 21.1 Storage

- S3-compatible object storage. AWS S3 in production; MinIO for dev/staging.
- Buckets: `dahab-identity` (encrypted, private), `dahab-listings` (private), `dahab-invoices` (private), `dahab-igi` (private), `dahab-legal` (public — versioned).
- No public buckets except `dahab-legal`.

### 21.2 Access control

- All reads via pre-signed URLs with 60s TTL for private objects.
- Server-side generation of pre-signed URLs authorised by the app's policy layer.
- Public browse listing images use *signed* URLs so the CDN caches without granting bucket-wide public access.

### 21.3 Validation

- Whitelist MIME types (JPG, PNG, WebP, MP4, PDF).
- Magic number check (a `.jpg` must actually be a JPEG).
- Max file size limits enforced client and server.
- All uploads scanned (ClamAV) before the entity that references them is enabled.

### 21.4 Retention

- Identity documents: kept while account active; **image deleted after account closure**, but a "we checked it, and when" record survives for the legally required period (OI-1.5).
- Legal documents: **forever** (customers agreed to that version).
- Ledger, audit, inspection: **forever**.
- Session/device: while token valid + 90 days.

### 21.5 Encryption

- SSE-KMS at rest for all buckets.
- Envelope encryption with per-customer DEK for identity documents (application-layer, key material in AWS KMS).
- TLS in transit everywhere.

---

## 22. Audit Logging

*Schema §13 provides the target. This section defines the writer contract.*

### 22.1 What is logged

Everything in admin-roles §8 — the full list. In summary: every wallet change, every price/rate change, every state change on a customer or a listing, every ID view, every promo change, every legal doc publish, every category control, every case file, every founder-security action, every login attempt.

### 22.2 Contract

- Always in-transaction with the state change.
- Actor: staff or customer (CHECK).
- `before_json` / `after_json` for updates; either can be null for pure creates or deletes.
- Reason required when the action is reason-mandatory; enforced by the authorize step (422 otherwise).
- Append-only; not editable by any role including founders.

### 22.3 Reads

- Founders see everything.
- Others see own-actions only.
- No delete, no purge, no truncate.

### 22.4 Retention

Currently: forever. Reduce to statutory retention only if legal clinic advises (OI-1.5).

---

## 23. Infrastructure

### 23.1 Environments

- **Development:** Docker Compose (Laravel, Postgres, Redis, MinIO, Mailpit).
- **Staging:** cloud mirror of production, seeded with anonymised data; feature-branch previews possible.
- **Production:** high-availability containerised deploy in-region.

### 23.2 Recommended production layout

For MVP scale, the cheapest right answer is a **Hetzner cloud + Coolify** or **AWS ECS Fargate** deployment. For long-term maturity, ECS Fargate + RDS Postgres + ElastiCache Redis + S3 + Route 53 + CloudFront (or the AWS Egypt / Bahrain / KSA region equivalents).

Concrete components:

| Component | Recommendation |
|---|---|
| App (PHP-FPM + Nginx or Octane) | 2+ instances behind ALB |
| Horizon workers | 2+ dedicated instances, autoscale on queue depth |
| Scheduler | 1 leader instance (run `schedule:run` every minute) |
| PostgreSQL | Managed (RDS) or dedicated VM with WAL archiving; **PgBouncer in transaction mode** in front |
| Redis | Managed (ElastiCache) or dedicated |
| Object storage | S3 or S3-compatible with lifecycle policies |
| CDN | CloudFront for the public listing images and the mobile app store static |
| WAF | Cloudflare or AWS WAF |
| DNS + certs | Route 53 + ACM (or Let's Encrypt) |
| Secrets | AWS Secrets Manager |
| Backups | RDS automated + weekly logical dump to S3, encrypted |

### 23.3 Data residency

Egypt-relevant regulation may require data to stay in-region. Confirm with the legal clinic; if strict residency required, evaluate a Bahrain/UAE region or an in-country provider. This is a **before-go-live** decision (§30 OI-4.5).

### 23.4 High availability targets

- App tier: 99.9% (single-region multi-AZ).
- Database: RPO ≤ 15 min, RTO ≤ 1 h (managed PITR).
- Horizon: at-least-once delivery of jobs; DB-side idempotency guards correctness.
- Full DR test at least every 6 months.

### 23.5 Config management

- Application config via env + Secrets Manager.
- Infrastructure-as-code: Terraform (preferred) or CloudFormation.
- Every environment repro from the same TF plan.

---

## 24. CI/CD

### 24.1 Pipeline (GitHub Actions)

Per push and per PR:

1. **Lint & format** — PHP-CS-Fixer, PHPStan level 8+, ESLint, Prettier, Dart analyzer.
2. **Static analysis custom rules** — no `ledger_posting` writes outside `MoneyService`; no `DB::raw` with user input; no `env()` outside config; RLS binder middleware present on all customer routes.
3. **Unit tests** — pure domain logic.
4. **Feature tests** — HTTP tests hitting the whole stack against a real Postgres in Docker.
5. **Database migration test** — apply all migrations to a fresh DB; verify `ledger_global_zero = 0`.
6. **Security** — `composer audit`, `npm audit`, `dart pub outdated`, secret scan (gitleaks).
7. **OpenAPI validation** — response conformance smoke.
8. **Build artifact** — Docker image, tagged.

On merge to main:

9. **Deploy staging** — automatic.
10. **Smoke tests** — synthetic transaction on staging (register → verify → list → buy → inspect → settle) using a test data set.

On tag `v*`:

11. **Deploy production** — manual approval + audit trail.
12. **Post-deploy checks** — `solvency_check` sanity, error rate baseline, alert if anomaly.

### 24.2 Rollback

- Immutable images; a rollback is a re-deploy of the previous tag.
- Migrations are forward-only; rollback of a migration is a *new* migration (never `down`) once released.
- A hotfix branch model for emergencies.

### 24.3 Feature branch previews

- Optional per-PR ephemeral env in staging.
- Data reset per preview.

---

## 25. Testing Strategy

### 25.1 Priorities

Not 100% coverage. Coverage of the **money paths**, the **state machines**, and the **auth gates** is critical; controllers and DTOs are less so.

### 25.2 What to test where

| Area | Test kind | Framework |
|---|---|---|
| Money math (spread, commission, VAT, rounding) | Unit + property-based | PHPUnit / Pest |
| Ledger transaction balance | Unit + integration against Postgres | PHPUnit |
| Buy request queue concurrency | Integration; N-thread simulation | PHPUnit + `pcntl_fork` or a queue-based simulation |
| Order state transitions | Integration | PHPUnit |
| Inspection karat rule | Integration; assert CHECK violation is thrown | PHPUnit |
| First-sale advance reconciliation | Integration with full end-to-end scenario | PHPUnit |
| Deposit forfeiture 50/50 | Integration | PHPUnit |
| Refund from escrow | Integration | PHPUnit |
| Auth flows (register, login, OTP, refresh, reset) | Feature | PHPUnit |
| Permission matrix per endpoint | Feature | PHPUnit + dataset per (role, action) |
| RLS isolation | Integration against Postgres | PHPUnit; asserts cross-customer queries return zero rows |
| Idempotency middleware | Feature | PHPUnit |
| Sweep jobs | Feature | PHPUnit |
| API contract | Contract | Dredd or OpenAPI conformance |
| Admin UI critical paths | E2E | Playwright |
| Mobile critical paths | Widget + integration | flutter_test + integration_test |

### 25.3 Test data

- A `TestFactories` collection that builds valid domain graphs (Customer → Listing → BuyRequest → Order → Inspection → Settlement).
- Money assertions use exact string comparisons.
- Every worked example in Part 3 §3 becomes a test.

### 25.4 Regression on the invariants

CI verifies **on every run**:

- `SELECT COALESCE(SUM(amount),0) FROM ledger_posting` = 0.
- No `cust_available` or `cust_held` balance is negative.
- No inspection row has `karat_mismatch = true` and `outcome != 'karat_cancel'`.
- No listing has `active_queue_count < 0`.

---

## 26. Observability

### 26.1 Logs

- Structured JSON logs (Monolog).
- PII scrubbed at the formatter.
- Ship to Loki (Grafana) or CloudWatch.
- Retention 90 days hot, 1 year cold.

### 26.2 Metrics

- Prometheus scrapes Laravel exporter + Horizon exporter + Postgres exporter + Redis exporter.
- Business metrics: settlements/day, refunds/day, karat cancels/day, first-sale advances outstanding, average time in queue, average time from acceptance to inspection to settlement.
- **Solvency headroom** as a first-class alerting metric — page if < some floor.

### 26.3 Error tracking

- Sentry for backend and mobile.
- Slack integration for on-call.

### 26.4 Audit is not observability

The audit log is a compliance artefact, not a debugging tool. Ops use logs and metrics; investigators use the audit log.

### 26.5 Dashboards

- Operator dashboard (default landing): solvency, pending reviews, active queues, deadlines closing today, active founder alerts.
- Finance dashboard: withdrawals in review, top-ups pending, daily close status, commission YTD.
- Engineering dashboard: queue depths, error rates, DB latencies, Horizon health.

---

## 27. Team Structure

### 27.1 Roles and sizing (for 20–24 week V1)

| Role | Count | FT/PT | Notes |
|---|---|---|---|
| CTO / Principal Architect | 1 | FT | Owns the technical roadmap, unblocks decisions |
| Engineering Manager / Tech Lead | 1 | FT | Runs sprints, code review discipline |
| Senior Laravel Engineer | 2 | FT | One anchors Ledger + Settlement; one anchors Orders + Inspection |
| Laravel Engineer | 1 | FT | Identity, Marketplace, Admin endpoints |
| Senior Vue Engineer (Admin) | 1 | FT | Owns the admin dashboard |
| Vue Engineer | 1 | FT (or PT after Phase 7) | Screens support |
| Senior Flutter Engineer | 1 | FT | Owns mobile architecture |
| Flutter Engineer | 1 | FT | Screens support |
| UI/UX Designer | 1 | FT for Phase 0–3, PT after | Owns design system and screens |
| DevOps / SRE | 1 | PT (0.5) → FT for launch weeks | Owns infra, CI/CD, observability, on-call rotation |
| QA Engineer | 1 | FT from Phase 3 | Financial subsystem tests, E2E, regression |
| Product Manager | 1 | FT | Requirements, stakeholder communication |
| Legal counsel (external) | 1 | Retainer | Clinic decisions in §30 |

**Total FT-equivalent:** ~10 engineers + 1 PM + external legal.

### 27.2 Shared roles

- QA can be shared with a founder-driven review on days without a load.
- DevOps can be a fractional external hire until Phase 8.

### 27.3 Communication cadence

- Daily 15-min standup by pod (backend, frontend, mobile).
- Weekly all-hands (30 min): milestone status.
- Bi-weekly stakeholder demo.
- Async status in Slack per phase.

---

## 28. Development Roadmap (Milestones)

**Working assumption:** ~10 engineers, parallel pods, 20–24 weeks to Production V1, MVP at week 18.

### Phase 0 — Discovery, decisions, environment (weeks 1–2)

**Objective:** unblock every OI that gates code; stand up dev/staging infra; freeze the decision register.

- CTO, PM, EM.
- Decide OI-1.1 (customer lockout), OI-1.3 (alert routing), OI-4.1 (`system` staff role vs `operations`), OI-4.5 (data residency), OI-2.1 disposition (already resolved manual).
- Legal clinic engagement kicked off for OI-1.5, OI-3.5, OI-2.4, terms-draft finalisation.
- Set up Git, CI/CD baseline, Docker Compose dev env, staging cluster.
- Sign off on OpenAPI as the contract source.
- Deliverable: this blueprint accepted; environment READY; open items assigned to owners.

### Phase 1 — Backend foundation (weeks 3–4)

**Objective:** the skeleton every module builds on.

- Laravel app skeleton with the Modules/ layout.
- Sanctum wired.
- DB migrations wrap SQL 01–05.
- RLS binder middleware, staff role switch middleware, Idempotency middleware.
- `MoneyService` skeleton (all methods stubbed with domain events).
- `AuditLogger`, `WorkingHoursResolver`, `PermissionMatrix`.
- Baseline CI (lint, PHPStan, PHPUnit, migration check, `ledger_global_zero` in CI).
- Deliverable: a green build with 0 endpoints but every cross-cutting concern in place.

### Phase 2 — Identity & authentication (weeks 4–6)

**Objective:** users can sign in, verify, and be locked down.

- Customer register, login, OTP, refresh, logout, password reset.
- Staff login, session timeout, failed-attempt lock, founder device approval, founder freeze/unfreeze.
- ID/passport upload + admin review.
- Suspension / reinstate.
- Deliverable: `/me`, `/auth/*`, `/admin/staff`, `/admin/customers/*` operational; the RLS + role-per-staff-role wired.

### Phase 3 — Marketplace & buy requests (weeks 5–9, parallel with Phase 2 from week 6)

**Objective:** the queue works. Multiple buyers can race; nobody gets double-billed.

- Reference tables seeded (karat, piece_type, branch, branch_hours, branch_closure, settings).
- Listings CRUD, admin listing review, media uploads.
- Public marketplace read.
- Buy request join / withdraw / seller-view queue.
- Seller accept (with atomic release-all).
- Concurrency tests (heavy).
- Deliverable: end-to-end listing → queue → accept, verified under concurrent load.

### Phase 4 — Orders, inspection, pay-balance (weeks 8–12)

**Objective:** an accepted order can reach settlement.

- Order lifecycle, seller cancel, admin branch change / deadline extension.
- IGI receive, inspection result submission, settlement decision, karat rule.
- **The full settlement math and ledger set** (the peak-risk item — QA-anchored).
- First-sale advance path (advance at IGI receive; reconciliation at pay-balance).
- Tax invoice issuance (ETA hook stub; real integration in Phase 9).
- Deliverable: a full clean pass end-to-end from list to `ready_to_collect`.

### Phase 5 — Wallet, withdrawals, admin money (weeks 10–14)

**Objective:** customers can top-up, request withdrawals; Finance can review and release.

- Top-up flow (payment gateway integration — MVP note: pick the gateway in Phase 0; likely Paymob or Fawry).
- Payout account CRUD + verification workflow.
- Withdrawal request → email second-check → admin review → release / reject / cancel.
- Withdrawal pause on account change.
- Admin compensation, refund, direct wallet adjustment, bank movement, daily close.
- Deliverable: customer money in and out end-to-end, plus solvency-headline dashboard.

### Phase 6 — Deadlines, disputes, market makers (weeks 12–15)

**Objective:** sweeps and edge cases.

- All sweep jobs (§11) live in Horizon, scheduled.
- Return-to-seller (buyer no-pay), seller_return codes, seller collection.
- Uncollected-paid state and manual disposition endpoints.
- Dispute lifecycle.
- Market maker approval + promo code enforcement.
- Notify-when-free.
- Deliverable: every corner of the state machines exercised in staging.

### Phase 7 — Admin dashboard (weeks 8–16, parallel from week 8)

**Objective:** operators can run the platform.

- All admin modules per §15.
- Wallet-touching screens hidden from COO by design.
- Founder security screens.
- Reports (daily close, solvency, commission summary, orders by state).
- Audit log viewer.
- Deliverable: an operations-usable dashboard tested in staging.

### Phase 8 — Mobile app (weeks 10–20, parallel from week 10)

**Objective:** buyer + seller flows on both platforms.

- Flutter shell, Riverpod, Dio, secure storage, FCM.
- Auth flow (including OTP).
- Marketplace browse + filters + search + listing detail + wishlist.
- Buy request join + queue view + notify-when-free.
- Sell flow (create listing).
- Order screens (buyer + seller views).
- Inspection status; adjustment approval.
- Pay balance; collection code; proxy authorisation.
- Wallet screens (with email withdrawal second-check).
- Notifications inbox and preferences.
- Localisation (ar, en) and RTL.
- Deliverable: apps on internal Play/App Store tracks.

### Phase 9 — Integrations (weeks 16–20)

**Objective:** external systems wired.

- Evolve gold rate feed (real; not stub).
- Rapaport weekly upload.
- IGI contract-specifics (cert costs — OI-3.2).
- ETA e-invoicing at pay-balance.
- Payment gateway top-up settlement.
- Deliverable: real gold rate, real tax invoices to ETA, real payments.

### Phase 10 — UAT, security review, launch (weeks 20–24)

**Objective:** production-ready with sign-off.

- Full E2E in staging with representative volumes.
- External security review (penetration test).
- Legal-clinic sign-off on terms, deposit forfeiture, uncollected-paid framing, proxy authorisation.
- Founder acceptance testing.
- Data residency + backup DR drill.
- Soft launch — CEO-invited sellers first.
- Deliverable: **Production V1 live.**

### 28.1 Timeline diagram (weeks 1–24)

```
Week  1  2  3  4  5  6  7  8  9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24
Ph0  [==]
Ph1     [====]
Ph2         [========]
Ph3            [==============]
Ph4                  [==============]
Ph5                        [==============]
Ph6                              [==========]
Ph7                     [========================]
Ph8                           [========================]
Ph9                                          [==========]
Ph10                                                    [==========]
                                                                MVP↑    V1↑
                                                              week 18  week 24
```

Parallelism is real: mobile (Phase 8) starts before deadlines (Phase 6) is done, because those flows depend on Phases 4–5 that are complete by then.

### 28.2 Acceptance criteria per phase

Each phase has: green CI, feature tests passing, staging smoke passing, PM sign-off, security review of any new attack surface. Phase 4, 5, and 9 additionally require CTO sign-off on ledger correctness.

---

## 29. Technical Backlog (Epics → Features)

*A digestible high-level view. Each row expands into stories in the tracker.*

### EPIC-01 · Cross-cutting foundations (P0)

- F-01.1 Modules/ scaffold; F-01.2 RLS binder; F-01.3 Staff role switch; F-01.4 Idempotency middleware; F-01.5 AuditLogger; F-01.6 WorkingHoursResolver; F-01.7 PermissionMatrix service; F-01.8 MoneyService skeleton; F-01.9 CI + `ledger_global_zero` check.

### EPIC-02 · Identity (P0)

- F-02.1 Register+login (customer, staff); F-02.2 OTP on new device; F-02.3 Password reset; F-02.4 ID upload + review; F-02.5 Suspension; F-02.6 Founder device approval; F-02.7 Founder freeze/unfreeze.

### EPIC-03 · Marketplace (P0)

- F-03.1 Reference seed; F-03.2 Listing draft + submit + admin review; F-03.3 Media upload (pre-signed); F-03.4 Public browse; F-03.5 Withdraw listing (with queue release).

### EPIC-04 · Buy request queue (P0)

- F-04.1 Join queue (with deposit hold); F-04.2 Leave queue; F-04.3 Seller view queue; F-04.4 Accept head (atomic release-all); F-04.5 Concurrency test suite.

### EPIC-05 · Orders (P0)

- F-05.1 Order create at accept; F-05.2 Reach-branch deadline computation; F-05.3 Seller cancel; F-05.4 Admin change-branch; F-05.5 Admin extend deadline.

### EPIC-06 · Inspection (P0)

- F-06.1 IGI receive; F-06.2 Inspection result submission (immutable, outcome derivation); F-06.3 Karat rule structural enforcement; F-06.4 Settlement decision (buyer approve/decline); F-06.5 Corrections via supersedes.

### EPIC-07 · Settlement + wallet (P0)

- F-07.1 Pay balance (escrow pass-through, full ledger set); F-07.2 First-sale advance (at IGI receive) + reconciliation at pay-balance; F-07.3 Deposit forfeiture 50/50 on buyer no-pay; F-07.4 Refund from escrow (post-settlement); F-07.5 Withdrawal request/release/reject/cancel; F-07.6 Withdrawal pause on account change; F-07.7 Compensation with Finance caps; F-07.8 Direct wallet adjustment CEO-only; F-07.9 Bank movement + daily close; F-07.10 Tax invoice issuance.

### EPIC-08 · Deadlines / sweeps (P0)

- F-08.1 Seller-reply sweep; F-08.2 Reach-branch sweep; F-08.3 Balance-payment sweep (with piece return to seller); F-08.4 Seller-return sweep; F-08.5 Collection sweep; F-08.6 Withdrawal-pause expiry; F-08.7 Notify-when-free; F-08.8 Pattern/cap flag.

### EPIC-09 · Admin dashboard (P0)

- F-09.1 Auth + role guards; F-09.2 Listings review; F-09.3 Orders operations; F-09.4 Wallets (CEO+Finance only); F-09.5 Withdrawals review/release; F-09.6 Bank movements + daily close; F-09.7 Gold price manual + corrections; F-09.8 Rate/karat/commission settings; F-09.9 Category controls; F-09.10 Market makers + promo codes; F-09.11 Disputes; F-09.12 Case files; F-09.13 Audit log viewer; F-09.14 Legal docs publisher; F-09.15 Notifications templates.

### EPIC-10 · Mobile app (P0)

- F-10.1 Auth (login/OTP/reset); F-10.2 Verification (ID upload); F-10.3 Marketplace browse; F-10.4 Buy request (with queue position live); F-10.5 Seller create listing; F-10.6 Seller queue view + accept; F-10.7 Orders (buyer + seller); F-10.8 Inspection status + adjustment approval; F-10.9 Pay balance; F-10.10 Collection code + proxy; F-10.11 Wallet + payout accounts + withdrawal (with email); F-10.12 Notifications + FCM; F-10.13 ar/en + RTL.

### EPIC-11 · Notifications (P0)

- F-11.1 NotificationRouter; F-11.2 FCM channel; F-11.3 SMS channel; F-11.4 Email channel; F-11.5 In-app inbox; F-11.6 Preferences; F-11.7 Templates per event.

### EPIC-12 · Integrations (P1)

- F-12.1 Evolve rate feed; F-12.2 Manual price fallback; F-12.3 Rapaport weekly matrix; F-12.4 IGI cert cost handling (contract-driven); F-12.5 ETA e-invoicing; F-12.6 Payment gateway top-up.

### EPIC-13 · Observability (P1)

- F-13.1 Structured logging; F-13.2 Sentry; F-13.3 Metrics exporters; F-13.4 Grafana dashboards; F-13.5 Solvency alert.

### EPIC-14 · Security hardening (P1)

- F-14.1 MFA for staff; F-14.2 Cert pinning mobile; F-14.3 Doc encryption at rest; F-14.4 Pen-test remediation; F-14.5 Rate limiting; F-14.6 IP allowlist admin.

### EPIC-15 · Reports (P2)

- F-15.1 Sales report; F-15.2 Commission YTD; F-15.3 VAT report; F-15.4 Seller stats; F-15.5 Inspection outcomes.

---

## 30. Risks (Top 20)

| # | Risk | Prob | Impact | Sev | Mitigation | Owner |
|---|---|---|---|---|---|---|
| R1 | Unbalanced ledger (constraint violation missed in tests) | Low | Catastrophic | High | Deferred DB trigger + CI check + PHPStan rule that all posts go through MoneyService | Tech Lead |
| R2 | Wallet goes negative under concurrent load | Low | Catastrophic | High | Deferred CHECK trigger; feature test with concurrent load; row-lock in service | Tech Lead |
| R3 | COO can view a wallet due to code bug | Low | High | High | DB grant is the backstop; not code-based | CTO |
| R4 | Duplicate `pay-balance` charges two deposits | Low | High | High | Idempotency key required; state precondition inside txn | Tech Lead |
| R5 | Race on queue accept (two orders for one head) | Low | High | High | `FOR UPDATE` on listing_queue_seq; state transition trigger; unique index on `buy_request` | Tech Lead |
| R6 | Founder account compromise | Low | Catastrophic | High | Device approval + freeze; MFA + hardware key; real-time alerts (OI-1.3 pending) | CTO |
| R7 | ID document leak | Low | Catastrophic | High | KMS envelope encryption; short-TTL signed URLs; document_view_log; RBAC + RLS | Security |
| R8 | Evolve outage → wrong price | Med | Med | Med | Manual price with 10% confirm; banner; auto-recover; snapshots | Tech Lead |
| R9 | ETA e-invoicing outage → invoices not filed | Med | Med | Med | Retry with backoff; local queue survives; SLA with ETA integrator | DevOps |
| R10 | IGI cert cost handling changes contractually | Med | Low | Low | Ledger treatment behind a setting; contract-driven (OI-3.2) | PM |
| R11 | Karat rule loosened by "helpful" developer | Low | Catastrophic | High | Structural CHECK constraint prevents it | Tech Lead |
| R12 | Manual disposition logic gets automated by shortcut | Med | High | Med | Locked decisions #3 and #4 embedded in code review checklist | Tech Lead |
| R13 | Data residency non-compliance | Med | High | High | Confirm with legal clinic; pick region accordingly (OI-4.5) | Legal + CTO |
| R14 | Payment gateway integration blocks MVP | Med | High | Med | Start Phase 0 discussion; support 2 gateways (Paymob, Fawry) | PM |
| R15 | Deadline sweeps miss firing (Horizon down) | Low | Med | Med | Watchdog job checks last run; solvency alert catches drift | DevOps |
| R16 | RLS misconfigured → cross-customer data | Low | Catastrophic | High | Feature tests assert isolation; RLS binder middleware; separate DB roles | Tech Lead |
| R17 | Sentry / logs expose secrets | Med | Med | Med | Scrubbers in Monolog + Sentry config; secret scan in CI | DevOps |
| R18 | Market maker programme abused | Med | Med | Med | Aged-listing gate; admin approval per piece; monthly cap; audit | Ops |
| R19 | Buyer sends someone to collect who is not authorised | Med | Med | Med | Wording (legal-clinic OI); ID check at counter; declaration accepted | Ops + Legal |
| R20 | Insurance for pieces in custody insufficient / unclear | Med | High | Med | IGI contract review (OI-3.2 / open-Qs §1); insurance rider | PM + Legal |

---

## 31. MVP / V1 / V2 Scope

### 31.1 MVP (week 18)

**Definition:** the minimum complete transaction, both roles, both platforms, all financial invariants, all deadlines.

Included:
- Customer register/login/OTP/ID verification.
- Marketplace browse.
- List a piece + admin review + queue join + accept.
- Order + inspection + settlement + collection (physical).
- Wallet, top-up, withdrawal.
- All deadline sweeps.
- Deposit forfeiture; refund; first-sale advance.
- Admin dashboard for Operations, Finance, Verification, IGI, Founders.
- Mobile app (buyer + seller flows).
- Notifications (in-app + push + email; SMS if OTP-only).

Excluded from MVP:
- Rapaport weekly upload UI (a stub file loader is fine; full integration in V1).
- Market maker programme (V1).
- Reports beyond daily close and solvency.
- Advanced fraud detection.

### 31.2 V1 (week 24)

- Everything in MVP hardened.
- ETA e-invoicing real integration.
- Market maker programme end-to-end.
- Rapaport weekly upload UI + suggestion display.
- Reports suite.
- Legal-clinic-signed-off terms and disclaimers in-app.
- Founder MFA + hardware keys.
- Full security review remediation.
- Data residency confirmed and applied.

### 31.3 V2 (post-launch, quarter after V1)

- Automated fraud/AML detection tuned to real data.
- Additional payment gateways.
- International expansion (Gulf) if warranted.
- Sanctions/PEP screening if legally required by then.
- Insurance product for buyers if the business decides.
- Multi-language (add French, Turkish, etc.).

---

## 32. CTO Decision Register

| # | Decision | Options | Chosen | Reason | Trade-offs |
|---|---|---|---|---|---|
| D-01 | Backend framework | Laravel, Symfony, Django, Node/Nest, Go | **Laravel 11** | Hiring pool in EG; batteries-included; Horizon; hits the invariants directly | Some Laravel idioms (Eloquent) needed careful use for aggregates |
| D-02 | Database | PostgreSQL, MySQL, Aurora | **PostgreSQL 15+** | Schema uses PG-only features (RLS, deferred triggers, partial UI, JSONB, citext) — impossible to swap | Locked-in |
| D-03 | Architecture | Modular Monolith, Microservices, Hexagonal | **Modular Monolith with domain events** | Settlement atomicity; small team; extraction seam preserved | Cannot scale a single module independently until extracted |
| D-04 | Auth | Sanctum, Passport, custom JWT | **Sanctum** | Per-device tokens fit the "new device → OTP" flow; simple; revocable | No OAuth2 for 3rd parties yet — add Passport later if needed |
| D-05 | Money service | Repository-per-model, single service, service-per-event-kind | **Single MoneyService, one method per event_kind** | One writer, one place to audit; PHPStan rule enforces | Method count grows with event kinds |
| D-06 | Ledger design | Mutable balance, single-entry, double-entry | **Double-entry, derived balance** | Already schema-enforced; auditable; industry standard | Slightly more code per transaction; worth it |
| D-07 | Escrow | Bypass at settlement, pass-through, permanent hold | **Instantaneous pass-through** | Locked decision; single refund source | One extra ledger leg per settlement |
| D-08 | Idempotency | Client hash, server key, no formal handling | **Server key + DB store + Redis cache** | Money-safety non-negotiable | Requires client discipline; middleware handles it |
| D-09 | State machines | Code-only, DB tables, workflow lib | **DB tables + code guard + transition trigger** | Data, not code; reviewed changes; already schema-enforced | Tables are the source of truth; workflow library adds no value here |
| D-10 | Queue | Redis, DB, SQS | **Redis + Horizon** | Ecosystem fit; low ops; scaling clear | Redis is now critical infra |
| D-11 | Frontend admin | Vue+Vuetify, React, Angular, Blade | **Vue 3 + Vuetify 3** | TS + Pinia + Vuetify's data-heavy components | Team must have Vue skills |
| D-12 | Mobile | Flutter, React Native, native | **Flutter** | Small team, one codebase, mature | RN pool bigger in some markets — decided anyway for consistency |
| D-13 | Mobile state | Riverpod, Bloc, GetX, Provider | **Riverpod** | Best testability; ceremony minimal | Team learns Riverpod idioms |
| D-14 | Object storage | S3, GCS, Azure Blob, local | **S3-compatible** | Standard; MinIO for dev | Cost tuning at scale |
| D-15 | Push | FCM, OneSignal, native APNs+FCM | **FCM (both platforms)** | Cheap; direct in Flutter | APNs indirection via FCM adds a hop — acceptable |
| D-16 | Rate limiter store | Redis, DB, memory | **Redis for API; DB for lockouts** | Speed + survivability | Two systems to keep in sync — thin |
| D-17 | Search | Postgres tsvector, Meilisearch, ES | **Postgres tsvector (MVP)** | Simplicity; enough for expected volume | Revisit at 1M+ listings |
| D-18 | i18n | i18next-like server, framework-native | **Vue-i18n + Flutter ARB + `preferred_lang` for API selection** | Standard; low ceremony | — |
| D-19 | CI | GitHub Actions, GitLab, Jenkins | **GitHub Actions** | Ecosystem; free minutes for open branches | Some proprietary matrix hacks needed |
| D-20 | Infra style | ECS Fargate, K8s (EKS), Hetzner+Coolify, VMs | **ECS Fargate for prod (recommendation); Hetzner+Coolify a cheaper option** | Managed ops burden minimised | Cost model differs; pick per budget in Phase 0 |
| D-21 | PgBouncer mode | Session, transaction, statement | **Transaction pooling** (essential for `SET LOCAL` per request) | Required by RLS design | Any long-lived session state must move elsewhere |
| D-22 | Localisation of admin | ar+en, en-only | **ar+en with RTL** | User base | Testing overhead in RTL |
| D-23 | System actor for sweeps | Add enum `system`, reuse `operations`, per-job | **Decision open — Phase 0** (recommend adding `system`) | Cleaner audit; enum change is a schema migration | Migration coordination |
| D-24 | Payment gateway | Paymob, Fawry, both | **Both, MVP one first (Paymob)** | Coverage EG-wide; redundancy | Two integrations, but shared abstraction |

---

## 33. Open Questions (Consolidated, Prioritised)

**BLOCKER** — must decide before development can proceed on the touched module.

| ID | Question | Blocks | Owner |
|---|---|---|---|
| OI-1.1 | Customer failed-sign-in lockout threshold | Auth (Phase 2) | CTO + Ops |
| OI-1.3 / OI-2.2 / OI-3.4 | Cap/pattern alert routing (channel + recipient) | Notifications (Phase 6, 11) | PM |
| OI-4.1 | `system` staff role vs re-use of `operations` for sweeps | Phase 1 (schema) | CTO |
| OI-4.5 | Data residency requirement | Phase 0 infra | Legal + CTO |
| OI-1.2 | Founder account recovery path | Phase 2 hardening | CTO + Legal |
| Payment gateway choice | Paymob / Fawry / both, MVP first | Phase 5 wallet | PM |
| Payload of tax invoice for ETA | ETA schema alignment | Phase 4 stub / Phase 9 real | Finance + PM |

**HIGH** — must decide before go-live.

| ID | Question | Owner |
|---|---|---|
| OI-3.5 | Uncollected-paid legal framing ("not our liability" wording) | Legal |
| OI-3.3 | First-sale advance vs forfeited-deposit comp (does seller still get 50%? default yes) | Legal + CTO |
| OI-3.2 | Diamond certificate cost ledger treatment (IGI contract-driven) | PM + Legal |
| OI-1.5 | Audit log retention (statutory vs operational) | Legal |
| OI-4.2 | Does the seller "decline this specific request" endpoint exist, or only "accept another"? | PM |
| OI-4.3 | Staff MFA — TOTP everywhere, hardware key for founders? | CTO |
| Proxy authorisation wording | Legal-clinic drafting | Legal |
| Deposit forfeiture framing (agreed compensation, not penalty) | Legal |
| Ownership declaration + stolen goods clause | Legal |
| IGI insurance rider limits | PM + Legal |
| Evolve name/logo usage terms | Partnership + Legal |
| Rapaport commercial licence | Partnership + Legal |

**MEDIUM** — can start development around; decide before launch.

| ID | Question | Owner |
|---|---|---|
| OI-3.1 | Seller-return / collect windows: calendar vs working (default calendar) | PM |
| Market maker programme scaling controls | PM |
| Sanctions/PEP screening for gold trading in EG | Legal |
| Formal AML policy + compliance officer | Legal |
| Residence permit alongside passport for foreign residents (current: no) | Legal |

**LOW** — post-launch decisions.

- Early-payout financing product (currently: no).
- Additional payment gateways beyond MVP.
- International expansion.

---

## 34. Final CTO Report — Recommended Next Steps

### 34.1 In this week

1. **Read this blueprint end-to-end** with the founder team; capture disagreements as tracked items.
2. Accept or amend the technology stack (§4) and the phase plan (§28).
3. Assign owners to every BLOCKER open item (§33).
4. Kick off the legal clinic on the HIGH items.
5. Sign contracts (or NDAs) with Evolve, IGI, Rapaport, ETA integrator, payment gateways (Paymob and Fawry both).

### 34.2 In weeks 2–4

6. Hire the team per §27 (start with Tech Lead + Senior Laravel + Designer if not yet on board).
7. Complete Phase 0 (dev env, CI/CD baseline, staging cluster, OpenAPI source-of-truth).
8. Freeze the decision register (§32) into the repo as `docs/decisions/`.
9. Have a walkthrough of the schema with the senior Laravel engineers — schema-first learning matters.

### 34.3 In months 2–3

10. Execute Phases 1–4 with heavy QA focus on the money paths.
11. Weekly demos of state-machine progress to the founders.
12. Mid-Phase-4 external audit of the ledger and settlement flow (a 2–3 day engagement with a fintech-savvy reviewer).

### 34.4 In months 4–6

13. Complete Phases 5–10 in parallel.
14. External security review pre-launch.
15. UAT with founder-invited real sellers on a staging that mirrors production.
16. Soft launch behind CEO invites; monitor solvency headline and Sentry closely for the first 30 days.

### 34.5 Non-negotiable engineering principles for the team

1. **Financial correctness is a P0 that never yields to schedule pressure.** A slipping deadline is a schedule problem; a wrong ledger is a company-ending problem.
2. **The DB is the source of truth for invariants.** If it needs to be true after the transaction, put a constraint on it. App code enforces workflow, DB enforces truth.
3. **The audit log is sacred.** Never turn off audit writes for performance. Optimise the schema; do not remove the log.
4. **`MoneyService` is the only writer of postings.** Anything else is a bug.
5. **Every state change is guarded by a transition table.** New transitions get reviewed rows, not new `if`s.
6. **Read the setting live, in the transaction.** Never cache rates across a request.
7. **RLS is not optional.** Every customer route sets `app.current_customer_id` inside the transaction.
8. **When in doubt about a legal wording, add a hook and flag it.** Do not invent the wording in code.
9. **Locked decisions are locked.** #3 (buyer no-pay: piece to seller + 50% comp), #4 (uncollected-paid: manual disposition), #5 (IGI weight is the settlement basis), and the karat rule are non-negotiable.
10. **The solvency headline is a P0 alert.** If `bank - owed_to_customers < 0`, page.

### 34.6 Closing note

The documentation set delivered to me is the highest-quality pre-build handoff I have received. The team has already resolved most of the traps a marketplace-with-escrow can fall into — mutable balances, non-atomic settlement, ambiguous karat handling, silent state transitions, shared logins, hidden penalties. My blueprint above adds the Laravel/mobile/admin/infra layers, the sequencing, and the operational plumbing, but the underlying design is sound. If the team holds to the ten principles above, this ships.

---

*End of blueprint. Prepared under the "produce a technical blueprint, do not write code" instruction; every implementation decision above is grounded in the provided documents and this section's rationale. Where I could not resolve a contradiction from the docs alone, I have flagged it in §30/§33 rather than silently choosing.*
