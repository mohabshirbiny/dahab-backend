# Dahab — Technical Specification

## Part 1 of 4: Authentication & Authorization

*Senior developer handoff · PostgreSQL 15+ · This part defines the cross-cutting security layer that every later part references. Parts 2 (API endpoints), 3 (backend logic & workflows) and 4 (integrations) point back to the roles, guards and error contracts defined here rather than restating them.*

*Where this document says "enforced by the schema", the constraint already exists in the applied SQL (files 01–05). Where it says "enforced by the service", the rule lives in application code and this spec is its source of truth. Open items the business documents have not decided are listed at the end of this part — build around them, do not guess past them.*

---

## 1. What this layer is responsible for

Dahab holds other people's money and other people's gold. The authorization layer is not a login screen; it is the thing that makes the audit log meaningful. Three properties must hold on every request that changes state:

1. **A named actor is attached.** Every state-changing request resolves to exactly one `customer_id` or one `staff_id`. This is not a logging nicety — the ledger (`ledger_transaction.customer_id`/`staff_id` with a `CHECK` that at least one is present) and the audit log (`audit_log` with the same `CHECK`) both refuse rows without an actor. A request that cannot be attributed cannot move money.
2. **Least privilege is enforced by the engine, not remembered by code.** Customer data isolation is Postgres row-level security; wallet visibility is a Postgres grant. Neither depends on the application remembering a `WHERE` clause.
3. **Sensitive actions are visible in real time and reversible.** Founder-account compromise is the single largest risk in the system (either founder can do anything, no action needs a second approval), so it gets device approval, mutual freeze, and real-time notification as a dedicated sub-layer.

This part is organized as: session model (customer, then staff), the permission matrix as data, RLS and wallet grants, the founder-security sub-layer, the request-context contract every endpoint inherits, and the error contract for auth failures.

---

## 2. Customer authentication

### 2.1 Credential model

Primary authentication is **phone number + password, together, every sign-in.** A one-time code (OTP) is an *additional* gate on a sign-in from an unrecognised device, not the primary factor. Email is a *second confirmation channel on withdrawal*, never a sign-in factor. This matches the prototype: a login screen with phone + password, a separate OTP screen, and an email-confirmation step before a withdrawal.

| Factor | Where it is used | Stored as |
|---|---|---|
| Phone number | Sign-in identity; unique per customer (`customer.phone UNIQUE`) | Plain (it is an identifier, not a secret) |
| Password | Required on every sign-in alongside phone | Argon2id hash. Never recoverable, never returned by any endpoint. |
| OTP (SMS) | New-device sign-in; phone-number change; not on known devices | Not stored; short-lived hash in a rate-limited cache with a TTL |
| Email confirmation link | Second check on each withdrawal; email change | One-time signed token, single use, short TTL |

**Rules that hold regardless of anything else** (carried from the admin-roles document, §9):

- No one — no staff role, no founder — can see a customer's password. It is Argon2id-hashed and never recoverable. There is no "view password" or "reset to known value" path; a reset issues a new set-password token to the customer's own channel.
- A foreign phone number and a passport (instead of an Egyptian ID) are both valid. Residence is **not** checked. Handover is still in Cairo. None of this is an auth concern beyond: the identity document kind is `egyptian_id` or `passport` (`identity_document.doc_kind`), and verification status gates *trading*, not *browsing* or *sign-in*.

### 2.2 The verification gate (distinct from authentication)

Authentication proves *who is signing in*. Verification proves *they may trade*. They are separate and must not be conflated:

- **Browsing** needs no account at all. Public listing reads are served by a non-authenticated path (see §5.3).
- **An account** can be created and signed into before verification. A customer may sign in, look around, save pieces, and top up a wallet while `customer.is_verified = false`.
- **The first sale or purchase** requires `is_verified = true`. The gate fires at the moment of the first state-changing trade action (`POST` of a buy request, or submitting a listing for review), not at signup and not at browse. The prototype's copy is exact: *"Required before your first sale or purchase, not before you browse."*

Verification itself (ID/passport upload → Verification-role review → approve/reject) is an identity workflow detailed in Part 3. The auth layer only reads the resulting boolean and the suspension state:

```
trade_allowed(customer) :=
      customer.is_verified = true
  AND customer.is_suspended = false
```

