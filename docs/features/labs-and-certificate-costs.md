# Labs and certificate costs

> File: `docs/features/labs-and-certificate-costs.md` · Branch: `feature/labs-and-certificate-costs` in each affected repo
> Status: built, not committed · Date: 2026-10-31

## Goal

Dahab is moving from one inspection lab (IGI) to labs the admin manages. Each lab sets what its
certificates and its gold inspection cost. A seller says which certificate they hold, then chooses one
lab and one of its branches, so they know the cost before they list. Costs come out of the seller's
proceeds and reach the invoice, the statements and what Dahab owes each lab.

**Non-goals.** Recording payments to a lab (later phase). Replacing "IGI" in the app's texts: IGI stays
the main lab, and those texts must change before a second lab goes live. Charging anyone on a sale that
does not complete.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES
Dashboard:    YES
Customer App: YES
Auth:         NO
Permissions:  NO (reuses reference.view and branches.manage)
```

## Decided rules

**Lab and branch.** A lab has a name, three certificate costs, a gold inspection cost (starts at 0) and
an enabled flag. A branch belongs to one lab and keeps its address, hours, phone, map and closures.

**Which cost applies.** The lab is the one that inspects the piece.

| Piece | Certificate the seller holds | Cost |
|---|---|---|
| Diamond, gold with diamond | From this lab | this lab's `own` cost |
| Diamond, gold with diamond | From another listed lab, or `Another lab` | this lab's `other` cost |
| Diamond, gold with diamond | None | this lab's `none` cost |
| Gold only | Not asked | this lab's gold cost |
| Free relist (any category) | Not asked | none |

Gold with diamond pays the certificate cost only, never the gold cost.

**Showing it.** A gold cost of 0 never appears (sell form, quote, order, invoice, statement) and posts
nothing. A certificate cost of 0 shows as "Free".

**When it is fixed and taken.** Fixed on the order at acceptance. Taken from the seller's proceeds at
pay-balance. The buyer's total, the commission and the VAT do not change. A sale that does not complete
charges nobody. A changed lab cost applies to pieces not yet accepted, and their sellers are told.

**Recommended.** Shown on the lab that issued the seller's certificate, and only that lab. `Another lab`
and `No certificate` recommend no lab.

**Wrong certificate.** At inspection, staff may record the certificate actually found. The cost moves to
that lab's current cost for the true kind, the sale continues, the change is audited, and the seller is
told before settlement. No seller approval.

**A branch or lab goes away.**
- Branch disabled: the piece stays on the market. At acceptance the seller picks another enabled branch
  of the same lab, at the same cost.
- Lab disabled, or none of its branches enabled: a live piece leaves the market and takes no new
  requests until the seller picks another lab and confirms the new cost. It returns by itself if the lab
  comes back. A piece already `reserved` keeps its line; the seller picks the lab at acceptance, which
  changes only the seller's own cost.

## Database Impact

- **`lab`** (new): `lab_id`, `name_en`, `name_ar`, `certificate_own_cost_egp`,
  `certificate_other_cost_egp`, `certificate_none_cost_egp`, `gold_inspection_cost_egp` (default 0; all
  `>= 0`), `is_enabled`. Never deleted, only disabled.
- **`branch`**: `lab_id` NOT NULL FK.
- **`listing`**: `lab_id` (FK, the lab that inspects it; a column so market lists need no join through the branch), `certificate_lab_id` (nullable FK), `certificate_other_lab_name` (nullable, required
  with `other_lab`). `certificate_kind` values become `lab | other_lab | none`.
- **`"order"`**: `lab_id` (FK), `gold_inspection_cost` (nullable), `certificate_kind_found` (nullable).
  `certificate_cost` stays.
- **`inspection_result`**: `certificate_kind_found`, `certificate_lab_id_found` (both nullable).
- **Data migration, in one reversible migration:**
  1. Create IGI with the **current values** of the three `certificate.*` settings and a gold cost of 0.
  2. Point every branch and every existing order's `lab_id` at IGI.
  3. Change `certificate_kind = igi` to `lab` with `certificate_lab_id` = IGI.
  4. Leave posted entries and `certificate_cost` on existing orders untouched.
- The `listing_branch_option` table stays. New listings hold exactly one row; old listings keep theirs.
- Check the listing guards (`listing_guard()`, DH023) allow the new columns on the states they need.

The internal account stays one (`igi_certificates`), shown as "Lab fees". `account_kind` is an enum, so a
ledger account per lab would mean a schema change for each new lab. What Dahab owes each lab is the sum
over settled orders by `order.lab_id`, and it must reconcile with that account's balance. **Existing
settled orders count towards IGI.**

## Backend Impact

- **New:** `Lab` model and factory; `CreateLabAction`, `UpdateLabAction` (audited; a changed cost
  notifies sellers of affected pieces after commit); `ChooseListingLabAction`.
- **`CertificateFee` → lab-aware resolver:** takes the category, the lab and the seller's certificate,
  and returns the two costs. Used by `ListingQuote`, `ListingPricer`, `QuoteAction`,
  `PreviewCommissionAction`, the listing resources and acceptance. A legacy stone listing with a null
  kind still pays nothing. So does a free relist (`isFreeRelist()`), including gold.
- **`CreateListingAction`, `UpdateListingAction`:** validate one enabled branch and the certificate
  fields.
- **`AcceptBuyRequestAction`:** `branch_id` becomes optional.
  - Listing with one enabled branch: that branch is used and a different one is refused.
  - Listing with several options (old): the seller must pick, as today (`branch_not_in_options`).
  - Listing's only branch disabled: the seller picks an enabled branch of the same lab.
  - Lab disabled: the seller also sends the new lab's `branch_id`, and the fee is computed on it.
  - Store `lab_id`, `certificate_cost` and `gold_inspection_cost`.
- **`ChangeOrderBranchAction`:** the listing now holds one branch, so staff may move an order to any
  enabled branch **of the same lab**. The cost does not change. Moving to another lab is not offered.
- **`RecordInspectionResultAction`:** optional `certificate_kind_found` + `certificate_lab_id_found`,
  also allowed on a correction (same window: before any decision or payment). Updates the order's cost
  and the listing's kind so a returned piece relists truthfully. Refuses a cost that would leave proceeds
  at or below zero, since settlement refuses that anyway. A new audit event and a seller notice.
- **`OrderSettlement`, `SettlementFigures`:** the fee is `certificate_cost + gold_inspection_cost`, kept
  as two figures, posted to the same account.
- **`InvoiceLines`, invoice PDF:** the lab's name, and the gold cost on its own row only when above 0.
  Still informational, not a Dahab charge, no VAT.
- **`BuildWalletStatementAction`, `ExportWalletStatementAction`:** add the gold cost and a per-lab payable
  breakdown.
- **Market:** leaving the market is derived, not a state. The market reads and buy-request creation
  exclude a listing whose lab cannot take it, and the seller's listing gets `needs_lab_choice`. No
  `listing_state` change, because `suspended_hold` is restored per customer on reinstatement.
- **`FreeRelistAction`:** keeps copying the branch. When it is disabled, it uses the rule above.
- **Remove** the three `certificate.*` settings from `SettingKey` after the migration reads them.

Notification texts go through `app/Notifications/Templates`, with the golden fixture updated.

## API Changes

| Method + path | Change | Class |
|---|---|---|
| `GET /dashboard/labs` | New; `reference.view` | Non-breaking |
| `POST /dashboard/labs`, `PATCH /dashboard/labs/{lab}` | New; `branches.manage`, audited | Non-breaking |
| `POST`, `PATCH /dashboard/branches*` | `lab_id` required on create, editable while the branch has no open order | Potentially breaking |
| `GET /reference/labs` | New, public: enabled labs, their costs and enabled branches with hours | Non-breaking |
| `GET /reference/branches` | Adds `lab_id` | Non-breaking |
| `GET /reference/certificate-costs` | Kept, answers with IGI's costs until no client reads it | Non-breaking |
| `GET /reference/quote`, `POST /customer/me/commission-preview` | Add `lab_id`, `certificate_lab_id`; add `gold_inspection_fee` | Non-breaking |
| `POST`, `PATCH /customer/me/listings*` | `branch_option_ids` exactly one; `certificate_lab_id`, `certificate_other_lab_name`; `certificate_kind` gains `lab` | Potentially breaking |
| `POST /customer/me/listings/{id}/lab` | New: choose the lab and branch again, `idempotent` | Non-breaking |
| Listing resources | Add `lab`, `certificate_lab`, `gold_inspection_fee`, `needs_lab_choice`; `certificate_kind` `igi` → `lab` | Potentially breaking |
| `/customer/me/listings/{id}/accept` | `branch_id` becomes optional | Non-breaking |
| `POST /dashboard/orders/{id}/change-branch` | Same-lab rule | Potentially breaking |
| Inspection result POST | Adds the two `*_found` fields | Non-breaking |
| Order and invoice resources | Add `lab`, `gold_inspection_fee` | Non-breaking |

New `code`s: `lab_not_available` 409, `certificate_lab_required` 422, `other_lab_name_required` 422,
`branch_not_in_lab` 422, `branch_in_use` 409, `certificate_cost_too_high` 422. During the transition, input `certificate_kind=igi` is read as `lab` + IGI.

Update the `#[OA]` attributes, regenerate OpenAPI, and update the Postman collection.

