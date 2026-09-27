# Feature Specification: Pricing — Settings, Gold Prices, Price Feed, Price Math

**Feature Branch**: `feature/pricing` (backend and dashboard; the Customer App has no repository yet)

**Created**: 2026-09-27

**Status**: Draft

**Input**: User description: "Pricing (feature 005): settings + setting history with the schema seed; a gold price record fed by the gold price provider (identity pending confirmation) and by hand when the feed is down; every karat has two prices, buy and sell (product-owner input 2026-09-27, confirmed by the provider's feed which returns a bid and an ask); manual price with reason and a confirmation above the deviation setting; per-karat buy/sell adjustments, fixed or percentage; the Part 3 §2 price math as one tested calculator; Dashboard Gold pricing and Commission rates screens and the Karats 'Price today' column. Out of scope: Switches, customer price endpoint and Customer App wiring, price locks on orders, founder alerts."

## Context

- **Sources**:
  - `docs/Database schema/01_schema_core.sql` §3 (settings, history, seed values; "the app reads the current value, never hard-coded")
  - Technical Spec Part 3 §2 (the pricing inputs, the two sides, spread, commission, VAT, seller proceeds) — **amended by this feature** for the two-price feed (see Clarifications)
  - Part 2 §10 (`PATCH /admin/settings/{key}`, `POST /admin/gold-price/manual`, confirmation above the deviation)
  - Part 1 §4.2 (permission seed: "Change commission or spread rates", "Enter a gold price manually", "Set the gold price correction" = CEO + Finance)
  - `docs/Database schema/00_schema_mysql.sql` `manual_gold_price` (reason, previous price, deviation, confirmation) — rules only; the platform stays on PostgreSQL
  - The previous platform's price-feed command (shared by the product owner 2026-09-27): the provider authenticates with a username and password, then returns the Egyptian gold price as **`bidPrice` and `askPrice` for 24K**; karat prices follow by purity; a per-karat, per-side adjustment (fixed amount or percentage) is applied on top. Technical Spec Part 4 (integrations) does not exist yet; this feature writes the price-feed part of it.
  - Dashboard design reference screens "Gold pricing", "Commission rates" and "Karats"
  - `docs/dahabctoblueprint.md` is not a reference.
- **How a customer's gold price is built** (confirmed with the product owner 2026-09-27):
  1. the **market's two 24K prices per gram**: the **bid** (what the market pays for gold) and the **ask** (what the market sells it for) — from the price feed, or entered by hand when the feed is down;
  2. every other karat follows by purity: `karat price = 24K price × purity ÷ 0.999` (purity from the karats table);
  3. a **per-karat adjustment on each side** (a fixed EGP amount per gram, or a percentage) gives each karat's **two published prices**: **sellers get** = bid-based price adjusted by the buy-side adjustment; **buyers pay** = ask-based price adjusted by the sell-side adjustment. The difference is Dahab's spread, on gold only.
- **Why now**: every later money feature reads these numbers — listings show prices, buy requests lock them, settlement uses commission and VAT, the ledger posts them. Nothing may be a literal in code.
- **Out of scope** (decided 2026-09-27):
  - the "Switches" screen and category stop/pause controls (with listings, which they act on);
  - a customer-facing price endpoint, the in-app "manual price" banner, and replacing the Customer App's prototype maths (`lib/services/pricing.dart`, `live_rates.dart`) — with listings; **Customer App not affected by 005**;
  - price locks and per-order price snapshots (with buy requests);
  - the Rapaport matrix panel and promo codes (their own features);
  - real-time founder alerts on rate changes and manual prices (Part 1 §8.3) — a separate cross-cutting feature once OI-1.3 (the channel) is decided; this feature records every change in the audit log.

## Clarifications

### Session 2026-09-27