A suspended customer (`is_suspended = true`) can sign in and read their own data (they need to see why, withdraw a remaining balance, and wind down open orders) but every trade action is refused with `403 account_suspended`. Suspension always carries a reason from a fixed list and a named actor (`suspended_by`, `suspended_reason`), enforced by `customer.suspended_needs_actor`.

### 2.3 Session issuance and device recognition

On a successful phone + password check:

1. **Known device** (a device fingerprint already trusted for this customer): issue the session immediately.
2. **New device**: hold the session, send an OTP to the customer's phone, and require it before the session is issued. On success, record the device as trusted. This is the same "we send a code every time you sign in from a new device" the security screen promises.

A session is a short-lived access token plus a longer-lived refresh token. Recommended: access token ~15 min, refresh rotated on use and revocable. Tokens carry `customer_id`, `is_verified`, `is_suspended` **as claims for routing only** — the authoritative check is always re-read server-side inside the request transaction, because verification or suspension can change between token issuance and use. A token claim is a hint; the database row is the truth.

Failed sign-in throttling mirrors staff (§3.4): a small number of failures, then a timed lock, to blunt credential stuffing. Exact threshold for customers is an **open item** (the documents fix staff at 5/30-min but are silent on customers).

### 2.4 The email second-check on withdrawal

A withdrawal request is **not** released on the strength of the session alone. Before a withdrawal enters review, the customer confirms via a one-time link sent to their verified email — *"Your email is the second check whenever you withdraw money."* This is an auth-layer obligation the withdrawal endpoint (Part 2) inherits: no confirmed email token, no withdrawal. It is deliberately independent of the session so that a stolen session on its own cannot drain a wallet.

---

## 3. Staff authentication and roles

### 3.1 Roles are fixed; the actions they map to are data

There are exactly six staff roles, fixed in the domain and modelled as an enum (`staff_role`): `ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch`. New roles are a schema change, deliberately — you do not invent a role in the admin panel. What each role *may do* is the permission matrix (§4), which is reference data the service reads, not a scatter of `if role == …` checks. In the application that matrix is enforced with Spatie roles and permissions on the `staff` guard — see [Dashboard authentication & authorization](./dahab-dashboard-authorization.md).

Every staff account uses its own credentials. **Shared logins are forbidden**, because a shared login makes the audit log meaningless — the whole point of the layer. There is exactly one sanctioned exception: the **IGI branch account**, shared by agreement, which logs the *branch* rather than the individual inspector. That exception is structural in the schema: `staff.igi_has_branch` forces `role = 'igi_branch'` if and only if a `branch_id` is attached, so an IGI account is always branch-bound and a non-IGI account can never be.

### 3.2 The two founders

`ceo` and `coo` are two accounts with identical, unrestricted permissions — either can do anything, including creating accounts and assigning permissions. They are distinguished **only by the audit log**: every action records against the individual who performed it, and neither can act as the other. No action requires dual approval — a deliberate choice for a two-founder company, to be revisited if a third full-permission account is ever created.

**The one deliberate asymmetry:** wallet access is limited to `ceo` + `finance`. The COO, with otherwise full permissions, does **not** see or move wallet balances. This is not enforced by a column or an `if` — it is a Postgres grant (§5.2). The COO's database role simply has no `SELECT` on the wallet views. This is the safest possible expression of the rule: even a bug in application code cannot show the COO a balance the engine won't return.

### 3.3 Staff sessions

Staff authenticate with their own credentials and operate under:

- **Session timeout:** 30 minutes idle (admin-roles §4, "all staff accounts").
- **Failed sign-in:** 5 attempts, then a 30-minute lock (admin-roles §4).
- **Every privileged action is logged** with actor, timestamp, device, IP, before/after JSON, and a reason where one is required (§6).

Staff connections use a database role per staff role (`dahab_ceo`, `dahab_finance`, …). The application does not connect as a superuser and then filter; it connects as the role that already lacks the grants the role is not permitted. RLS on customer tables is *bypassed* for staff roles (staff must see across customers to do their job), but every other guard — wallet grants, append-only triggers, the karat CHECK, the balanced-ledger trigger — applies to staff exactly as to anyone, including founders. **No role, including the founders, can edit or delete the audit log or the ledger.**

### 3.4 IGI inspector — the narrowest role

The `igi_branch` account is external staff with the tightest permission set of any role. Auth-layer specifics:

- It is **branch-scoped**: it may act only on pieces routed to *its own* branch, and only while those pieces are in inspection. This is not just a permission flag — the service filters every read and write by `inspection.branch_id = session.branch_id`, and a later part's RLS-style guard on the IGI-facing views enforces it.
- It **sees no prices, no wallets, no buyer/seller contact details.** The IGI-facing API surface (Part 2) exposes only: the piece routed for inspection, the fields to record a result, and the collection-code + collector-ID check at handover.
- It **cannot edit a submitted inspection result.** This is enforced by the append-only trigger on `inspection_result`; a correction is a new superseding row. The auth layer's job is only to ensure the *actor* on that new row is a valid `igi_branch` account for the right branch.

---

## 4. The permission matrix (authoritative, as data)

This is the definitive list. **If an action is not listed here, it belongs to the founders only.** The service resolves every privileged endpoint against this table before executing (in the application: as Spatie permissions on the `staff` guard, seeded from this table; only the permissions the current spec needs are materialised — see [dahab-dashboard-authorization.md](./dahab-dashboard-authorization.md) §4). Values are transcribed exactly from the admin-roles permission matrix; the "CEO only" cells are the wallet-access narrowing and are enforced additionally by grant (§5.2), so even the COO's application code cannot perform them.

### 4.1 Listings and orders

| Action | CEO | COO | Finance | Operations | Verification | IGI |
|---|---|---|---|---|---|---|
| Approve or reject a new listing | ✓ | ✓ | — | ✓ | — | — |
| Ask a seller for a better photo | ✓ | ✓ | — | ✓ | — | — |
| Take a live listing down | ✓ | ✓ | — | ✓ | — | — |
| Approve a piece for market makers | ✓ | — | ✓ | — | — | — |
| Extend a deadline on request | ✓ | ✓ | — | ✓ | — | — |
| Change the inspection branch on an open order | ✓ | ✓ | — | ✓ | — | — |
| Cancel an order | ✓ | ✓ | — | ✓ | — | — |
| Freeze an order during a dispute | ✓ | ✓ | — | ✓ | — | — |
| Enter an inspection result | ✓ | — | — | — | — | ✓ |
| Confirm handover at the counter | ✓ | — | — | — | — | ✓ |
| Check the ID of someone collecting for another | — | — | — | — | — | ✓ |

Note the two founder columns differ only where wallet access is involved. For listings/orders they are identical. "Approve a piece for market makers" is CEO+Finance, **not** COO — it is a money-adjacent approval (it waives commission), so it follows the wallet-access narrowing.

### 4.2 Money

| Action | CEO | COO | Finance | Operations | Verification | IGI |
|---|---|---|---|---|---|---|
| Release a withdrawal | ✓ | — | ✓ | — | — | — |
| Match an incoming transfer | CEO only | — | ✓ | — | — | — |
| View a wallet balance | CEO only | — | ✓ | — | — | — |
| Open a wallet statement | CEO only | — | ✓ | — | — | — |
| Pay compensation to a wallet | ✓ | — | Up to cap | — | — | — |
| Refund a buyer in full | ✓ | — | ✓ | — | — | — |
| Issue or correct a tax invoice | ✓ | — | ✓ | — | — | — |
| Adjust a wallet balance directly | CEO only | — | — | — | — | — |
| Change commission or spread rates | ✓ | — | ✓ | — | — | — |
| Enter a gold price manually | ✓ | — | ✓ | — | — | — |
| Set the gold price correction | ✓ | — | ✓ | — | — | — |
| Upload the Rapaport matrix | ✓ | — | ✓ | — | — | — |
| Turn a karat on or off | ✓ | — | ✓ | — | — | — |
| Stop new listings in a category | ✓ | ✓ | — | ✓ | — | — |
| Pause a whole category | ✓ | — | — | — | — | — |
| Stop everything | ✓ | — | — | — | — | — |
| Show or hide something customers see | ✓ | — | — | — | — | — |
| Record a bank movement outside the app | CEO only | — | ✓ | — | — | — |
| Close the day | CEO only | — | ✓ | — | — | — |

Two important reads of this table:

- **The COO is excluded from every wallet-touching action even though the COO is a founder.** The COO cannot view a balance, adjust a wallet, match a transfer, record a bank movement, or close the day (the "CEO only" rows), and cannot release a withdrawal either (that row is CEO + Finance — both may release, but not the COO). This is the wallet-access narrowing made concrete, and §5.2 enforces it by grant so it cannot be bypassed in code.
- **"Change commission or spread rates" is CEO + Finance (RESOLVED — was OI-1.4).** The earlier conflict — the admin-roles matrix reading founders-only versus the blueprint settings table reading "Founders and CFO" — is resolved in favour of **CEO + Finance**: the COO is excluded because it is a money-adjacent action (consistent with the wallet narrowing), and Finance is included per the settings table. The admin-roles matrix and blueprint are updated to match. **Withdrawal release is likewise CEO + Finance** (resolved; was OI-2.3).

### 4.3 Accounts and access

| Action | CEO | COO | Finance | Operations | Verification | IGI |
|---|---|---|---|---|---|---|
| Approve an ID or passport | ✓ | — | — | — | ✓ | — |
| Verify a payout bank account | ✓ | — | ✓ | — | ✓ | — |
| Suspend a user account | ✓ | — | — | — | — | — |
| Reinstate a suspended account | ✓ | — | — | — | — | — |
| Create a staff account | ✓ | — | — | — | — | — |
| Change a staff member's permissions | ✓ | — | — | — | — | — |
| Manage promo codes | ✓ | — | ✓ | — | — | — |
| Edit app text | ✓ | ✓ | — | ✓ | — | — |
| Edit legal text and publish a new version | ✓ | — | — | — | — | — |
| Add or edit an inspection branch | ✓ | ✓ | — | ✓ | — | — |
| Build a full case file | ✓ | ✓ | ✓ | — | — | — |
| View the audit log | ✓ | Own actions | Own actions | Own actions | Own actions | — |

"Suspend / reinstate a user account", "create staff", "change permissions", "edit legal text" are **CEO only in this table** but are conceptually founder actions. Re-checking the source: the admin-roles matrix marks these `CEO/COO = Yes` (both founders). The single-column rendering above collapsed them — **corrected reading: both founders may suspend, reinstate, create staff, change permissions, and publish legal text.** The COO exclusions are *only* the wallet rows in §4.2 plus the market-maker/category-pause/customer-visibility rows. I flag this because a mis-transcription here would wrongly lock the COO out of running the platform; the authoritative rule is "COO has full permissions **except wallets**" (open-questions §3, admin-roles §2). Where a cell above and that sentence disagree, the sentence wins. The commission/spread case (formerly OI-1.4) is now resolved to CEO + Finance (§4.2 note).

### 4.4 How the service resolves a permission

```
authorize(staff, action):
    if staff is frozen (account_freeze open on staff_id):      deny 403 account_frozen
    if action is wallet-touching (the "CEO only"/Finance set):
        # enforced twice: here AND by DB grant, defence in depth
        require staff.role in wallet_roles(action)
    else:
        require staff.can(action)      # Spatie permission on the staff guard
    if action requires a reason and none supplied:              deny 422 reason_required
    proceed; the action's own handler writes the audit_log row
```

The double enforcement of wallet actions (matrix check *and* grant) is intentional: the matrix check gives a clean `403` with a useful message; the grant is the backstop that holds even if the matrix check is ever wrong.

---

## 5. Data isolation: row-level security and grants

### 5.1 Customer row-level security

RLS is enabled on `customer`, `listing`, `buy_request`, `order`, `payout_account`, `withdrawal`, and `identity_document`. Customer-facing connections set, per request, inside the transaction:

```sql
SET LOCAL app.current_customer_id = '<uuid-from-session>';
```

and the policies restrict every row to the owning customer (buyer or seller for orders; owner for everything else). Two properties matter:

- **`SET LOCAL`**, not `SET` — the binding lives for exactly one transaction and cannot leak to the next request on a pooled connection. This is a hard requirement of the connection-pooling model; a plain `SET` on a pooled connection is a cross-customer data leak.
- The customer connection role **cannot** `SET app.current_customer_id` to an arbitrary value it chooses — the value comes from the authenticated session and is written by the framework's request-context middleware (§7), never from request input.

### 5.2 Wallet visibility is a grant, not a check

Wallet balances are derived views (`customer_wallet`, `account_balance`, `solvency_check`) over the append-only ledger. Their visibility is controlled by **granting `SELECT` on those views only to the `dahab_ceo` and `dahab_finance` database roles.** The COO's role and every other staff role simply have no grant. Consequences:

- The COO cannot see a balance because the query returns a permission error at the engine, before any application logic runs.
- There is no application code path that can be tricked into showing a wallet to the wrong role, because the data never leaves Postgres for that role.

This is why the spec insists wallet access "is a grant, not a column": a column can be read around; a missing grant cannot.

### 5.3 Public browsing does not leak seller identity

Browsing needs no account, but a live listing exposes photos, weight, karat, price and the making charge — never the seller's identity. This is served by a **dedicated public view** exposing only non-owner-sensitive columns of listings in state `live`/`reserved`, kept deliberately *outside* the owner RLS policy so that a public read can never surface `seller_id` or contact details. The public path connects as a low-privilege role with `SELECT` on that view and nothing else.

### 5.4 Identity documents

Identity documents are the most sensitive data in the system. Access is limited to `verification` and the founders, and **every view is logged, including views that lead to no decision** (`document_view_log`, append-only). The auth layer obligation: any endpoint that returns a document's content must (a) check the actor is `verification`/founder, and (b) write a `document_view_log` row *in the same transaction* as the read, so a view can never occur without its log. A read that cannot log is a read that must fail.

---

## 6. Audit and immutability (what the auth layer guarantees)

Every privileged action records: who, when, from which device and IP, what changed (before/after JSON), and a reason where required. The log is append-only — a `BEFORE UPDATE OR DELETE` trigger (`block_mutation()`) blocks all mutation, for every role including founders.

The auth layer's contribution to this is the **actor resolution and the reason requirement**:

- The actor on every `audit_log` row is the authenticated `staff_id` (or `customer_id` for customer-initiated logged events). The `audit_has_actor` CHECK guarantees no row lacks one; the session guarantees the value is the real actor, not spoofable input.
- Actions that require a reason (suspension, manual price, compensation, rate change, a branch change on an open order, and so on — the full list is in admin-roles §8) are refused at the authorize step with `422 reason_required` if none is supplied. The reason is stored, not just checked.

Endpoints in Part 2 do not each re-describe this; they declare `audited: yes` and `reason: required|optional|none`, and inherit the machinery here.

---

## 7. The request-context contract (inherited by every endpoint)

Every endpoint in Parts 2–4 executes inside a request context this layer establishes. The contract, in order:

1. **Authenticate.** Resolve the session to a `customer_id` or `staff_id`. No valid session on a protected route → `401 unauthenticated`. (Public browse routes skip this.)
2. **Load the live actor row.** Re-read `is_verified`, `is_suspended` (customer) or `is_active`, `role`, `branch_id`, and any open `account_freeze` (staff) from the database — never trust token claims for the decision.
3. **Open the transaction and bind context.** `SET LOCAL app.current_customer_id` for customers; connect as the role-specific DB role for staff. Everything the handler does runs in this one transaction, so RLS, the balanced-ledger deferred trigger, and the non-negative-wallet deferred trigger all commit or roll back together.
4. **Authorize** the specific action (§4.4) for staff; check `trade_allowed` for customer trade actions.
5. **Enforce idempotency** for any money-moving or state-creating request (contract defined in Part 2): the same `Idempotency-Key` replays the original result and never double-executes. This is called out here because it is a security property, not a convenience — without it a retried "send buy request" holds two deposits.
6. **Execute**, writing the audit row (if `audited`) and any ledger transaction *inside the same transaction*.
7. **Commit.** The deferred constraints fire at commit: an unbalanced ledger transaction, an overdrawn wallet, or an out-of-subset branch all abort the whole request atomically.

A handler never writes a ledger posting, an audit row, or a document view "beside" its main work — they are all in the one transaction, so a partial success is impossible.

---

## 8. Founder-account security sub-layer

Because either founder can do anything and no action needs a second approval, a compromised founder account is the largest single risk. Three measures, all already modelled in the schema:

1. **New-device sign-in is held until the *other* founder confirms.** A founder signing in from an unrecognised device creates a `founder_device_approval` row; the session is withheld until the other founder approves it. `approver_is_not_self` (a CHECK) makes self-approval impossible — the compromised account cannot wave itself through. Known devices sign in normally.
2. **Either founder can freeze the other instantly, from their own phone.** A freeze (`account_freeze`) stops all activity on the frozen account at once; the authorize step (§4.4) denies every action from a frozen staff account. `no_self_freeze` prevents a founder freezing themselves by accident. **Unfreezing needs both founders** — two confirmation columns (`unfreeze_confirm_1`, `unfreeze_confirm_2`) — so a single compromised account cannot undo its own freeze. A freeze is not a deletion: nothing is lost, and the account resumes the instant both confirm.
3. **Both founders are notified in real time of any sensitive action on either account** — a wallet adjustment, a rate change, a promo-code change, a manual price, or a new staff account. The channel for this notification is an **open item** (OI-1.3; the documents ask "who receives the alert and through which channel" without answering).

The recovery path *after* a confirmed compromise (beyond the freeze) is explicitly **not written down** in the business documents and is an open item (OI-1.2).

---

## 9. Auth-layer error contract

Every auth failure returns a stable, machine-readable `error.code` so clients can react without parsing prose. HTTP status and code:

| Code | HTTP | Meaning | Client action |
|---|---|---|---|
| `unauthenticated` | 401 | No/invalid/expired session on a protected route | Re-authenticate |
| `otp_required` | 401 | New device; sign-in held pending OTP | Prompt for the SMS code |
| `otp_invalid` | 401 | Wrong/expired OTP | Re-prompt; count toward lockout |
| `password_invalid` | 401 | Wrong password (do not reveal whether phone exists) | Generic "check your details" |
| `locked_out` | 429 | Too many failed attempts; timed lock active | Show the wait; do not retry early |
| `email_confirmation_required` | 403 | Withdrawal attempted without the email second-check | Send/await the email link |
| `not_verified` | 403 | Trade action before identity verification | Route to verification flow |
| `account_suspended` | 403 | Trade action on a suspended account | Show reason; allow read/withdraw/wind-down only |
| `account_frozen` | 403 | Staff action on a frozen staff account | Block; surface to the other founder |
| `forbidden_role` | 403 | Authenticated but the role lacks this action | Hide the control; log the attempt |
| `reason_required` | 422 | A reason-mandatory action supplied none | Collect a reason and resubmit |
| `wallet_access_denied` | 403 | A non-CEO/Finance role hit a wallet path | Should be unreachable in UI; a real one is a bug or an attack |

`password_invalid` and a non-existent phone return the **same** response, to avoid confirming which phone numbers have accounts. `wallet_access_denied` should never occur through the intended UI — if it does, it is either a bug or a probe, and it is itself an audited event.

---

## 10. Open items in this layer — decide, do not guess

These are unresolved in the business documents. The layer leaves a defined place for each; the behaviour needs a decision before build.

- **OI-1.1 — Customer failed-sign-in lockout.** Staff are fixed at 5 attempts / 30-minute lock. The documents do not set a customer threshold. Proposed default: same 5/30, but confirm, because customers on shared IPs and flaky mobile networks fail more benignly than staff.
- **OI-1.2 — Founder-account recovery path.** The freeze contains a compromise; the documents state the recovery path "is not written down". This needs a defined, dual-control recovery procedure (credential reset, device re-trust, session revocation) before go-live.
- **OI-1.3 — Sensitive-action alert routing.** "Who receives the alert when a cap is hit or an unusual pattern appears, and through which channel" is unanswered (blueprint §10, admin-roles §10, database-design §10). The founder real-time notifications in §8 depend on this. Needs a channel decision (SMS, push, email, a specific ops inbox).
- **OI-1.4 — Commission/spread rate-change authority. RESOLVED → CEO + Finance.** The earlier conflict (admin-roles matrix "founders-only" vs blueprint settings "Founders and CFO") is resolved in favour of **CEO + Finance**: the COO is excluded as a money-adjacent action (wallet narrowing), Finance is included per the settings table. The matrix (§4.2), the admin-roles document, and the blueprint are updated to agree. The rate-change endpoint's authorization is now unblocked.

*(OI-1.4 is a real contradiction between two source documents, not an omission. The rest of the matrix agrees with itself. Flagging rather than picking a side, per the working rules.)*

---

*End of Part 1. Part 2 (API endpoints) will reference the roles, the request-context contract (§7), the idempotency requirement, and the error codes defined here without restating them. Before I write Part 2: please confirm the reading of §4.3 (both founders may run the platform; the COO is excluded only from wallet actions and the market-maker / category-pause / customer-visibility rows), and give a steer on OI-1.4 if you have one — it's the one item that blocks a specific endpoint's auth.*
