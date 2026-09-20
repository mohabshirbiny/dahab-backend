# Users and verification — design

Date: 2026-09-20 (revised same day)
Repos: `dahab-backend` (contract) · `dahab-dashboard` (page)
Status: approved · **revised to match the refactor already on disk**

## Goal

Build the `/dashboard/users` page ("Users and verification") so staff can work the queue of
customers waiting for identity verification: see who is waiting, open the document, and
Verify / Ask again / Reject.

Scope is the **waiting queue and the review workspace only**. Verified, Rejected and Suspended
remain visible but unbuilt. Export is out of scope.

## Revision note — what changed and why

The first version of this spec was written against the backend as it stood that morning. Before
implementation began, a refactor landed on disk that rebuilds the same domain with a different
and in two places better model. **The code on disk is now the authority**; this spec has been
rewritten to match it. Superseded decisions:

| First version | On disk now — authoritative |
|---|---|
| No new status; "ask again" = `rejected` + a `review_outcome` column | **`needs_resubmission` is a real status**, and `needs_resubmission → pending` when the customer re-uploads. Better: it models the state instead of overloading `rejected` |
| `approved` | **`verified`** — a renamed enum value |
| One `storage_ref`; Front/Back listed as an unbuildable deviation | **`front_ref` + `back_ref`.** The design's two tiles are now buildable |
| `city`, free text | **`governorate`**, a 27-value enum, CHECK-constrained |
| No customer lifecycle model | **`CustomerStatus`** — `pending_verification` / `active` / `rejected` / `suspended`, with `transitionTo()` and a DB CHECK keeping `is_verified` / `is_suspended` in lock-step |
| `review_issues` / `IdentityReviewIssue` | `review_reasons` / `IdentityReviewReason`, different spellings |
| Request `{decision, issues, reason}` | Request `{action, reasons, note}` with `action` ∈ `verify` / `request_resubmission` / `reject` |
| One notification class | Three: Verified / Rejected / Resubmission |

Decisions that survive unchanged: customer-centric page · scope limited to the waiting queue and
review · name + masked phone with list reads unlogged · review delegated to the existing
identity-documents endpoint rather than a second mechanism · reuse of the dashboard's existing
image, permission, error and review machinery.

## State of the backend

**Already on disk and done:** both migrations · `CustomerStatus`, `Governorate`,
`IdentityDocumentStatus` (4 values), `IdentityReviewReason`, `AuditEvent` additions ·
`StaffPermission::CUSTOMER_VIEW` (`customer.view`, roles CEO + VERIFICATION) · `Customer::transitionTo()` ·
`IdentityDocument` front/back + review columns + `scopeInReview()` · both factories ·
`ReviewIdentityDocumentAction` (full three-way) · `ReviewIdentityDocumentRequest` ·
`ViewIdentityDocumentAction` (side-aware).

**Broken — the suite is 25 failed / 7 passed:**

1. `CustomerVerifiedNotification`, `CustomerVerificationRejectedNotification` and
   `CustomerVerificationResubmissionNotification` are imported by the review Action and **do not exist**.
2. `IdentityDocumentController::review()` calls `$request->reason()` (now `note()`) and passes five
   arguments to a six-argument Action.
3. `IdentityDocumentController::image()` passes no `$side`, and the route has no `{side}` segment,
   so `front_ref` / `back_ref` is unreachable over HTTP.
4. `Staff/IdentityDocumentResource` is untouched: stale OpenAPI enum, no `review_reasons`,
   no `review_note`, no per-side image availability.
5. The identity feature tests assert the old contract.

**Not built at all:** the entire `/dashboard/customers` surface.

## Backend work

### Repair first

Write the three notifications, fix the two controller call sites, add `{side}` to the image route,
bring `Staff/IdentityDocumentResource` onto the new columns, and update the stale tests. The suite
must be green before the customer surface is added — building on a red suite hides which change broke what.

Notifications follow `CustomerRegisteredNotification` exactly: `implements ShouldQueue`, `tries = 3`,
`via()` = `['sms']` plus `'mail'` when the customer has an email, `toSms()` returning `SmsMessage`,
wording branching on `preferred_lang`. Reason wording lives in `lang/{en,ar}/identity.php` keyed by
the `IdentityReviewReason` value, so the code is the structured payload and the customer reads their
own language. **No new provider abstraction:** `App\Services\Sms\SmsSender` is already bound in
`AppServiceProvider` from `config('sms.default')` alone, behind `SmsChannel`. Wiring a real provider
later is an `.env` change.

### Image endpoint