- Q: How do the market's two prices per karat relate to the price record? → A: The provider's feed returns a 24K **bid** and **ask**. Each price entry stores both. Other karats follow by purity (purity ÷ 0.999, from the karats table — not karat ÷ 24). Sellers get = bid-based price + buy-side adjustment; buyers pay = ask-based price + sell-side adjustment. Part 3 §2 is amended to match (it assumed one rate `R`).
- Q: What does a manual price consist of? → A: Both 24K prices, bid and ask, entered by hand (or as a percentage change from the current pair). The adjustments still apply on top.
- Q: How are the adjustments expressed? → A: Per karat and per side (buy / sell), each either a **fixed EGP amount per gram** or a **percentage**, as in the previous platform. They replace the two single `price_correction.*` settings of the schema seed; each karat is seeded with the schema values (buy side −15 EGP, sell side +15 EGP, fixed).
- Q: Is the price feed connected in this feature? → A: Yes. The feed is read every minute from the provider (credentials in the environment, never in code or docs); a manual price is accepted only while the feed is down, and the feed takes over again automatically when it recovers (design). The unit (EGP per gram of 24K) is an assumption from the previous platform, verified against the provider's staging server before release.
- Q: Who confirms a manual price above the deviation threshold? → A: Dynamic, from the Dashboard: a separate **confirm** permission (assignable to any role; seeded to CEO + Finance), and a setting `manualprice.confirmer_must_differ` (default **yes**) deciding whether the person who entered it may confirm it themselves. The threshold is `manualprice.confirm_deviation_pct`; a pending price lapses after `manualprice.pending_expiry_hours` (default 24).
- Q: How do the provider's credentials and identity get handled? → A: (1) Credentials live **only in the environment (`.env`)** — never in code, docs, tests, fixtures or Git; `.env.example` carries empty keys. The staging check runs only after the product owner puts the credentials in `.env` themselves. The provider password must be rotated with the provider, because the old one appeared in the previous platform's code and in chat. (2) The previous platform's host (`mngm.com`) is **not assumed to be Evolve**: the provider's identity is pending confirmation, and nothing in the integration's behaviour depends on the name — only on the two calls observed in the old code.
- Q: What does a manual price entry that needs confirmation answer? → A: `202 Accepted` with the pending entry and `code: manual_price_confirm_required` (not the `409` in Part 2 §10): the request is recorded as pending, so it was accepted, not refused. Part 2 §10 is updated.
- Q: Are both founders alerted in real time of a rate change or a manual price (Part 1 §8.3) in this feature? → A: No. Changes are recorded in the audit log only. Founder alerts for every sensitive action become their own feature once the channel (OI-1.3) is decided.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The live feed keeps prices current (Priority: P1)

Every minute the platform reads the 24K bid and ask from the price provider. When they changed, a new price entry takes effect at once, and every karat's two published prices follow. Staff see the feed's health (last successful reading) on the Gold pricing screen.

**Why this priority**: gold prices move all day; every quote must come from the market, not from a person, whenever the market is reachable.

**Independent Test**: With the provider answering (simulated in tests), the current prices follow its bid and ask; with it failing for longer than `pricefeed.stale_after_minutes`, the screen shows the feed as down and the last good price stays current.

**Acceptance Scenarios**:

1. **Given** the provider answers with a bid and an ask, **When** the reading differs from the current price, **Then** a new entry with source "feed" takes effect; **When** it is identical, **Then** no new entry is written, only the health timestamp.
2. **Given** the provider fails (login refused, error, timeout, unreadable answer, bid ≤ 0, ask < bid), **Then** nothing is written, the failure is logged, and the last good price stays current.
3. **Given** no successful reading for more than `pricefeed.stale_after_minutes`, **Then** the feed counts as down.
4. **Given** a manual price is current and the feed recovers, **When** the next successful reading arrives, **Then** it takes over automatically.

---

### User Story 2 - Finance sets the gold price by hand while the feed is down (Priority: P1)

While the feed is down, a Finance or CEO user enters the 24K bid and ask (or a percentage change from the current pair), sees every karat's two published prices before saving, and saves with a reason. A large jump needs a confirmation before it goes live.

**Why this priority**: the platform must keep quoting when the provider is unreachable, with a human accountable for the number.

