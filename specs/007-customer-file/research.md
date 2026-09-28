# Research: Customer File v1

Decisions taken while planning. Product decisions are in [spec.md](./spec.md) Clarifications.

## R1 — The file is the existing details endpoint, extended

- **Decision**: extend `GET /dashboard/customers/{customer}` (non-breaking: optional fields only). It gains:
  - `documents[]` — every identity document, newest first, same shape as `latest_document`;
  - `suspension` — `{ reason, note, suspended_by: {id, full_name}, suspended_at, status_before }` or `null` (staff refs use the existing `DashboardStaffRef` shape);
  - `preferred_lang` and `joined_at`.

  `latest_document` stays for the Users page. The single `auth.customer.verification_details_viewed` audit entry stays the one record per opening (FR-003).
- **Rationale**: it already loads every document and is already audited. A second endpoint would either write a second audit entry for the same opening, or need a flag saying which page opened it.
- **Alternatives**: a new `/file` endpoint with its own audit kind — rejected, because it duplicates the audited read.

## R2 — Idempotency: a shared layer, built now (user decision 2026-09-28)

Part 2 requires an `Idempotency-Key` on every state-creating POST. The codebase has none, and the ledger needs one next.

- **Table**: `idempotency_key`, a PostgreSQL port of the MySQL draft (`00_schema_mysql.sql` §20), added to `05_schema_security.sql` and `00_schema_full.sql` first (Constitution III).
- **Middleware**: `idempotent`, on routes that opt in. It does the following:
  1. **Header**: requires the `Idempotency-Key` header, a UUID. Missing or malformed → `400 idempotency_key_required`.
  2. **Scope**: a key is scoped to (actor kind, actor id, route name).
  3. **Request hash**: SHA-256 over the canonical JSON body plus the route parameters.
  4. **Start**: inserts an `in_flight` row in its own short transaction before the controller runs.
  5. **Same key, different hash** → `422 idempotency_key_mismatch`. It never replays another request's response.
  6. **Same key, still `in_flight`** → `409 idempotency_in_progress`. An `in_flight` row older than 60 s counts as abandoned and is taken over.
  7. **Same key, `completed`** → replays the stored status and body, with the header `Idempotent-Replayed: true`. The action does not run.
  8. **Finish**: a response below 500 is stored as `completed`. A 5xx marks the row `failed`, so a retry runs again.
  9. **Expiry**: rows expire after 24 h. `idempotency:prune` runs hourly.
- **RLS**: the table is protected like the other customer-owned tables (owner: `actor_customer_id = dahab_current_customer_id()`, or an elevated scope). Customer requests can therefore only see their own keys.
- **Why not "state-based" only**: the user chose the shared layer. The Actions still lock the row and refuse conflicting transitions (R4), so a lost `in_flight` row can never apply a transition twice.
- **Scope of adoption**: only the two new routes in this feature. Adding it to existing POSTs (for example identity-document submission, which Part 2 marks "not implemented yet") is a separate change, listed as follow-up.

## R3 — Suspension state on the customer row

- **New columns**:
  - `suspended_note TEXT` — the staff note, never shown to the customer;
  - `status_before_suspension TEXT`, with CHECK `IN ('pending_verification','active','rejected')`.
- **New constraints**:
  - `customer_suspension_state`: `(status = 'suspended') = (status_before_suspension IS NOT NULL)`;
  - `customer_suspended_reason_check`: `suspended_reason` must be one of the seven codes (R5), or NULL.
- **Legacy flags**: `CustomerStatus::SUSPENDED->legacyFlags()` hard-codes `is_verified = true`. That is wrong for a suspended customer who was waiting or rejected.
  - `Customer::suspend()` sets `is_verified` from `status_before_suspension` (true only when `active`), keeping `is_suspended = true`.
  - The existing flag/status CHECK already allows `is_verified` either way when suspended.
  - `transitionTo()` refuses `SUSPENDED`; the two new model methods `suspend()` / `reinstate()` are the only way in and out.
- **Reinstate**: clears `suspended_reason`, `suspended_note`, `suspended_by`, `suspended_at` and `status_before_suspension`, then transitions to the stored state. `suspended_needs_actor` is satisfied because `is_suspended` becomes false.

## R4 — Suspend / reinstate Actions

- **Actions**: `SuspendCustomerAction` and `ReinstateCustomerAction`, under `DB::transaction` + `lockForUpdate` on the customer row.
- **Conflicts**: suspending an already-suspended customer → `409 customer_already_suspended`; reinstating one who is not suspended → `409 customer_not_suspended`. Both are new `DomainApiException` codes, like the idempotency ones (the codebase keeps `AuthErrorCode` for auth failures).
- **Audit**: the existing `CUSTOMER_SUSPENDED` / `CUSTOMER_UNSUSPENDED` events, one each, with:
  - `entity_type = 'customer'`, `entity_id = customer_id`;
  - before `{status, suspended_reason}` and after the same;
  - `reason` = the note, and the payload holds the reason code.
- **Response**: `200` with the refreshed file (R1 shape), so the Dashboard redraws from the response.
- **Sessions**: suspension does not end sessions (Part 1 §2.2). The trade gate (`EnsureCustomerStanding`) already re-reads status on every request.

## R5 — Seven reason codes replace five