`GET /dashboard/identity-documents/{document}/image/{side}` where `side` ∈ `front` | `back`.
Permission `identity.view`, unchanged. **Every successful call still writes one `document_view_log`
row in the same transaction as the read** — that contract is untouched, and viewing both sides of one
document is therefore two logged views, which is correct: two reads of sensitive data.

A passport has no `back_ref`; requesting `back` on one answers `410 document_image_deleted`, the
same code an erased image gives. The resource tells the dashboard which sides exist so it never asks
for one that cannot be served.

### `Staff/IdentityDocumentResource`

Gains `review_reasons` (nullable array of string), `review_note` (nullable string), and
`images: { front: bool, back: bool }` replacing the single `image_available` boolean. `status` enum
in the OpenAPI attribute becomes `['pending','verified','needs_resubmission','rejected']`.

### `GET /api/v1/dashboard/customers`

`auth:staff` + `abilities:staff:access` + `staff.permission:customer.view`

| Param | Rules | Default |
|---|---|---|
| `verification_status` | `sometimes`, `in:waiting` | `waiting` |
| `per_page` | `sometimes`, `integer`, `min:1`, `max:50` | 25 |

**"Waiting" means a document in `pending`, not `scopeInReview()`.** A `needs_resubmission` document
is waiting on the *customer* to upload again, not on staff to decide; putting it in the staff queue
would show work nobody can action. `scopeInReview()` stays for callers that want the broader set.

`ListCustomersForVerificationAction` selects from `customer`, `EXISTS` on a pending document, eager-loads
only the pending documents, orders by the oldest pending document's `created_at` (correlated `MIN`
subquery) then `customer_id` for a stable page boundary.

Note for later: now that `CustomerStatus` exists, the other three chips are a `where status = ?` away.
They stay out of scope because scope was set deliberately, not because they are hard.

### `StaffCustomer` resource

```json
{
  "customer_id": "uuid",
  "display_ref": "004417",
  "full_name": "Mona Hassan Ibrahim",
  "phone_masked": "+20 10 •••• 4417",
  "governorate": "cairo",
  "status": "pending_verification",
  "is_verified": false,
  "is_suspended": false,
  "created_at": "2026-09-14T09:12:00+00:00",
  "pending_documents": [ /* StaffIdentityDocument */ ]
}
```

`full_name` and `governorate` are nullable. `phone_masked` is computed by a new `MaskedPhone`
support class; the raw phone never leaves the backend, so there is no path to a bulk phone dump.
`governorate` is the stable code — the dashboard owns the label, per the enum's own docblock.
`status` is included alongside the legacy flags because it is now the authoritative column.

### `GET /api/v1/dashboard/customers/{customer}`

Same permission, `whereUuid`. Looked up inside the Action with no route-model binding, so an
unauthorised caller cannot distinguish a missing customer from an existing one — the rule the
identity routes already follow. Returns `documents` (every status, newest first) instead of
`pending_documents`.

### Contract artefacts

`#[OA\…]` on every changed controller, Request and Resource, then `composer swagger:generate`.
Postman gets the two customer requests, the `{side}` image path, and the review body updated to
`{action, reasons, note}`. Pest tests through the HTTP boundary.

## Dashboard

Route `/dashboard/users` replaces the placeholder. `meta.permissions = ['customer.view']`.

### The breaking change it must absorb

The refactor renames `approved` → `verified`, adds `needs_resubmission`, changes the review body from
`{decision, reason}` to `{action, reasons, note}`, and splits one image into two sides. The dashboard's
`src/types/identity.ts` still maps `approved` and its service still posts `{decision, reason}`, so the
**existing identity-documents page breaks the moment this ships**. Updating it is part of this work,
not a follow-up — the workspace rule is that the backend never changes silently under the dashboard.

### Reused unchanged

`useIdentityDocumentImage` · `useApproveIdentityDocument` → renamed to verify · `refreshOnConflict` ·
`usePermissions` · `LoadingState` / `ErrorState` / `EmptyState` · `errorCodeOf` + `FAILURE_TEXT` ·
`Panel` · `FilterChips` · `Pagination` · `NoteBanner` · `KeyValueRow` · `StatusTag` · `ConfirmDialog` ·
`DModal` · `RejectDocumentModal` (body shape changes; component unchanged).

### New

`customer.service.ts` · `useCustomers.ts` · `types/customer.ts` · `CustomerQueueTable.vue` ·
`CustomerVerificationPanel.vue` · `AskAgainModal.vue`.

### Chips