## Dashboard Impact

- Branches page → **Labs and branches**: labs with their four costs and enabled switch, branches grouped
  under each lab, `lab` on the branch form.
- Rates (Platform settings): the three certificate rows go; a link points to the new page.
- `InspectionResultModal`: shows what the seller stated and lets staff record what was found. It shows no
  amount, like the rest of this form; the seller is told the new cost.
- `ChangeBranchModal`: same-lab branches.
- Order detail, invoice detail, `StatementTable`, `StatementSummary`: lab name, gold cost when above 0,
  and what Dahab owes per lab.
- `ListingReviewPanel`: the certificate's lab or typed name, and the chosen lab and branch.
- Update `types` (`order.ts`, `listing.ts`: the `certificateKind` union), `services`, `endpoints.ts` and
  mocks.

## Customer App Impact

- **Sell step 1:** certificate buttons built from `/reference/labs` plus `Another lab` (a required name)
  and `No certificate`. Below them, in the same page, labs with their cost and `recommended`; each lab's
  branches with address and hours in small type; the seller chooses one branch. Gold skips the
  certificate buttons and shows a cost only when above 0.
- **Sell step 3:** the branch checklist and "The buyer picks one of them" go. "Inspection cost: Paid by
  Dahab" is hidden when a cost applies. The other IGI texts stay.