**Independent Test**: With the feed down, as Finance, enter a bid and ask with a reason; the current prices show them with "manual", the person and the time. With the feed healthy, the same entry is refused. Enter one deviating more than the threshold; it stays pending until someone holding the confirm permission confirms it — with `manualprice.confirmer_must_differ` on, not the person who entered it.

**Acceptance Scenarios**:

1. **Given** the feed is down and a current price exists, **When** Finance enters a bid and ask within the deviation threshold with a reason, **Then** they take effect at once, recorded with source, person, reason, the previous pair and the deviation, and audited.
2. **Given** the feed is healthy, **When** a manual price is entered, **Then** it is refused ("a manual price is only accepted while the feed is down").
3. **Given** a new pair deviating more than `manualprice.confirm_deviation_pct` from the current one (on either side), **When** it is entered, **Then** it is recorded as pending and does not take effect; the answer says a confirmation is required.
4. **Given** a pending price and `manualprice.confirmer_must_differ` on, **When** a *different* staff member holding the confirm permission confirms it, **Then** it takes effect; **When** the person who entered it tries, **Then** it is refused.
5. **Given** a pending price and `manualprice.confirmer_must_differ` off, **When** the person who entered it (holding the confirm permission) confirms it, **Then** it takes effect.
6. **Given** a staff member without the confirm permission, **When** they try to confirm, **Then** it is refused.
7. **Given** no price was ever recorded, **When** the first price is entered, **Then** it takes effect without confirmation and is audited.
8. **Given** a pending price older than `manualprice.pending_expiry_hours`, superseded by a newer entry, or overtaken by a feed reading, **When** someone tries to confirm it, **Then** it is refused.
9. **Given** an ask below the bid, or a price ≤ 0, **When** it is entered, **Then** it is refused.

---

### User Story 3 - Finance changes commission, VAT and the per-karat adjustments (Priority: P1)

A Finance or CEO user changes a rate (commission on gold, on stones, the minimum, VAT) or a karat's buy- or sell-side adjustment (type fixed or percentage, and value), with a reason. The change applies to every new calculation immediately and is kept with the old and new value. For adjustments, the screen shows the resulting "sellers get / buyers pay" and the published difference per gram before saving.

**Why this priority**: the rates and adjustments decide what Dahab earns and what customers are charged; they must be data, changed by the right people, with a trail.

**Independent Test**: As Finance, change 21K's buy-side adjustment from −15 fixed to −1.5 % with a reason; the next 21K sellers-get price uses it; the history shows the change, who and why. As the COO, the same change is refused.

**Acceptance Scenarios**:

1. **Given** Finance, **When** they change a rate or an adjustment with a reason, **Then** the current value changes, a history row records old and new value, person and time, and the change is audited.
2. **Given** no reason, **When** a change is sent, **Then** it is refused with "reason required".
3. **Given** a value outside the allowed range (a negative commission; an adjustment that would put a karat's buyers-pay price below its sellers-get price, or a price at or below zero, at the current market price), **When** it is sent, **Then** it is refused.
4. **Given** a staff member without the rates permission (for example the COO — money-adjacent, Part 1 §4.2), **When** they change a rate or an adjustment, **Then** it is refused.

---

### User Story 4 - One calculator gives every price the same way (Priority: P1)

Given a piece (category, karat, weight, making charge per gram, or an asking price for stones), the current prices, adjustments and settings, the Backend computes each karat's two published prices and, for the piece, the buyer total, the seller gross, the spread, the commission, the VAT and the seller's proceeds as Technical Spec Part 3 §2 (as amended) defines — one implementation every later feature uses.

**Why this priority**: prices shown, locked and settled must never disagree; a single calculator is the only way to guarantee it.

**Independent Test**: The worked examples of Part 3 §2 and §3, restated with a bid/ask pair and fixed adjustments that reproduce the documented rates, produce the documented figures to the piastre; added examples cover percentage adjustments, pure diamond, gold with diamond, a commission at the minimum and a market-maker waiver.

**Acceptance Scenarios**:

1. **Given** a gold piece, **Then** the seller's gold value uses the karat's sellers-get price and the buyer's the buyers-pay price; commission is taken only from the making charge (never from the gold value), never below `commission.minimum_egp`; VAT applies to commission only.
2. **Given** a pure diamond or a gold-with-diamond piece, **Then** there is no spread; commission is `commission.stone_pct` of the value above gold, never below the minimum; the gold value inside gold with diamond is protected at the midpoint of the unadjusted market bid and ask.
3. **Given** any piece, **Then** `buyer_total − seller_proceeds = commission + VAT + spread`.
4. **Given** a waived commission (market-maker code), **Then** the minimum does not force a charge.
5. **Given** the same inputs, **Then** the result is identical every time (the maths reads no clock and no database).

---

### User Story 5 - Operators tune deadlines, deposits and caps (Priority: P2)

A founder changes an operational setting (buyer deposit, seller reply window, time to reach the branch, days to pay, weeks at the branch, relist window, weight tolerance, market-maker age, withdrawal pause, pattern and cancellation thresholds) with a reason, from the Dashboard ("Deadlines and deposits" in the design). Every screen and message that shows such a number reads it from here.

**Why this priority**: later features depend on these values; they must exist with their seed before those features are built, but nothing uses them yet.

**Independent Test**: Change `deadline.seller_reply_hours` from 48 to 36 with a reason; the settings and history show it; a staff member without the permission cannot.

**Acceptance Scenarios**:

1. **Given** the seeded settings, **When** a permitted staff member opens them, **Then** every key shows its value, unit, description, and who changed it last and when.
2. **Given** a change with a reason within the allowed range, **Then** it is saved, kept in history and audited.
3. **Given** a key that does not exist, **When** a change is sent, **Then** it is refused (settings are a fixed catalogue; keys come with releases, not from the Dashboard).

---

### User Story 6 - Staff see today's price next to each karat (Priority: P3)

The Karats screen shows each karat's current "sellers get" price (the design's "Price today, sellers get" column deferred in 004).

**Independent Test**: With a current price, the Karats screen shows each karat's sellers-get price; with none, it shows that no price is set.

---

### Edge Cases

- **No price at all** (fresh install, feed not configured): calculations refuse with a clear "no gold price set" error instead of using zero; the feed counts as down, so the first manual price is allowed and needs no confirmation.
- **Feed not configured** (no credentials in the environment, e.g. local development): the feed counts as down permanently; manual prices work.
- **A pending manual price that is never confirmed**: it never takes effect; a newer entry or a feed reading supersedes it; it lapses after `manualprice.pending_expiry_hours`.
- **Two people entering prices at the same moment, or a feed reading at the same moment**: entries are serialized; the deviation is measured against the price current at the moment of entry.
- **Confirmation when the only other permitted person is unavailable** and `manualprice.confirmer_must_differ` is on: the price stays pending; the business can change the setting or grant the confirm permission from the Dashboard (both audited).
- **A disabled karat**: its prices are still computed, so they are ready when the karat is turned on.
- **A karat added later (004)**: it starts off with zero adjustments on both sides (its market prices unadjusted); Finance sets its adjustments before turning it on.
- **An adjustment that is valid today but, after a market move, would put buyers-pay below sellers-get**: the calculator refuses that karat's quote with a clear error rather than publishing an inverted price; the Gold pricing screen flags the karat.
- **Rounding**: money is kept to the piastre with the rounding rule of Part 3 §3.5, applied once at the end of each amount.
- **Setting changes during an open flow**: the calculator reads the values at the moment it is called; locking a value for an order is the buy-requests feature's job.

## Requirements *(mandatory)*

### Functional Requirements

**Settings**

