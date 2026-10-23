# Testing fixes (buy/sell testing, package A)

> File: `docs/features/testing-fixes.md` · Branch: `feature/testing-fixes` in dahab-backend, dahab-dashboard, dahab-flutter
> Status: done · Date: 2026-10-14

## Goal

Fix the bugs found in buy/sell testing that do not touch a money rule. Each item was traced to
its root cause; the product owner made the business decisions recorded below. No ledger
posting, balance, invoice or credit note changes; nothing already posted is rewritten.

| # | Testing issue | Decision |
|---|---|---|
| 1 | Every price showed ±15 EGP/g "out of nowhere" | A karat starts with a 0 adjustment on both sides; rows staff edited stay |
| 2 | (#7) Re-uploading overwrote the earlier ID and its images | Every attempt is kept and counted; no maximum for now |
| 3 | (#6) Staff could not decide while the customer had not re-uploaded | Verify / Reject allowed while waiting; manual only, no expiry |
| 4 | (#5) The app could not tell "waiting" from "upload again" | A `verification` object on `GET /customer/me`; the app reloads it |
| 5 | (#4) No way to send the ID again from the app | "Upload your ID again" screen |
| 6 | (#3) The sell flow failed only at Submit for an unverified seller | Say so on Sell step 1, before the form (app only) |
| 7 | (#10, #14) Inbox links opened the wrong screen | Seller items open their own listing; the accepted buyer opens the order |
| 8 | (#11) No way to end a listing waiting for changes | Staff may reject it from `changes_requested`; manual only |
| 9 | (#16) Commission and spread added together in the Dahab statement | Shown apart (display only) |

## Impact Summary

```
Backend:      YES
Database:     YES (3 migrations)
API:          YES (additive only)
Dashboard:    YES
Customer App: YES
Auth:         NO
Permissions:  NO (existing identity.review, listing.review, wallet.view)
```

## Backend Impact

- **Item 1** — migration `2026_10_17_000010_zero_default_karat_adjustments`. New karats already
  started at 0 (`CreateKaratAction` → `KaratPriceAdjustment::seedZero`, and `KaratFactory`), so only
  the rows seeded by `2026_09_28_000030` change. The calculator is untouched.
- **Item 2** — `SubmitIdentityDocumentAction`: each submission inserts a new row. When the open row is
  `needs_resubmission` it becomes `superseded` (it keeps its images, reasons and reviewer) and the
  audit event is `identity_document.resubmitted` (with `attempt_number` and
  `superseded_document_id`); otherwise `identity_document.submitted`. No image is deleted any more;
  `image_deleted_at` retention still applies to every attempt. `IdentityDocumentStatus::SUPERSEDED`
  is final (`isTerminal()`), so a review on it is refused `illegal_document_transition`.
  *Why a status and not an `identity_document_attempt` table:* the document row already is one
  upload (front/back refs, kind, reviewer, reasons, view log, RLS policy, retention). A second table
  would duplicate all of it and every reader (Customer file, review queue, view log, the referral
  "same person" lookup) would have to join it. A new row per upload plus a final status keeps every
  existing reader correct, keeps forced RLS as it is, and makes "attempt N" a plain column.
- **Item 3** — no code change: `ReviewIdentityDocumentAction` already accepted verify/reject on
  `needs_resubmission`. Tests added.
- **Item 4** — `CustomerResource::verification()` reads the latest document (by `attempt_number`).
- **Item 7** — `ReleasesRequests::tell()` takes the order id; `AcceptBuyRequestAction` and
  `CancelAcceptanceAction` pass it. Mapping of every buy-request and listing message (the stored
  `customer_notification.link_kind` / `link_id`):

  | Message (`type`) | Reader | Link before | Link now |
  |---|---|---|---|
  | `buy_request.new_request` | seller | listing | listing (their own) |
  | `buy_request.accepted` | buyer | none | **order** (the new order) |
  | `buy_request.declined` / `not_chosen` / `expired` / `piece_withdrawn` / `seller_suspended` | buyer | wallet (refund) | wallet (refund) |
  | `buy_request.free_again` | buyer | listing (market) | listing (market) |
  | `buy_request.order_cancelled` | both | none | **order** (shows the cancellation and, for the buyer, the refund) |
  | `listing.approved` / `changes_requested` / `rejected` / `taken_down` | seller | listing | listing (their own) |

  The app opens a `listing` link from a `listing.*` or `buy_request.new_request` item in the owner
  view; `buy_request.free_again` in the buyer view.
- **Item 8** — `DecideListingAction`: REJECTED from `in_review` or `changes_requested`;
  `can_reject` on the staff listing follows. History row, audit (`listing.rejected`, `before.state`),
  SMS + email + inbox, and `VoidListingCreditsAction` (via `MovesListing`) are unchanged.
- **Item 9** — `BuildWalletStatementAction`: per-entry `commission_delta` / `spread_delta`; the
  Dahab view adds them to the summary, each row and each day/month row; `ExportWalletStatementAction`
  adds the columns. No ledger query changes for the other views.

## Database Impact

| Migration | What |
|---|---|
| `2026_10_17_000010_zero_default_karat_adjustments` | `karat_price_adjustment` → `fixed 0` where no history row exists for that karat + side. `down()` restores ±15 on the same untouched rows of the five seeded karats |
| `2026_10_17_000020_identity_document_attempts` | `attempt_number` (backfilled by `created_at`, NOT NULL, UNIQUE per customer, ≥ 1, set by trigger `trg_identity_document_next_attempt`); status CHECK + `superseded`; partial unique index `identity_document_one_open` (one `pending`/`needs_resubmission` per customer). Forced RLS unchanged |
| `2026_10_17_000030_listing_reject_after_changes` | `listing_transition` row `changes_requested → rejected`. Guard functions read the table; `listing_state_change` already requires a note for `rejected`; nothing leaves `rejected` |

Earlier resubmissions (before this migration) were made in place, so their first images are
gone; those customers show one attempt.

## API Changes

All additive (non-breaking) unless noted.

- `GET /customer/auth/me` (and every response that carries `CustomerProfile`: login, OTP verify,
  registration submit, account changes): new object `verification`
  `{document_status: pending|needs_resubmission|verified|rejected|null, doc_kind, reasons[], note,
  attempt_number, submitted_at, reviewed_at, can_submit}`.
- `POST /customer/me/identity-documents`: same request; the response adds `attempt_number`.
  Behaviour: a new row per submission (was: the same row updated). The documented status enum now
  matches the code (`verified`, `needs_resubmission`, `superseded`; the old doc said `approved`).
- `GET /dashboard/identity-documents[/{id}]`, `GET /dashboard/customers[/{id}]`: documents add
  `attempt_number`; `status` may be **`superseded`** — *potentially breaking* for an exhaustive
  status map; the Dashboard map is updated in the same change. The list filter accepts it.
- `POST /dashboard/identity-documents/{id}/review`: unchanged contract; documented that verify /
  reject work on `needs_resubmission`.
- `GET /dashboard/listings/{id}`: `can_reject` is now true on `changes_requested` (for
  `listing.review`). `POST /dashboard/listings/{id}/reject` accepts that state.
- `GET /dashboard/wallet-statement` (view=dahab): summary adds `commission_in`, `commission_out`,
  `spread_in`, `spread_out`; rows add `commission`, `spread` (signed; their sum = `in − out`). The
  CSV export adds a "Commission in/out, Spread in/out" line and two columns.
  Later (2026-10-23): the same view also adds the VAT — summary `vat_in`, `vat_out`, rows `vat`
  (the entry's change to `vat_payable`, signed; a credit note gives it back). VAT is owed, not
  earned: it stays out of `in`/`out` and the running balance. The CSV adds "VAT in/out" and a VAT
  column; the Dashboard shows a VAT column and a VAT card. Non-breaking (optional fields).
- Inbox (`GET /customer/me/notifications`): `buy_request.accepted` and `buy_request.order_cancelled`
  items now link `order` (link kinds already existed).

## Dashboard Impact

- `types/api.ts`, `types/identity.ts`, `types/customer.ts`, `services/customer.service.ts`:
  `superseded`, `attemptNumber`.
- `IdentityReviewPanel.vue`: subtitle "Attempt N"; in `needs_resubmission`, "Asked again on …,
  waiting N days" with **Verify** and **Reject**; a superseded attempt is read-only.
- `CustomersTable.vue`: "Attempt N", "Waiting for a new upload, waiting N days"; Review is offered
  while waiting too. `CustomerReviewPanel.vue`: points to the file for earlier attempts.
- `IdentityDocumentsPanel.vue` (Customer file): an Attempt column; every attempt opens read-only.
- `ListingReviewPanel.vue`: "Changes asked on …, waiting N days"; Reject follows `can_reject`.
- `ManualPricePanel.vue`: a line under the preview saying each karat's adjustment applies, with a
  link to the karat table (`#karat-prices` on the Pricing page).
- `StatementSummary.vue` / `StatementTable.vue` / `wallet.service.ts`: Commission and Spread cards
  and columns on the Dahab view.
- `useFormatters.ts`: `formatWaiting()`.

## Customer App Impact

- `models/customer.dart`: `CustomerVerification` parsed from `verification`.
- `services/auth`: `AuthApi.uploadIdentity` / `submitIdentity`; `AuthController.resubmitIdentity`,
  `refreshVerification` (throttled 20 s), `watchLifecycle` (reload on resume). `ApiClient` calls
  `onVerificationRequired` on any `verification_required`; `InboxController` calls back when the
  unread count grows; opening an `account.*` item reloads the customer.
- `features/account/identity_again_screen.dart` (route `id-again`): reasons and note, Egyptian ID
  (front + back) or passport (photo page, another page optional), upload `purpose=identity`, then
  submit; handles `document_already_pending`, `upload_token_invalid`, `unsupported_doc_kind`.
  `MediaKind.identity` (jpg/png/webp, 8 MB).
- `account_screen.dart`: the header pill and the National ID row show the same state (Verified /
  Waiting for review / Upload again / Not approved); the row opens the re-upload screen when
  `can_submit`.
- `sell1_screen.dart`: `sellGate()` replaces the form for an account that may not trade (waiting,
  upload again with the reasons and the button, suspended with its reason, closed).
- `settings_screens.dart`: `openInboxItem()` routes by type, then link.
- Texts: `core/text/keys/account_identity.dart` (`account.identity.*`, 38 keys, Arabic AR-DRAFT);
  manifest regenerated and copied to `database/data/app_text_keys.json`.

## Authentication / Authorization

Unchanged. The identity upload/submit routes are open in every customer state (as before);
reviews need `identity.review`; listing reject needs `listing.review`; statements need `wallet.view`.

## Permissions

None new.

## Validation

Backend unchanged. The app checks file type/size (as Signup step 3) and that both sides of an
Egyptian ID are present before sending.

## Error Handling

App: `document_already_pending` → "already waiting" and a reload; `upload_token_invalid` → add the
photos again; `unsupported_doc_kind` → both sides needed. Dashboard: existing review / listing
decision errors.

## UI States

Re-upload screen: form / sending / sent (Waiting for review) / nothing to send. Sell step 1:
gate card instead of the form. Dashboard: banners for waiting documents and listings.

## Testing

Backend (Pest): `Identity/IdentityAttemptsTest` (attempts, superseded, images kept, N+1 after
rejection, one open per customer, RLS, verify/reject while waiting + notification, every
`verification` state), `Listing/RejectAfterChangesTest`, `BuyRequest/BuyRequestInboxLinksTest`,
`Pricing/KaratDefaultAdjustmentsTest` (fresh 0/0, new karat 0/0, edited row survives, down/up,
calculator = market × purity / 0.999), statement split in `Wallet/WalletStatementTest` and
`WalletStatementExportTest`. Tests that relied on the old ±15 default now set it explicitly
(`Tests\Support\Pricing::legacyAdjustments()`), so their worked figures stay as they were.

Flutter (`test/flows_test.dart`): upload again end to end, the state after a refresh, the sell gate,
the inbox routes.

## Breaking Changes

None breaking. `superseded` is a new value in a staff-only response enum (Dashboard updated with it).

## Migration / Compatibility

Deploy the Backend (three migrations) before the Dashboard and the app. An older app ignores
`verification`; an older Dashboard shows an unknown status for a superseded attempt only.
Rollback: the three `down()`s (identity rows marked `superseded` go back to `needs_resubmission`).
`docs/Technical Spec/dahab-spec-part3-logic.md` keeps its worked examples (they use ±15) with a
"Changed by" note.
