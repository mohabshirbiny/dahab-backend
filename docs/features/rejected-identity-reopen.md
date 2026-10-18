# Rejected identity: locked until staff reopen

> File: `docs/features/rejected-identity-reopen.md` · Branch: `feature/rejected-identity-reopen` in dahab-backend, dahab-dashboard, dahab-flutter
> Status: in progress · Date: 2026-10-18

## Goal

The owner's rule: "When the admin rejects, the customer can still send again. I want to close
that. While rejected they cannot send; they contact customer service. In the Dashboard the admin
can move rejected customers back to waiting, then approve them or ask them to fix their details."

This reverses one part of testing fixes package A (`docs/features/testing-fixes.md`, item 2, which
let a rejected customer start a new attempt). "Ask again" (`needs_resubmission`) is unchanged: the
customer still uploads a new attempt. A rejected customer can still sign in and read (spec 002
FR-032); trading stays refused by the verified gate.

## Impact Summary

```
Backend:      YES
Database:     NO  (no migration: no status guard trigger; the one-open index already allows it)
API:          YES (one new endpoint; one tightened rule)
Dashboard:    YES
Customer App: YES
Auth:         NO
Permissions:  NO  (reuses identity.review)
```

## Backend Impact

- `SubmitIdentityDocumentAction`: with no open document, the customer's latest attempt (highest
  `attempt_number`) being `rejected` refuses the upload with `409 identity_rejected_contact_support`.
  `pending` still refuses with `document_already_pending`; `needs_resubmission` still supersedes.
- `CustomerResource::verification()`: `can_submit` is true only with no document yet or a
  `needs_resubmission` latest attempt (never for `rejected`, `pending`, `verified`, or a closed account).
