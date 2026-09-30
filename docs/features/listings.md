# Listings — selling a piece and browsing the market

> File: `docs/features/listings.md` · Branch: `feature/listings` in `dahab-backend` and `dahab-dashboard` (the Customer App has no repository)
> Status: in progress · Date: 2026-09-30

## Goal

A verified customer lists a piece for sale; Dahab staff review it before it goes live; anyone can
browse the live pieces without an account. Spec Kit artefacts: [`specs/010-listings/`](../../specs/010-listings/)
(spec with the clarifications, plan, research R1–R17, data model, contract, tasks).

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES
Dashboard:    YES
Customer App: YES
Auth:         YES (small — optional customer token on the public market; a read-only `market` DB scope)
Permissions:  YES
```

## Backend Impact

- Enums `ListingState`, `ListingMediaKind`, `ListingDecision`; `UploadPurpose` +4; `StaffPermission` +3;
  `AuditEvent` +4; `AuditCategory` + `listings`.
- Models `Listing`, `ListingMedia`, `ListingBranchOption`, `ListingOwnershipDeclaration`,
  `ListingStateChange`, `LegalDocument`, `AgreementAcceptance`.
- `app/Actions/Listings/*`: every writer and reader. State changes only through `MovesListing`
  (row lock → allowed move → history row). Prices only through `ListingPricer` over the spec 005 calculator.
- `DatabaseActor` scope `market` + middleware `db.market` (`UseMarketScope`).
- `IdentityDocumentStorage::storeChunkedAt()` / `readChunked()`: listing media is encrypted and served in chunks.
- `ListingDecisionNotification` (SMS + email after commit).
- `SuspendCustomerAction` / `ReinstateCustomerAction` hold and restore the customer's live listings.

## Database Impact

Migrations `2026_10_03_000010_create_legal_documents` and `2026_10_03_000020_create_listings`:
`legal_document`, `agreement_acceptance` (append-only, forced RLS, seed `ownership_declaration` v1);
type `listing_state` (with `rejected`); `listing_transition` (allowed moves, with `in_review → rejected`);
`listing` (+ `state_changed_at`, four CHECKs), `listing_media` (+ `mime`, `position`), `listing_branch_option`,
`listing_ownership_declaration`, `listing_queue_seq`, `listing_state_change` (history); triggers `trg_listing_guard`
and the deferred `trg_listing_change_recorded` (SQLSTATE `DH004`); forced RLS on all of them with the
`market` read policies. Local seed: `LocalListingSeeder`.

## API Changes

All non-breaking additions unless noted. Contract: [`specs/010-listings/contracts/listings-api.md`](../../specs/010-listings/contracts/listings-api.md).

| Surface | Endpoints |
|---|---|
| Public (new) | `GET /market/listings`, `/market/listings/{id}`, `/market/listings/{id}/media/{media}`; `GET /reference/karats`, `/reference/piece-types`, `/reference/branches`, `/reference/legal-documents/{code}` |
| Customer | `GET/POST /customer/me/listings`, `GET/PATCH /customer/me/listings/{id}`, `POST …/submit`, `POST …/withdraw`, `GET …/media/{media}`; `POST /customer/me/uploads` gains four purposes |
| Dashboard | `GET /dashboard/listings`, `/dashboard/listings/{id}`, `…/media/{media}`; `POST …/approve`, `…/request-changes`, `…/reject`, `…/takedown`; suspend / reinstate gain a side effect |

Potentially breaking for the Dashboard only: the permission union grows by three strings; the audit
category list grows by `listings` and four events.

## Dashboard Impact

`src/pages/listings/index.vue` replaces the placeholder: state chips with counts (Waiting, Changes asked,
Approved today, Rejected, Live), the queue, and the review panel with *Approve and publish*, *Ask for changes*,
*Reject* and — for a live listing — *Take down*. Every confirmation is a `DModal`. Types `src/types/listing.ts`,
service, composable, endpoints, nav item gated on any listing permission.

## Customer App Impact

*Sell your piece* (real uploads, reference data, a branch choice, the declaration text), *My listings*,
*Browse* and the piece page run on the API. Still mock or hidden: views, saved pieces, share, report,
promo codes, *Change making charge*, Accept/Decline, the step-1 "You receive" preview (prototype maths).
Browse search and any chip without an API filter work on the device.

## Authentication / Authorization

- Public routes need no token. A customer access token on `/market/*` is optional and only sets `is_mine`.
- Seller reads: `customer.gate:verified`. Seller writes and listing uploads: `customer.gate:trade` + `Idempotency-Key`.
- A customer reaches only their own listings (forced RLS, owner = `seller_id`).
- **Public read — recorded deviation from Part 1 §5.3 (product-owner decision):** no database view and no
  separate role. Public market → read-only `market` RLS scope → `listing` → public response Resource, with
  `MarketLeakTest` and `MarketScopeTest` failing the build on any seller or private field.

## Permissions

`listing.review` (approve, reject), `listing.request_changes`, `listing.takedown` — seeded to `ceo`, `coo`,
`operations`; editable from the Dashboard. Reads open with any of the three.

## Validation

Backend rules are authoritative: per-category fields (gold: karat, weight, making charge; diamond: asking price;
gold with diamond: karat, weight, asking price), enabled piece type / karat / branches, media limits (6 photos at
8 MB, one 50 MB video, one invoice, one certificate), and to submit: 2 photos (gold) or 3 (stones) and a
40–2,000 character description. The apps mirror these for UX only.

## Error Handling

`illegal_listing_transition` 409 · `listing_not_editable` 409 · `seller_suspended` 409 · `karat_disabled` 409 ·
`gold_needs_karat_weight` 422 · `branch_options_required` 422 · `ownership_declaration_required` 422 ·
`photo_required` 422 · `upload_token_invalid` 422 (existing) · the gate and idempotency codes.

## UI States

Each list has loading, empty, error-with-retry and permission-denied states; each action shows its
in-flight state and maps the codes above to plain text.

## Testing

Pest feature tests through HTTP (`tests/Feature/Listing`, `tests/Feature/Market`): schema and guard triggers,
every endpoint's happy and refusal paths, idempotent replay, audit, notifications, concurrency, isolation,
the market leak test and a real 50 MB video upload and playback within a memory bound. Dashboard:
`npm run type-check`, `npm run lint`, `npm run build`. Customer App: `flutter analyze`, `flutter test`,
`flutter build web --release`. Manual: [`specs/010-listings/quickstart.md`](../../specs/010-listings/quickstart.md).

## Breaking Changes

None. Two enum growths for the Dashboard (above) are handled in the same feature.

## Migration / Compatibility

Deploy the Backend first (migrations, then code), then the Dashboard, then the Customer App. PHP
`upload_max_filesize = 64M`, `post_max_size = 70M` and nginx `client_max_body_size 70m` are required for video.
Rolling back the migrations drops listings and acceptances; no money is involved.

## Follow-ups

Buy requests (`reserved`, queue, withdraw from reserved); category controls; market makers; object storage and
range requests for video; clean-up of unclaimed uploads; structured stone data; legal documents management.
