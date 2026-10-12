# Spec 023 — First-sale advance, customer-side codes and referral

**Status**: DRAFT for the owner's confirmation · **Repos**: Backend, Dashboard, Customer app · **Priority**: after 020; independent of 021, 022 · **Money**: this spec moves money — Finance must sign off Story 1's ledger before it is switched on

## A correction to the earlier audit

The audit said the first-sale advance had "no definition". That was wrong: **Technical Spec Part 3 §6 defines it in full** (eligibility, cap, ledger at the advance, reconciliation at the buyer's payment, the no-pay case). The **percentage-off codes** (WELCOME 50 %, EID25) are defined in the *Dashboard design* (`docs/dahab-admin-dashboard.html`, page `p-promos`: the *New code* form and the code table) but not in the Technical Spec: no ledger entry, no tax treatment, no stacking rules; spec 019 limited codes to market makers by the owner's decision Q5. **Referral** is defined nowhere but the app prototype. This spec therefore has one fully defined money story, two Dashboard pages that must match the design exactly, and two stories waiting on decisions.

## What exists today

- Ledger kind `first_sale_payout`; setting `payout.first_sale_cap_egp` (100,000); `promo_code.kind = first_sale` in the schema; the market-maker codes and approvals of spec 019.
- The sell form shows a red-flagged promo card; its lookup runs against a fake list of five codes (`mockPromoCodes`) and applies a "% off the commission" label with no effect anywhere.
- A red-flagged MOCK *Invite a friend* screen with a sample code and figures.

## Story 1 — The first-sale advance (defined, Part 3 §6)

1. **Eligibility (all must hold)**: category gold only; the seller's first gold sale (no earlier completed gold order and no earlier `first_sale_payout`); gated on an active `first_sale` promo code applied to the listing **or** an explicit staff enablement; never automatic.
2. **At the inspection that passes** (or at `awaiting_balance` after an approved price change): the advance is `min(provisional proceeds on the IGI-measured weight, payout.first_sale_cap_egp)`; one balanced entry `external_equity −advance` / seller available `+advance`, kind `first_sale_payout`; a price adjustment waiting for the buyer's decision advances nothing yet.
3. **At the buyer's payment** the seller's settlement leg is reduced by the advance and Dahab's front is recovered (`external_equity +advance`); a final amount below the advance is Dahab's incentive cost and is never clawed back from the seller's wallet.
4. **If the buyer never pays** the piece returns to the seller, who keeps the advance; the advance is written off against `external_equity` offset by the forfeited-deposit split (Part 3 §6.5).
5. Staff create and switch first-sale codes (`promo.manage`) and may enable the advance for one seller; every enablement and advance is audited; the Dashboard shows the exposure (advances outstanding × cap).

## Story 2 — The customer enters a code on the sell form

The card becomes real **for first-sale codes only**: a code is checked against the Backend (valid, active, for this account or open to all **[D23-1: answered]**, first gold sale), the listing records the code, and the screen says plainly what it unlocks ("paid as soon as IGI confirms your piece, up to {cap}"). Nothing else is promised. The mock repository and the fake codes go.

## Story 3 — Percentage-off-commission codes (design only, **[D23-2: built]**)

Built (owner, 2026-10-12: the design wins where nothing is written; its numbers are settings). The Dashboard design defines the *form* and the code table; the Backend posts the reduced commission on the settlement: the percentage of the code off the commission, never below the minimum commission (a setting, 200 EGP by default), VAT on the commission actually charged, one code per sale, the lowest single commission when a free relist (spec 018) or a market-maker waiver (spec 019) also applies. How often an "Anyone" code may be used is the code's own *Times they can use it*. Finance confirms the VAT treatment with D23-5 before the codes are switched on.

## Story 4 — Referral: Invite a friend (prototype only, **[D23-3: built]**)

Built (owner, 2026-10-12). The prototype text is the definition: "She pays a lower commission on her first sale. You pay a lower one on your next… the reward lands as commission credit after your friend completes her first sale, not when she signs up." The reward for the friend and for the inviter, the number of sales it applies to, the usage cap, the invitation limit per account, the per-account and per-device limits and the expiry of the credit are **settings** (`referral.*`) with proposed defaults for Finance. A *commission credit* is not wallet money: it is a balance that only reduces a later commission, and it shares one mechanism with the percentage-off codes of Story 3.

## Story 5 — The *Promo codes* page, exactly as designed (`p-promos`)

The page is rebuilt to match the design; the current page (code, account, cap, uses) is a simplification and is replaced.

**Page.** Lead text: "Codes are checked on the server, never in the app. A code tied to an account works only for that account, whatever device it is typed on." Panel *Codes* with the button **Add a code**. A note under the table: "Turning a code off stops it instantly. Anything already in progress carries on as normal."

**Table columns, in this order**: *Code* (mono, bold) · *What it does* · *Tied to* · *Conditions* · *Used this month* · *Status* · actions.

| Column | Content (as in the design's rows) |
|---|---|
| What it does | the commission outcome in words (*No commission*, *50% off commission*, or the unchanged commission) and, below it in small text, *Spread goes to them* when the buy-and-sell difference is given |
| Tied to | the account's name and phone for a one-account code; *Everyone* for an open code, with *First sale only* in small text where it applies |
| Conditions | *N days old* (the piece must have been listed that long) and *Admin approved* in small text; *Once per ID* / *Once a month* for the use limit; *Ends 12 Sep* for an end date |
| Used this month | for a capped code the value as `312,400 of 500,000` with a progress bar (EGP of transaction value against the monthly cap); for an uncapped code the count, `64 uses` |
| Status | tag *On* / *Off* |
| Actions | **Uses** (opens the code's use log, as the design goes to the audit view) and **Turn off**; for an off code **Turn on** only. Toasts: "Turned off and logged." / "Turned on and logged." |

**New code panel** (opens from *Add a code*, title *New code*, button **Create the code**, success "Code created and logged against your name."). Fields, labels and helper texts exactly:

| Field | Options / helper |
|---|---|
| The code itself | placeholder `MM-KARIM`; "Letters, numbers and dashes. People will type this." |
| Who can use it | *One account only* · *Anyone* |
| Tied to | placeholder "Account number or phone"; "It will not work from any other account, on any device" (shown for *One account only*) |
| Times they can use it | *No limit* · *Once* · *Once a month* |
| Commission | *Waive it entirely* · *Reduce by a percentage* · *Leave it as it is* |
| Reduce by | placeholder `50`; "Percent, if you chose reduce" |
| Buy and sell difference | *Dahab keeps it* · *Give it to them* |
| Piece must be listed for | default `7`; "Days. Zero means no wait" |
| Piece must be admin approved | *Yes* · *No* |
| Monthly cap | placeholder `500,000`; "EGP of transaction value" |
| Starts / Ends | dates; "Leave empty for no end date" |

A closing note: "The minimum commission of 200 EGP is never broken, whatever this code says." This is enforced by the settlement (`commission.minimum_egp`), not only printed.

**What the Backend needs for this page** (columns the schema §15 does not have): the commission mode and percent, the use limit (`unlimited | once | monthly`), the minimum listed days, whether admin approval is required, the start and end dates, the *first sale only* and *once per ID* flags, and the open-to-anyone kind. They are added in one migration; rows written by spec 019 keep their meaning (a market-maker code is *waive* + *give the spread*, 7 days, approval required). *Used this month* needs the use rows' `amount_egp` (spec 019 6B already stores it).

## Story 6 — The *Market maker approvals* page, exactly as designed (`p-mm`)

**Page.** Lead: "Only pieces older than the waiting period appear here, so a market maker never gets ahead of an ordinary buyer. Approving is a judgement about price, not about the piece." An info note: "The waiting period is **7 days** and the approval requirement is on. Both are set in *commission rates*. This screen is yours, not the market maker's. They see approved pieces marked in the app, with a filter to show only those." The figures are read from settings and *commission rates* is a link to that page, where both are editable (the approval requirement is a new boolean setting, `marketmaker.require_approval`, default on).

**Panel *Waiting for approval***, columns *Piece · Listed · Asking · Against the guide* · actions.

| Column | Content |
|---|---|
| Piece | title with karat or grade; below it weight, **views** and requests ("30.00 g · 127 views, no requests"; "VS1, G · 44 views"); a line of links to the piece's files: *Video*, *Original invoice*, *N photos*, or the grey *No video*; for a stone also *GIA certificate*. Each opens the staff view of that file (audited) |
| Listed | days since listing ("9 days") |
| Asking | the asking amount in EGP, mono |
| Against the guide | a coloured comparison: *Making charge 21% of gold* (green) for gold; *30% above Rapaport* (red) for a stone above the guide |
| Actions | **Price is sound** (approves; "Approved and logged against your name.") and **Leave** ("Left as it is.") |

**Panel *What this costs*** with the sub-heading *This month*: *Pieces bought by market makers* · *Commission given up* · *Spread given up* · **Total cost** (the large figure), and the note "This is what you pay for liquidity. Sellers get their money faster and the market looks active. Watch it against the sell-through rate to know whether it is working."

**What the Backend needs for this page**
- **Views** on a listing are not measured today (spec 019 R13). The design shows them, so a view counter is added: one count per account or device per piece per day, shown to staff only, never revealing who viewed. **[D23-6: answered]**
- **Against the guide**: for gold, the making charge per gram as a percentage of the rate per gram, which is computable today. For stones the comparison is against **Rapaport**, an external integration left out of this round: until it exists the cell says *No guide yet* in grey.
- **Leave** changes nothing about the piece; it stays in the queue and the action is logged. **[D23-7: answered]**
- **Commission given up** and **Spread given up** are recorded on the order at settlement as what an ordinary sale of the same piece would have paid (the calculator run with the waiver off, and the spread credited to the dealer), so the monthly figures are sums of stored values, not estimates. *Total cost* is their sum. Orders settled before this spec have none and show as not recorded.

## Surfaces

| Repo | Change |
|---|---|
| Backend | the advance in the inspection and settlement steps (Part 3 §6.3–6.5), seller eligibility check, the code recorded on the listing, staff enablement, advance exposure read, error codes, a sweep or hook for the write-off, Postman, OpenAPI; later the credit tables for Stories 3–4 |
| Dashboard | **Promo codes and Market maker approvals rebuilt to the design (Stories 5-6)**; the first-sale kind and its cap; an enable-advance action on the Customer file; an exposure panel; the waiting period and the approval requirement editable on *Commission rates* |
| Customer app | the sell card talks to the Backend and explains the advance; the seller's order screen shows "Advance paid"; the wallet already knows the `first_sale_payout` kind; the Invite screen is built or hidden per D23-3 |

## Depends on / used by

Depends on the spec 019 code tables and the settlement of specs 012 and 016 (the invoice reconciliation must stay balanced with an advance in the entry). Spec 022 (price change) must not alter a price after an advance was paid on it **[OPEN D23-4: confirm]**.

## Owner's answers (2026-10-12)

Two rules from the owner, which settle every open point below:

1. **The design wins where nothing else is written.** Where the Technical Spec is silent, the Dashboard design (`p-promos`, `p-mm`) and the app prototype are the definition.
2. **The design's numbers are examples, not requirements.** Every figure that the design shows (50 %, 7 days, 200 EGP, a monthly cap, a reward) is a **setting** an authorised staff member can change, with the design's figure as the default. Any figure nobody has given is also a setting, never a constant in code. No business number is hard-coded.

Consequences:

- **D23-2 is built, not deferred.** The percentage-off-commission codes work end to end. The commission is reduced by the code's percentage, never below the minimum commission (a setting; 200 EGP in the design); VAT follows the commission actually charged; one code per sale; where more than one waiver could apply (a code, a free relist, a market-maker waiver) the customer gets the single lowest commission, not a sum. The "Not available yet" options of Story 3 and `promo_kind_not_available` are removed.
- **D23-3 is built, not deferred.** The referral uses the same *commission credit* mechanism as D23-2. The reward for the friend, the reward for the inviter, how many sales it applies to, the usage cap, the invitation limit per account, and the expiry are all settings (`referral.*`), each with a proposed default for Finance to confirm.
- **Abuse limits** that the design does not give (one reward per friend, per-account and per-device limits) are settings as well, with conservative defaults.
- **VAT on a reduced commission and the credit's accounting** are still for Finance to confirm with D23-5 before the codes and the credit are switched on for customers; the build does not wait for it.

## Decisions

| ID | Question | Answer |
|---|---|---|
| D23-1 | Is a first-sale code tied to one account or open to any first-time seller? | Both, as the design's *Tied to* column: one account, or *Everyone* with *First sale only*. Default for a new code: open to any first-time seller, once each. |
| D23-2 | Percentage-off-commission codes | **Built** (see above); percentages, caps and dates are per code, the minimum commission is a setting. |
| D23-3 | Referral | **Built** on the commission-credit mechanism; every figure is a `referral.*` setting. |
| D23-4 | May the price of a piece change after its advance was paid? | No (`listing_price_locked()` of spec 022 is extended). |
| D23-5 | **Finance** to approve Story 1's ledger, the exposure ceiling, and the write-off rule | Open: before switching on. The ceiling and the write-off rule are settings. |
| D23-6 | Count views of a piece? | Yes: one per account or device per piece per day, shown to staff only. |
| D23-7 | What does **Leave** do? | Nothing to the piece; the action is logged; the piece stays in the queue. |
| D23-8 | The open-to-anyone options in the form | All shown and working as in the design (follows D23-2). |
| D23-9 | Is *Once per ID* per national ID or per account? | Per national ID, as the design says. |

## Mock removed

`MockContentRepository.promo()`, `mockPromoCodes` and the red flag on the sell card (Stories 1-2); the simplified Promo codes and Market maker pages are replaced, not extended; the Invite screen only if D23-3 defines it.

## Planning notes (2026-10-12)

The owner's answers above supersede these earlier sentences, which are kept as history: Story 3's "The Backend refuses these kinds (`promo_kind_not_available`)" and "unbuilt options disabled" (now built, the code removed); Story 4's "Not built unless the owner defines it" (now built); the **Mock removed** line's "only if D23-3 defines it" (it does); Story 1.5's "exposure (advances outstanding × cap)" (the Dashboard shows the exact sum of outstanding advances against the ceiling setting).

Gaps the answers do not close are planning decisions **P23-1…11** in `research.md` R31, each with a recommendation. Story-to-task map: Story 5 → T025–T031, T068–T069, T074; Stories 3 and 2 → T032–T042, T078–T080; Story 1 → T043–T054, T072–T073, T075, T080–T081; Story 4 → T055–T059, T082; Story 6 → T060–T066, T070–T071.