- **FR-001**: The system MUST hold every tunable number as a setting from a fixed catalogue seeded from `docs/Database schema/01_schema_core.sql` §3 (key, value, unit, description) — except the two `price_correction.*` keys, which become per-karat adjustments (FR-017) — plus the keys this feature adds (`manualprice.confirmer_must_differ` = yes, `manualprice.pending_expiry_hours` = 24, `pricefeed.stale_after_minutes` = 5) and any the plan finds the design needs.
- **FR-002**: Each setting change MUST require a written reason, record old value, new value, person and time in an append-only history, and write an audit record.
- **FR-003**: Each setting MUST have a validated range (percentages 0–100, money and counts ≥ 0, durations > 0); out-of-range values are refused.
- **FR-004**: Settings MUST be grouped for permission: **rates** (commission, VAT, the manual-price rules, the feed staleness, compensation and first-sale money caps) are changed with the rates permission, seeded to Finance (+ CEO); **operations** (deposit, deadlines, windows, tolerances, thresholds) with the operational-settings permission, seeded to the founders (CEO, COO) — anything outside the Part 1 §4 matrix is founders-only. Viewing needs a view permission, seeded to CEO, COO, Finance and Operations. All are ordinary Dashboard-managed permissions (spec 002).
- **FR-005**: No code path MAY use a literal where a setting exists; the application reads the current value.

**Gold prices**

- **FR-010**: The system MUST keep an append-only record of gold prices. Each entry holds the **24K bid and ask per gram**, its source (feed or manual), the staff member it is attributed to (the system actor for the feed; Constitution I), who entered it (manual), the reason (manual), the previous current pair and the deviation from it, its status (effective, pending, superseded, lapsed), who confirmed it and when, and when it took effect. Entries are never edited or deleted.
- **FR-011**: The current price is the latest entry that has taken effect. Each karat's market prices are `24K bid × purity ÷ 0.999` and `24K ask × purity ÷ 0.999`; its published prices are those adjusted by its buy-side and sell-side adjustments. Every later feature reads them through one lookup.
- **FR-012**: A manual entry MUST require a reason and the "enter a gold price" permission (seeded to Finance + CEO), MUST be refused while the feed is healthy, and may be given as a bid and ask or as a percentage change from the current pair.
- **FR-013**: A manual entry deviating from the current pair by more than `manualprice.confirm_deviation_pct` on either side MUST NOT take effect until confirmed by a staff member holding the separate "confirm a gold price" permission (seeded to Finance + CEO, assignable to any role). When `manualprice.confirmer_must_differ` is on, the confirmer MUST be a different person. Until then the entry is pending and the answer is `manual_price_confirm_required`. The first price ever recorded takes effect without confirmation.
- **FR-014**: Only the latest pending entry can be confirmed, within `manualprice.pending_expiry_hours`, and only if no newer entry (manual or feed) took effect since.
- **FR-015**: Every entry MUST have bid > 0 and ask ≥ bid; otherwise it is refused (manual) or discarded and logged (feed).
- **FR-016**: The **price feed** MUST read the provider every minute, write a new effective entry only when the bid or ask changed, record the time of the last successful reading, and never write on failure. The feed counts as down when the last successful reading is older than `pricefeed.stale_after_minutes` or it is not configured. Provider credentials and address live in the environment only.
- **FR-017**: Each karat MUST have a **buy-side** and a **sell-side adjustment**, each a type (fixed EGP per gram, or percentage) and a value, seeded from the schema values (−15 and +15, fixed). Changing one needs the rates permission and a reason, and is kept in history and audited. A change is refused if, at the current market price, it would make that karat's buyers-pay price lower than its sellers-get price or any price ≤ 0.

**Price math (Part 3 §2, amended)**

- **FR-020**: One calculator MUST produce each karat's two published prices and, for a piece: buyer total, seller gross, spread, commission, VAT and seller proceeds, from the current prices, adjustments and settings.
- **FR-021**: Commission on gold MUST be `commission.gold_pct` of the making charge only (never of the gold value); on diamond and gold-with-diamond, `commission.stone_pct` of the value above gold; never below `commission.minimum_egp` unless waived.
- **FR-022**: VAT MUST be `vat.pct` of commission only; the spread carries no VAT.
- **FR-023**: Diamond and gold-with-diamond MUST have no spread; the gold value inside gold-with-diamond, at the midpoint of the karat's unadjusted market bid and ask, is protected from commission.
- **FR-024**: The identity `buyer_total − seller_proceeds = commission + VAT + spread` MUST hold exactly for every result; any rounding residue goes to the spread (Part 3 §3.5).
- **FR-025**: The calculator MUST be deterministic and refuse to compute when no current price exists or a karat's published prices are inverted.