- New `ReopenIdentityDocumentAction` ("Move back to waiting"), one transaction:
  - the document must be `rejected` **and** the customer's latest attempt, else `409 illegal_document_transition`;
  - a closed account is refused `409 account_closed`;
  - document `rejected → pending` on the **same row** (same images, same attempt number); the
    rejection's `reviewed_by`, `reviewed_at`, `review_reasons`, `review_note` are cleared on the row
    and kept in the audit row's `before`;
  - customer `rejected → pending_verification`; behind a suspension, `status_before_suspension`
    `rejected → pending_verification` (the suspension is never lifted);
  - audit `identity.document.reopened` (category Identity, label "Rejected identity moved back to
    waiting") with the written reason; `AuditEntryPresenter` shows the status change.
  - No message is sent to the customer: the app shows "Waiting for review" on its next reload.
  - Then the existing `ReviewIdentityDocumentAction` (Verify / Ask again / Reject) applies unchanged.
- *Why the same row and not a new attempt:* a copy would share the encrypted image files between
  two rows (retention would delete them under the other), and "attempt N" counts what the customer
  sent, not staff decisions. The rejection stays readable in the audit log and the customer file's
  History. `IdentityDocumentStatus::isTerminal()` keeps `rejected` "final" for the review action:
  only this action reopens it.
- Rejection message (`customer.verification_rejected.sms` / `.mail_body`, EN + AR): now says to
  contact customer service. The golden fixture was updated for exactly these two defaults; any
  published App text override still wins.

## Database Impact

None. Checked: `identity_document_status_check` already allows `pending`; there is no transition
trigger on `identity_document`; `identity_document_one_open` (one pending/needs_resubmission row per
customer) holds because a rejected latest attempt means no other open row; `trg_identity_document_next_attempt`
only fires on INSERT; forced RLS is unchanged.

## API Changes

| Endpoint | Change | Class |
|---|---|---|
| `POST /customer/me/identity-documents` | New refusal `409 identity_rejected_contact_support` while the latest attempt is rejected | Potentially breaking (new rule) — the only consumer, the Flutter app, is updated |
| `GET /customer/auth/me` → `verification.can_submit` | `false` for a rejected latest attempt (was `true`) | Potentially breaking (meaning tightened) — Flutter updated |
| `POST /dashboard/identity-documents/{document}/reopen` | **New.** `identity.review`. Body `{ "reason": string 5–500 }` (required). `200` `{data: StaffIdentityDocument}` (status `pending`, `customer.status`). `404`, `409 illegal_document_transition` / `account_closed`, `422 validation_failed` / `reason_required`. Not idempotent-keyed: a repeat finds the document pending and gets `409` | Non-breaking |

OpenAPI (`#[OA]` on both controllers, `ReopenIdentityDocumentRequest`) and Postman
(*Dashboard → Identity → Reopen — Move back to waiting*; the submit request's description) updated.

## Dashboard Impact

- `api/endpoints.ts` `identityDocumentReopen`; `identity-document.service.ts`
  `reopenIdentityDocument(id, reason)`; `useIdentityDocuments.ts` `useReopenIdentityDocument()`
  (refreshes the customer lists, tab counts and badge, also on a conflict).
- `IdentityReviewPanel.vue` (Users → Rejected → the customer): the rejected banner says the customer
  cannot send again; **Move back to waiting** (only with `identity.review`) opens a dialog with a
  required reason (5–500). On success the panel shows the normal Verify / Ask again / Reject.
  Errors: conflict / not found / forbidden close the dialog and show a notice; `account_closed` has its
  own text; validation shows under the field.

## Customer App Impact

- `lib/features/account/identity_again_screen.dart`: for a rejected customer (`verification.isRejected`
  and not `canSubmit`) the screen shows "Your ID was not approved", the reasons and note, and
  **Contact customer service** (`R.support`) instead of the upload form; the sell gate shows the same
  with the button; the new error code shows the same sentence and reloads the customer.
- `account_screen.dart`: the National ID row of a rejected customer opens that screen.
- `settings_screens.dart`: the `account.verification_rejected` inbox item opens that screen.
- Texts `account.identity.rejected_title|rejected_body|contact_support` (Arabic AR-DRAFT); the
  manifest `assets/text/keys.json` regenerated and copied to the Backend
  (`database/data/app_text_keys.json`). `account.identity.intro_rejected` is no longer shown (keys are append-only).
- Fake backend (`test/test_app.dart`): `can_submit` false for rejected; the submit answers
  `identity_rejected_contact_support`. New flow test.

## Authentication / Authorization

Staff guard, `auth:staff`, `abilities:staff:access`, `staff.standing`, `staff.permission:identity.review`
(the CEO and Verification roles by the seeder). Customers cannot reach it.

## Permissions

Reused `identity.review`; no new permission, no seeder change. The Dashboard hides the button without it.

## Validation

Backend: `reason` required, string, 5–500 (`ReasonRule`, re-checked in the Action). Dashboard mirrors
the minimum for UX.

## Error Handling

| Code | Where | Client |
|---|---|---|
| `identity_rejected_contact_support` (409) | customer submit | Flutter: the rejected sentence, reload |
| `illegal_document_transition` (409) | reopen | Dashboard: notice, lists refreshed |
| `account_closed` (409) | reopen | Dashboard: own text |
| `reason_required` / `validation_failed` (422) | reopen | Dashboard: under the field |

## UI States

Dashboard: loading on the dialog button; error in the dialog or as a notice; success toast "Back to
waiting review. Logged with your name."; no button without the permission. Flutter: the rejected
card replaces the form; the button opens Contact us.

## Testing

- Pest: `tests/Feature/Identity/RejectedIdentityReopenTest.php` (new) and the updated
  `IdentityAttemptsTest`, `CustomerIdentitySubmissionTest`, `IdentityVerificationFlowTest`,
  `OpenApiGenerationTest`, `TemplatesTest` (golden).
- Dashboard: `npm run type-check`, `npm run lint` (changed files), `npm run build`.
- Flutter: `flutter analyze`, `flutter test`.

## Breaking Changes

None that break a shipped client: the two tightenings are consumed only by the Flutter app, updated in
the same feature. An older app build would show the upload form to a rejected customer and get the new
409 shown as a generic error.

## Migration / Compatibility

Deploy the Backend first. Customers already rejected before the deploy are locked from the deploy on
(the rule reads the latest attempt; no data change). Rollback: revert the Backend; reopened rows are
ordinary pending documents.