- **Estimate rows:** the lab's name instead of "IGI certificate" when the lab is not IGI.
- **Accept:** no picker when the branch is settled; a picker only for the disabled-branch and
  disabled-lab cases, and for old listings.
- **My listings:** a piece with `needs_lab_choice` asks the seller to pick and shows the new cost.
- **Order, invoice:** lab name and gold cost when above 0.
- **Consumers of the old values:** `sell_draft.dart` (`cert`, the `igi` mapping), `stone_rows.dart` (the
  certificate label on the piece page and the order: the lab's name), `models/reference.dart`
  (`CertificateCosts`), `market_api.dart`.
- New keys in the text catalogue; Arabic is AR-DRAFT. Update the fake backend in `test/flows_test.dart`.

## Authentication / Authorization

No new guards. Lab reads and writes follow the branch endpoints. `/reference/labs` is public like
`/reference/branches`; the costs are already public through `/reference/certificate-costs`.

## Permissions

Reused: `reference.view`, `branches.manage`, `inspection.enter`, `order.change_branch`. No seeder change.

## Validation

- Costs: non-negative, 2 decimals at most.
- `certificate_kind` only on diamond and gold with diamond; `lab` needs an enabled `certificate_lab_id`;
  `other_lab` needs a typed name (2–100 characters).
- Exactly one enabled branch, of an enabled lab, on a new listing.
- Certificate upload stays required unless `none`, as today.
- A lab cannot be disabled with no warning: the response gives the count of live pieces affected.

The typed lab name is staff-only: never in market resources.

## Error Handling

The app shows `lab_not_available` by reloading the labs and asking again, like the stale-branch case in
`sell3_screen.dart`. The Dashboard shows the other codes on their fields.

## UI States

- **App:** no lab open → "No branch is open for inspections right now", with sending blocked. Loading and
  errors use `AsyncView`.
- **Dashboard:** loading, empty and error as on Branches; forms hidden without `branches.manage`.

## Testing

Pest, targeted:
- Every row of the cost table, with own vs other decided by the inspecting lab.
- A gold cost of 0 posts nothing and appears nowhere; above 0 it is posted and shown; never on gold with
  diamond; never on a free relist.
- The cost stays locked at acceptance after a lab edit.
- Certificate correction: cost, audit, notice, window, and the refusal when proceeds would fall to zero.
- Disabled branch and disabled lab at acceptance; the derived market exclusion and its return.
- The same-lab staff move.
- Old multi-branch listings still accept as today.
- Migration: IGI gets the live setting values, and a settlement after it matches one before.
- Ledger balances, and the per-lab payable equals the account balance.

Then `pint --test` and `swagger:generate`; Dashboard `type-check`, `lint`, `build`; Flutter `analyze`,
`test`, `build web`.

## Breaking Changes

No URL or error `code` changes. The `certificate_kind` value `igi` → `lab` and the one-branch rule are
potentially breaking for the sell form and the Dashboard; both consumers ship in this change, and input
`igi` stays accepted through the transition. Per the project's table this stays on `/api/v1`.

## Migration / Compatibility

Deploy the Backend first, then the Dashboard and the app together. An app still open on the old version
can send several branches and gets `validation_failed` with the existing "show the current ones" path.
Rollback: the migration restores the settings and `igi` values, but only before any non-IGI lab is used.

**Before a second lab goes live:** replace the IGI texts in the app, the notifications and the
`CompensationReason`, `OrderState` and `SeedRole` labels, and agree that lab's commercial terms.

## Later phases

- Recording payments to a lab, which reduce what Dahab owes it.
- Rules for who pays when a piece was inspected but the sale did not complete, once the labs' contracts
  say what they charge.
- A second inspection on a free relist, if a lab charges for it.