**Dashboard**

- **FR-030**: A "Gold pricing" screen MUST show, as in the design and adapted to the per-karat adjustments: each karat's market bid/ask and published sellers-get / buyers-pay prices and the difference per gram; the adjustments per karat and side (type and value, saved with a reason, previewing the result); the feed status (last successful reading, healthy / down / not configured); the manual price (last manual price and who; entry as a 24K bid and ask or as a percentage change; a preview of every karat with disabled ones greyed; a required reason; the pending / confirm flow; refused while the feed is healthy); and the recent price and adjustment changes. The Rapaport panel is left out.
- **FR-031**: A "Commission rates" screen MUST show and change the rates (commission on gold, on stones, minimum, VAT) and the "Deadlines and deposits" operational settings as in the design, each change with a reason, showing who changed it last.
- **FR-032**: The Karats screen MUST show each karat's current sellers-get price.
- **FR-033**: Each control MUST be shown only to staff holding its permission.

### Key Entities

- **Setting**: a catalogue key with one typed value, a unit, a description, a permission group and an allowed range; last changed by and when.
- **Setting change**: append-only record of one change — key, old value, new value, person, time, reason.
- **Gold price entry**: append-only; the 24K bid and ask per gram; source (feed / manual); entered by; reason; previous pair and deviation; status (effective / pending / superseded / lapsed); confirmed by and when; effective from.
- **Karat price adjustment**: per karat and side (buy / sell) — type (fixed / percentage) and value; last changed by and when; with an append-only change history.
- **Karat prices**: derived, not stored — per karat, market bid/ask, sellers get, buyers pay.
- **Price breakdown**: the calculator's result for one piece — buyer total, seller gross, spread, commission, VAT, seller proceeds, and the inputs used.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of the Part 3 §2/§3 worked examples (restated for bid/ask) and the added percentage-adjustment examples reproduce to the piastre.
- **SC-002**: 100% of setting, adjustment and manual price changes carry a reason and appear in the audit trail with person, before and after.
- **SC-003**: A manual price above the deviation threshold never takes effect without a confirmation that satisfies the current confirm permission and "must differ" setting (0 exceptions in tests).
- **SC-004**: While the provider is reachable, published prices are never more than 2 minutes behind the provider.
- **SC-005**: Finance can set a manual price, including the per-karat preview check, in under 2 minutes.
- **SC-006**: No money or deadline figure used by the Backend exists as a literal in code, and no provider credential exists in code, docs, tests or the Git history of this feature.
- **SC-007**: Changing a rate or adjustment is reflected in the very next calculation (no restart or deploy).

## Assumptions

- The provider's price is EGP per gram of 24K gold (as the previous platform used it); this is verified against the provider's staging server before release — **only after the product owner has put the credentials in `.env`** — and recorded in Technical Spec Part 4.
- The provider's address, username and password are environment configuration only; the staging address is used until the production one is supplied. The old password must be rotated with the provider before production.
- The provider's identity (the Technical Spec says "Evolve"; the old code's host is `mngm.com`) is pending confirmation; docs and code refer to "the gold price provider" until it is confirmed.
- Settings are a fixed, code-defined catalogue seeded by migration; the Dashboard changes values, never adds or removes keys.
- Permission codes (names settled in the plan) join the dynamic-role catalogue (spec 002): enter price, confirm price, rates → Finance (+ CEO); operational settings → CEO, COO; view → CEO, COO, Finance, Operations.
- Money amounts are EGP with 4 stored decimals and customer-facing rounding per Part 3 §3.5.
- The Customer App is not affected; its prototype prices stay until the listings feature.