- **Codes**, with staff labels:
  - `piece_misrepresented` — "A piece was not what they said it was"
  - `off_platform_dealing` — "Tried to deal outside Dahab"
  - `repeated_disputes` — "Repeated disputes against them"
  - `reported_by_users` — "Reported by other users"
  - `identity_unconfirmed` — "Identity could not be confirmed"
  - `customer_request` — "They asked us to close it"
  - `other` — "Something else"
- **Customer-facing wording**: the Customer App holds it (display text, R8).
- **Migration**: remaps any existing rows before adding the CHECK. Only seeders and factories use the old codes, but the migration is defensive:
  - `fraud_suspected` → `piece_misrepresented`
  - `policy_violation` → `off_platform_dealing`
  - `kyc_failed` → `identity_unconfirmed`
  - `staff_request` → `other`
  - `other` → `other`

  `down()` maps back where the mapping is 1:1 (`other` stays `other`), and drops the CHECK.
- **Enum**: `SuspendedReason` gains a `label()`. `GET /dashboard/customers/suspension-reasons` is **not** added; the codes and labels ship in the OpenAPI enum and the Dashboard types, like identity review reasons.

## R6 — Identity review on a suspended customer

`ReviewIdentityDocumentAction::activate()` / `reject()` would today move a suspended customer to `active` or `rejected`, silently lifting the suspension. This is a latent bug that this feature makes reachable.

- **Decision**: when the customer is suspended, the review updates `status_before_suspension` (approve → `active`, reject → `rejected`) and the customer stays suspended. `needs_resubmission` leaves it unchanged. The review's audit entry is unchanged.
- **Test**: approve a pending document of a suspended customer → still suspended; reinstate → active.

## R7 — Activity: History and Sessions

- **History**: `GET /dashboard/customers/{customer}/activity?cursor=&per_page=`.
  - **Rows** are audit entries where any of these holds:
    - `actor_customer_id = c`;
    - `entity_type = 'customer' AND entity_id = c`;
    - `entity_type = 'identity_document' AND entity_id IN (c's documents)`.
  - **Excluded**: `auth.token.rotated` (the raw refresh). Sign-in, sign-out, OTP and revocation kinds stay.
  - **Reuse**: the rows go through `AuditQuery::visibleTo()`, so a `view_own` holder sees only rows they acted in. They reuse `AuditCursor` (keyset) and `AuditEntryPresenter` (labels, categories, summaries).
  - **Authorization**: `customer.view` **and** (`audit.view_all` or `audit.view_own`), else `403 permission_denied`. The Dashboard hides the panel accordingly.
  - **Index**: new `idx_audit_actor_customer ON audit_log(actor_customer_id, created_at DESC)` for SC-005. The entity path uses the existing `idx_audit_entity`.
- **Sessions**: `GET /dashboard/customers/{customer}/sessions?page=&per_page=` returns:
  - `devices[]` — all trusted devices (a short list), with `device_ref` = the first 12 characters of `fingerprint_hash` (already a hash), `first_seen_at`, `last_seen_at`;
  - `sessions` — paginated **open** token families of this customer: `started_at` = min `created_at`, `last_active_at` = max(`last_used_at`, `created_at`), `expires_at` = the latest token expiry. A family counts as open while any of its tokens is unexpired.
  - **As built (2026-09-28, user decision)**: sign-out, sign-out-everywhere and reuse detection **delete** the token rows (`RevokeTokenFamilyAction`; `revoked_at` is never written), so ended sessions leave nothing to list. The first draft's `ended_at` / `open` fields are dropped. Past sessions are read from History (sign-in, sign-out and revocation audit entries). A session history table was offered and not chosen.

  It is grouped in SQL by `family_id` over `personal_access_tokens` where `tokenable` is the customer. Token values and abilities are never returned. Permission: `customer.view`. It is not audited, like the rest of the file after the opening entry.
- **Why two endpoints**: each list pages independently, and the file opens without waiting for them (SC-005).

## R8 — Customer App (Flutter)

- `Customer` already parses `status` / `is_suspended`; it gains `suspendedReason` (from `suspended_reason`, which `/customer/auth/me` already returns).
- The home shell shows a `SuspendedNotice` banner when `status == 'suspended'`, with the customer-facing wording per reason code (en + ar, in `lib/core/i18n`) and a generic line for unknown codes. It never shows the staff note (the API does not send it).
- The existing `account_suspended` error message stays. No new endpoint is needed.

## R9 — Search from the Customer file menu item

- `GET /dashboard/customers` gains an optional `q` (non-breaking). When it is present it is an exact match on `display_ref` or on the phone as stored (E.164 — registration accepts nothing else and there is no normaliser; spaces in `q` are ignored), across all statuses, and `status` is ignored.
- The Dashboard's People → Customer file item opens a search box → result list → the file at `/dashboard/customers/:id`. The Users page's rows link to the same route.

## R10 — Dashboard structure

- **Route**: `dashboard/customers/:id` (the file), plus `dashboard/customer` (the search), replacing the placeholder. Both need `customer.view`.
- **Components** (`src/components/customer-file/`): `CustomerProfileCard`, `IdentityDocumentsPanel` (reuses `DocumentPreview`), `SuspendForm` / `ReinstateForm` (inline in the profile card, as in the design; need `customer.suspend`), `CustomerHistoryPanel` (needs one of the audit permissions), `CustomerSessionsPanel`.
- **Data**: the service sends an `Idempotency-Key` (a `crypto.randomUUID()` per dialog submission, reused on retry of the same submission).
- **Types**: the seven reason codes and labels live in `src/types/customer.ts`.