`FilterChips` already supports `disabled` + `title`. Verified / Rejected / Suspended render visible,
disabled, titled "Not available yet", **with no count**. "Export all customers" the same.
`verification_status` accepts only `waiting`. No fabricated status behaviour.

### Data flow

```
/dashboard/users?page=1&customer=<uuid>
  guard: meta.permissions = ['customer.view']   → 403 ⇒ forbidden page
  ↓
useCustomersQuery({status:'waiting', page})      keepPreviousData + retryTransient
  GET /dashboard/customers?verification_status=waiting&per_page=10&page=N
  → rows: name · governorate label · joined · doc_kind · submitted   NO image call, NO log
  ↓  "Review" ⇒ router.replace({query:{...q, customer:id}})   deep-linkable
useCustomerQuery(id)
  GET /dashboard/customers/{id}
  → Name on the account · Phone (masked) · Where          (Payout account row omitted)
  ↓
DocumentPreview, once per available side
  GET /dashboard/identity-documents/{id}/image/front   and  /back when images.back
  ⚠ EACH IS A LOGGED READ — two sides is two view-log rows, which is correct
  ⚠ fires when the panel opens, never from the list
  ↓
actions  v-if can('identity.review')  else the existing read-only NoteBanner
  Verify     ConfirmDialog       → {action:'verify'}
  Ask again  AskAgainModal       → {action:'request_resubmission', reasons:[…], note?}
  Reject     RejectDocumentModal → {action:'reject', reasons:[…], note?}
    POST /dashboard/identity-documents/{id}/review
  ↓
  409 conflict → refreshOnConflict invalidates; "Someone else already decided…"   existing
  403 / 422    → existing describe() + FAILURE_TEXT map                            existing
  ↓
onSuccess: setQueryData(identityKeys.detail) · invalidate identityKeys.all + customerKeys.all
  ↓
toast states what was RECORDED and QUEUED, never "delivered"
  ⚠ the response is the decision, not the delivery. No delivery-status field exists
    and none will be invented.
  ↓
queue refetches → decided customer drops out → clear ?customer → next row
```

Note that **Reject now also carries reasons** — the FormRequest requires them for both
`request_resubmission` and `reject`. Ask again and Reject differ in the resulting status and in
which notification is sent, not in the shape of the payload.

## Deliberate deviations from the design reference

`docs/dahab-admin-dashboard.html` line 1522:

| Design element | Why not built |
|---|---|
| Customer type "Ordinary / Market maker" + MM code | `market_maker_approval` is in the schema docs, never migrated |
| Payout account row | `payout_account` is in the schema docs, never migrated |
| Verified / Rejected / Suspended chips | Out of scope. Visible, disabled, uncounted |
| Export all customers | No endpoint. Visible, disabled |

Front and Back tiles are **no longer a deviation** — the refactor made them real.

## Backend gaps to report (not built here)

1. **In-app notification.** "the app shows them exactly what to fix" needs an in-app channel. There is
   no `notifications` table and no `GET /customer/me/identity-documents`. SMS and email will work;
   the in-app half needs a customer-side read endpoint.
2. **`governorate` backfill.** Registration does not yet collect one, so it is null for every existing
   customer. Adding it to the registration flow is separate work.
3. **Suspend / unsuspend.** `customer.suspend` exists in `StaffPermission` with no route behind it;
   `CustomerStatus::SUSPENDED` and `transitionTo()` now make it nearly trivial.
4. **Counts for the remaining chips.** No counts endpoint; the identity page derives counts from
   `meta.total`, one request per status.

## Change classification

| Change | Class |
|---|---|
| New `GET /dashboard/customers`, `/{id}` | Non-breaking (new endpoints) |
| `customer.view` permission | Non-breaking (gates new routes only) |
| `approved` → `verified` in the `status` response enum | **Breaking** |
| Review body `{decision, reason}` → `{action, reasons, note}` | **Breaking** |
| `/image` → `/image/{side}` | **Breaking** (URL change) |
| `image_available` → `images{front,back}` | **Breaking** (field removed) |
| `needs_resubmission` added to the status enum | Potentially breaking (exhaustive UI maps) |

**Four breaking changes.** The Constitution says breaking changes ship under a new version prefix with
the old one deprecated on a schedule. That is not what is happening here: the refactor changed `/api/v1`
in place. This is defensible **only** because the sole consumer is the dashboard in this workspace and
it is being updated in the same breath — no third party holds this contract, and `/api/v1` has not been
published. That reasoning must be stated wherever this ships, and it stops being true the day anyone
else integrates.
