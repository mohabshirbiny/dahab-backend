# Feature Specification: Controls and content — app text, legal publishing, notification templates and the remaining mock items

**Feature Branch**: `feature/app-content` in all three repositories, created locally from `main` on 2026-10-10. Base verified against GitHub `origin/main`: Backend `28d058b` (spec 018 merge), Dashboard `e74282d`, Flutter `3bf8f7c`. Nothing is committed or pushed.

**Created**: 2026-10-10 · **Clarified**: 2026-10-10 (one round, Q1–Q5 answered)

**Status**: Clarified (Q1–Q5) and reviewed (decisions D1–D7, 2026-10-10). Ready for implementation after the owner's review of this revision, except the parts marked **[SIGN-OFF]**, which must not be enabled before the named sign-off. Implementation has NOT started.

**Input**: Spec 019 "Controls and content" — everything content-related, all three projects (brief of 2026-10-10, from the owner's `spec-019-requirements` notes).

## How to read this document

| Label | Meaning |
|---|---|
| **[OWNER]** | Decided by the product owner in the brief for this spec. Binding. |
| **[CONFIRMED]** | Stated by a source document or by the built code (source named). |
| **[CONSTRAINT]** | Follows from an established pattern of specs 001–018 or a built guard; not a new product decision. |
| **[PROPOSED]** | A name or mechanism chosen here so the plan has something concrete; the plan may rename it. Not a business rule. |
| **[ASSUMPTION]** | Not stated anywhere; listed again in *Assumptions*. |
| **[OPEN]** | Needs an owner decision; listed in *Decisions and sign-offs*. Nothing marked OPEN is built on a guess. |
| **[DECIDED Dn]** | Decided by the owner in the review of 2026-10-10 (D1–D7). Binding. |
| **[DOC]** | Resolved from an existing document during the review (source quoted). |
| **[SIGN-OFF]** | Decided by the owner but **may not be enabled** until Finance or the lawyer confirms (named in *Decisions and sign-offs*). The code may be designed; it is not built or switched on before the sign-off. |
| **AR-DRAFT** | Arabic written for this spec by the assistant. **Needs owner approval** before it is shown to customers. [OWNER] |

## Clarifications

### Session 2026-10-10

- Q: How should a customer text be named and how big should one text be? → A: Readable, stable keys by screen and purpose (e.g. `order.free_relist.card_title`), one key per visible sentence, label or button; the current English and Arabic strings stay the defaults; migration starts with the spec 018 screens and proceeds screen by screen. [DECIDED Q1]
- Q: When staff change a text, should customers see it at once or only after Publish? → A: Draft, then publish. Saves are drafts seen only in the Dashboard; *Publish changes* puts all pending drafts live together as one new version with publisher and date; reverting is a new publish. Applies to app texts, FAQ and notification templates; legal documents always publish as a whole new version. [DECIDED Q2]
- Q: Should the app keep the published texts on the device, and how fast must a change arrive? → A: Keep the last published bundle on the device; check for a newer version on app start, on return to the foreground, and every 15 minutes while open (server answers not-modified when unchanged); offline use the saved copy, else the code text. [DECIDED Q3]
- Q: Should notification texts use fill-in names, and which? → A: Yes — a fixed list per message taken from what the code fills today (`{piece}`, `{order_ref}`, `{branch}`, `{deadline}`, `{amount}`, `{code}`, `{link}`, `{free_relist_until}`, …) plus setting-based numbers (e.g. `{commission_gold_pct}`); publishing is refused for an unknown name or for dropping `{code}`/`{link}` where the message carries one; the same `{braces}` rule applies to app texts and the FAQ. [DECIDED Q4]
- Q: For promo codes and market makers, build exactly what the Technical Spec decided, and how far? → A: Reuse the decided rules: market-maker codes end to end (code management, approval queue, market-maker mark and filter, purchase with commission waived and spread to the dealer, use log, cost panel). `first_sale` codes and the first-sale advance go to a later money spec; percentage-off codes are not built; their app parts stay hidden. [DECIDED Q5]

## Review decisions (2026-10-10)

- **D1** Permissions: *Edit legal text and publish a new version* → **CEO only** (owner confirms, overriding admin-roles §3, Part 1 §4.3's note and Part 2 §10, which say both founders). *Pause a whole category*, *Stop everything*, *Show or hide something customers see* and *People to watch* → **CEO and COO** (blueprint §7–8 "Founders only"; admin-roles §3 merged CEO/COO column; admin-roles §3 "not listed → founders only"). *Approve a piece for market makers* → **CEO and Finance** (Part 1 §4.1, money-adjacent). [DECIDED D1]
- **D2** When `terms` (or `privacy`) is published, every customer with no recorded acceptance of that document must accept the live version before continuing. [DECIDED D2; the terms text itself needs the lawyer — SIGN-OFF before publication]
- **D3** Market-maker spread: the buyer pays the normal locked price; at settlement the spread line is credited to the dealer's available wallet instead of Dahab's spread account; a negative spread (rates crossed) stays with Dahab as today. **[SIGN-OFF Finance]**: the spread allocation and its ledger, invoice and VAT treatment must be confirmed by Finance before market-maker purchases are built or enabled. [DECIDED D3]
- **D4** Pause and requests for more time: a waiting request is answered *refused — category paused* (in the name of the staff member who paused); the order keeps its deadline and runs normally; new requests are refused while paused. No order is cancelled and no money moves. [DECIDED D4]
- **D5** Stop everything: both founders (staff with `is_founder`) are emailed at their staff address when it is set and when it is cleared, and every Dashboard page shows a banner while it is active; the wider alert-routing question (OI-1.3) stays open. [DECIDED D5]
- **D6** People to watch shows the measured figure of every signal, sorted by the strongest ratio, with **no pass/fail threshold**; a person appears once they reach `flag.pattern_txn_threshold` transactions in the period. [DECIDED D6]
- **D7** Notification templates that carry a one-time code or a link are **read-only** in this spec (shown, not editable) until the owner names who may edit them; every other template is edited under `content.edit`. [DECIDED D7]

## Discovery (what the repositories say)

- **D1 — Environment.** Backend at `28d058b`; Dashboard (`e74282d`) and Flutter (`3bf8f7c`) cloned as siblings. The first Backend checkout had a stale `origin/main` (`3df0a58`, spec 016); it was re-fetched and the branch re-created from `28d058b` before any file was read for this spec. `docs/*.docx` are plain markdown with a `.docx` extension.
- **D2 — The owner's notes file.** The memory file `spec-019-requirements` is on the owner's machine and is not in this environment; the brief in the conversation is the copy used.
- **D3 — Permissions exist only on paper.** Part 1 §4.3 lists *Edit app text* (CEO, COO, Operations), *Edit legal text and publish a new version* (table: CEO only), *Manage promo codes* (CEO, Finance); §4.1 *Approve a piece for market makers* (CEO, Finance); §4.2 *Stop new listings in a category* (CEO, COO, Operations), *Pause a whole category*, *Stop everything*, *Show or hide something customers see* (CEO only — seeds settled by D1). **None of these is a code in `App\Enums\StaffPermission`** or in the Dashboard's `src/types/staff.ts`; this spec adds them. [CONFIRMED]
- **D4 — Disagreement on legal publishing (reported).** Part 1 §4.3 table says CEO only; the note under it says "corrected reading: both founders may … publish legal text … where a cell and that sentence disagree, the sentence wins"; Part 2 §10 `POST /admin/legal-documents` says "both founders". The owner's brief says **CEO only**. Per the Constitution (III) an owner decision recorded in a spec overrides the Technical Spec and the implementing PR updates `docs/`. Admin-roles §3 (the source Part 1 transcribes) has one merged *CEO / COO* column marked Yes. **Resolved by D1: CEO only, confirmed by the owner**; the Technical Spec is updated to say so.
- **D5 — `legal_document` today.** Table `(legal_doc_id, code, version, body_en, body_ar, is_material, published_by, published_at)`, `UNIQUE(code, version)`; current = highest version; no draft state; no write endpoint; reads `GET /reference/legal-documents` (lists only `terms`, `privacy`, `selling_rules`, `id_handling` — `LegalDocumentCode`) and `GET /reference/legal-documents/{code}`. Seeded by migrations: `ownership_declaration` v1, `deposit_agreement` v1, `payout_account_declaration` v1, `collection_proxy_authorisation` v1. `terms`, `privacy`, `selling_rules`, `id_handling` are **not seeded** (the app shows "not published yet", spec 017 FR-045). Forms send the `legal_doc_id` they showed and are refused when it is not current (`ownership_declaration_required` etc.). [CONFIRMED]
- **D6 — Material change and sign-up acceptance are not built.** `is_material` is stored but nothing reads it; no acceptance is recorded at sign-up (contexts in use: `list_piece`, `buy_request`, `payout_account`, `collection_proxy`; the schema comment also names `signup` and `first_sale_offer`). Part 2 §10: "a material change forces re-acceptance"; Dashboard design *Terms and versions*: "If the change is material, ask them again on their next sign-in". [CONFIRMED]
- **D7 — The app translates by English sentence, not by key.** Flutter `LangController.t(en)` looks the English source string up in `assets/i18n/ar.json` (1,177 prototype entries) then `ar_extra.dart` (616 entries) then 70+ regex patterns for strings with numbers; most widgets (`T`, `DRow`, `DNote`, `DButton`, `DMenu`, `showToast`, …) translate internally. There are **no text keys** in the app today. About 1,800 English sentences exist; the design says "1,097 pieces of text". [CONFIRMED]
- **D8 — Dashboard design for App text** (`p-content`): filter chips *Everything / Home / Selling / Buying / Wallet / Legal, locked*; table *Where · English · Arabic · Edit*; **Publish changes**; **Export to Excel**; legal rows show *Locked*; "Text with {braces} pulls its number from settings. Never type the number in". Design for *Terms and versions* (`p-docs`): list *Document · Live version · Since · Agreed by (count) · History*, **New version**, "what is recorded on every tick" (document, version, when, device and address), material → ask again at next sign-in. Both nav entries exist in `src/mock/nav.ts` as `hidden: true`. [CONFIRMED]
- **D9 — Notifications are hard-coded.** 20 notification classes build EN and AR text in PHP (`OrderNotification` 28 event texts, `BuyRequestNotification` 18, `PayoutNotification` 12, `ListingDecisionNotification` 8, `AccountNotification` 6, `WalletNotification` 2, plus single-message classes for OTPs, links, verification and top-ups — about 90 messages). The email body is the SMS sentence; the inbox (spec 017, `RendersInbox`) re-renders the mail subject and SMS text in both languages; codes and confirmation links never go to the inbox. Placeholders today are positional PHP variables (piece title, order ref, branch, deadline, amount, code). [CONFIRMED]
- **D10 — Mock items in the customer app** (`lib/widgets/mock_flag.dart` `mockScreens`): `R.help`, `R.invite`, `R.branch`, `R.editprice`, `R.codes`, `R.codeuses`, `R.mmapprove`, `R.compensate`; `MockMark` on the sell form's promo-code card and on the account details when signed out; the account menu's *Invite a friend* row is `mock: true`. `R.codes`, `R.codeuses`, `R.mmapprove`, `R.compensate` live in `lib/features/admin/admin_screens.dart` and are **staff (admin) screens** in the customer app prototype. `R.branch` and `R.editprice` are not content and are **not** in this spec's list. [CONFIRMED] The Account screen also lists these four as menu rows marked mock, plus an unmarked **"Show payout averages — Admin only"** toggle (`account_screen.dart`, local `session.togglePayStats()`); that toggle is the app's only "switch" and is the design's *What customers can see → Payout averages* switch done in the wrong app.
- **D11 — What the documents define for each mock item.**
  - *FAQ (`R.help`)*: nine question/answer pairs exist only as mock data (`lib/mock/mock_account.dart`); several contain numbers that live in settings (20 % of the making charge, 5 % on stones, 200 EGP minimum). No document defines FAQ behaviour beyond "show it" (spec 017 FR-045: "The FAQ is not built (spec 019)"). [CONFIRMED]
  - *Invite a friend (`R.invite`)*: prototype only ("She pays a lower commission on her first sale. You pay a lower one on your next", code `MONA4417`, reward "as commission credit"). **No Technical Spec section, schema table, setting, ledger event or design page defines referral.** [CONFIRMED]
  - *Promo codes (`R.codes`, `R.codeuses`)*: schema `promo_code (code, kind ∈ {first_sale, market_maker}, tied_customer_id, commission_waived, gives_spread, monthly_cap_egp, is_active, created/deactivated by/at)` and `promo_code_use (code, order_id, customer_id, allowed, block_reason, used_at)`; Part 2 `POST/PATCH /admin/promo-codes` (*Manage promo codes*, audited, idempotent); Part 3 §6: a `first_sale` code **gates the first-sale advance** (Dahab pays a first-time gold seller at IGI receipt, capped by `payout.first_sale_cap_egp`) — the advance is **not built**. The Dashboard design's *New code* form and the prototype also show **percentage-off codes** (`WELCOME` 50 % off commission once per ID, `EID25` 25 % off with an end date), "times they can use it", "starts/ends" — **none of which the schema or the Technical Spec has**. [CONFIRMED — disagreement, see OD-3]
  - *Market-maker approval (`R.mmapprove`)*: Part 3 §8 (commission waived, spread to the dealer, listing age ≥ `marketmaker.min_list_age_days` = 7, one tied account, logged approval, monthly cap, switch off instantly); Part 2 `POST /admin/market-maker/approve-listing` (CEO, Finance); schema `market_maker_approval (listing_id unique, approved_by, asking_price, gold_value, rapaport_guide, approved_at)`; `customer.customer_type` already has `market_maker`; setting `marketmaker.min_list_age_days` seeded. Design `p-mm`: queue of aged pieces with views/no-requests and price-vs-guide, *Price is sound* / *Leave*, "What this costs" (pieces bought, commission and spread given up); "They see approved pieces marked in the app, with a filter to show only those." Part 3 says the code "fails from any other account/device"; the design says it works "whatever device it is typed on"; **blueprint §4 settles it: "It fails from any other account, on any device"** — the code is bound to the account, not a device [DOC]. Nothing is built. [CONFIRMED]
  - *Compensation (`R.compensate`)*: **built and live in the Dashboard** (spec 015 Compensation page, `compensation.pay`). The customer-app screen is a staff screen with mock data. [CONFIRMED]
  - *Switches*: design `p-switches` and Part 2 `POST /admin/category-controls`, schema `category_control (category, level ∈ {stop_new_listings, pause_category, stop_everything}, is_active, message_en/ar, set_by/at, cleared_by/at)`; "anything with a locked price is left alone"; pause hides live pieces with no request and they come back on reopening; "requests for more time become cancellations" (blueprint §7, admin-roles §5; design adds "deposits returned") — interpreted by **D4**; a reason is required; sellers in the category are told. Design also lists **What customers can see**: Rapaport reference price, payout averages, views and requests on a listing, sharing a piece — shown/hidden by *Show or hide something customers see*. **No schema exists for the visibility switches**; blueprint §8 lists *What customers can see — Rapaport, averages, sharing, social proof — Founders* among the settings changed in the admin panel [DOC]. Nothing is built. [CONFIRMED]
  - *People to watch*: design Inspections page — people with ≥ 5 transactions (`flag.pattern_txn_threshold`), period filter, export, columns *Person · Sold / bought · What stands out* (weight always short; asks then does not pay; trades with the same person repeatedly; listings sent back often). Part 3 §9.3: crossing the threshold raises a **review flag, never a suspension**; who is alerted and how is unresolved (OI-1.3 / OI-2.2 / OI-3.4). No permission row names it, so it belongs to the founders (admin-roles §3: "If an action is not listed, it belongs to the founders only") [DOC]. Nothing is built. [CONFIRMED]
- **D12 — Spec 018 first-slice strings** (all in Flutter, English as source, Arabic in `ar_extra.dart`): order screen `_FreeRelistCard` (title, body, button, countdown), the seller's "No Dahab fee on this sale…" note and the buyer's "You put this piece back…" note (`order_screen.dart`); `FreeRelistScreen` — form, the estimate line "No Dahab commission on this sale. The difference between the rate you were charged and the rate you are paid still applies…" (spec 018 A6: wording to approve with the Arabic), errors, success dialog (`order_help_screens.dart`); `RateScreen` — title, stars, note hint "Anything we could do better?", send, closed/given states; **no invite card** (spec 018 FR-038). [CONFIRMED]
- **D14 — Terms §13** (`docs/dahab-terms-draft.docx`): "Every version of these terms is kept. You remain bound by the version you accepted until you accept a new one. Where a change is material, you are asked to accept it again before you continue using the platform." No acceptance of any terms version is recorded today (D6). [CONFIRMED]
- **D15 — Settlement and invoices.** The DH012 check is keyed on `order.commission_amount = 0` (no seller invoice) — not on the free-relist link — so a commission-free market-maker sale already fits it. `OrderSettlement::post` writes the spread to `dahab_spread`; staff rows carry `email` and `is_founder`; refusing a request for more time takes a staff actor and a note. [CONFIRMED code at `28d058b`]
- **D13 — Terms §5.5 draft** (`docs/dahab-terms-draft.docx`, v0.1): "If you change your mind after collecting, you may relist the piece with no commission within twelve working hours, returning it to the branch at your own cost." It omits what spec 018 built: the spread still applies, once per order, the window is counted from the handover at the branch, the relisted piece is a new listing (FR-005/012/013, Q2). A proposed wording is in *Appendix A* for legal review; spec 018's open item stays open. [CONFIRMED]

## Scope

**In** (all three projects, English and Arabic):

1. **App text** — every customer-facing text of the customer app, editable from the Dashboard and published; code strings stay the defaults.
2. **Legal publishing** — new versions of the seven named documents from the Dashboard, with history, acceptance counts, and the material-change re-acceptance the documents require.
3. **Notification templates** — every customer SMS, email and inbox text editable from the Dashboard.
4. **The remaining mock items** — each built exactly as far as the documents define it, and no further: FAQ and help; market-maker codes, approvals and the code use log (Q5); Switches (category controls and what customers can see); People to watch; the customer app's staff mock screens removed (the Dashboard is where staff work). Invite a friend waits for a definition (OD-2). Market-maker **purchases** wait for Finance (D3 [SIGN-OFF]).

**Out (non-goals):**
- The Dashboard's own staff-facing labels (they are not customer text; the Dashboard keeps its own i18n). [ASSUMPTION A1]
- Changing any business rule, number or setting through text: text **describes**; settings decide. [CONSTRAINT, design D8]
- Deciding spec 018's open items: Finance sign-off on "no seller invoice when commission is 0" and the lawyer's approval of Terms §5.5. [OWNER]
- Filing anything with the Tax Authority; rich text, images or HTML in texts; per-customer or A/B texts; machine translation.
- `R.branch` and `R.editprice` (not content; not in the brief).
- Who receives pattern / cap alerts and by which channel (OI-1.3) — People to watch is a list staff open, not an alert.

## Terminology

- **Text key** — a readable, stable name by screen and purpose (`<screen>.<part>.<purpose>`, e.g. `order.free_relist.card_title`) for exactly one visible sentence, label or button. [DECIDED Q1] Has a default English and Arabic value (the code's) and, optionally, published overrides.
- **Draft / published** — an edited value is a draft until someone with the permission publishes it; customers only ever see published values or the code default.
- **Bundle** — all published app texts with one version number; what the customer app downloads.
- **Placeholder** — `{name}` inside a text, filled at display/send time from the order, the customer or a setting.
- **Legal document / version** — one of the seven codes; every published version is kept forever.
- **Material version** — a version that requires customers to accept it again before they continue.
- **Notification template** — the EN and AR text of one customer message (per event and channel: SMS sentence, email subject, email body; the inbox reuses them).

## Actors and permissions

| Actor | Does | Permission (code [PROPOSED]) |
|---|---|---|
| CEO, COO, Operations (seed) | Edit app text and FAQ, publish, export, revert | *Edit app text* → `content.edit` [CONFIRMED Part 1 §4.3] |
| Same | Edit notification templates and publish — except templates carrying a code or link, which are read-only (D7) | `content.edit` [PROPOSED — no document names a permission for templates; DECIDED D7] |
| CEO (seed) | Publish a new version of a legal document; mark it material | *Edit legal text and publish a new version* → `legal.publish` — **CEO only** [DECIDED D1] |
| Any staff with `content.edit` or `legal.publish` | Read documents, versions, acceptance counts | read with either code [PROPOSED] |
| CEO, Finance (seed) | Create, edit, turn off promo codes; read every use | *Manage promo codes* → `promo.manage` [CONFIRMED Part 1 §4.3] |
| CEO, Finance (seed) | Approve an aged piece for market makers; read the cost | *Approve a piece for market makers* → `market_maker.approve` [CONFIRMED Part 1 §4.1] |
| CEO, COO, Operations (seed) | Stop new listings in a category | `category.stop_new` [CONFIRMED §4.2; blueprint "Founders or Operations"] |
| CEO, COO (seed) | Pause a whole category; Stop everything; show or hide what customers see | `category.pause`, `platform.stop_everything`, `visibility.manage` [DECIDED D1] |
| CEO, COO (seed) | Open People to watch, export | `people_to_watch.view` [DECIDED D1; DOC admin-roles §3] — never IGI, even on the Inspections page |
| Customer (and visitor) | Reads published texts, FAQ and legal documents; accepts a required legal version; a market maker sees approved pieces and uses their code — **only after Finance sign-off (D3)** | none / customer token |
| System | Seeds defaults; serves bundles; renders notifications | system actor |

All codes are seeded once and editable in Roles (spec 002); the CEO holds every code. [CONSTRAINT]

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Staff change a customer text and publish it (Priority: P1)

Operations corrects the Spec 018 free-relist card wording in English and Arabic, previews it, and publishes; customers see the new wording without an app release.

**Why this priority**: it is the platform every other content item uses, and the owner named the Spec 018 screens as the first slice.

**Independent Test**: edit one first-slice key in the Dashboard, publish, open the order screen in the app in both languages → the new text shows; delete the override (revert) → the code default shows again.

**Acceptance Scenarios**:
1. **Given** a staff member with `content.edit`, **When** they open App text, **Then** they see every registered key with where it appears (screen/group), the current English and Arabic, whether it differs from the code default, its version and published date, and filters by area (Home, Selling, Buying, Wallet, Account, Orders, Notifications, Legal locked).
2. **Given** they edit English and Arabic of a key, **When** they save, **Then** a draft exists, visible only in the Dashboard; customers still see the published value (or the default).
3. **Given** drafts exist, **When** they press *Publish changes*, **Then** all pending drafts become published in one step with a new bundle version, the publisher and the time recorded, and an audit entry listing each key with before/after.
4. **Given** a key whose text uses `{placeholders}`, **When** a draft drops a required placeholder or adds an unknown one, **Then** saving is refused with the names of the offending placeholders.
5. **Given** a draft with only one language filled, **Then** it cannot be published (both languages are required). [design D8]
6. **Given** a key in the *Legal, locked* group, **Then** it is read-only here with a link to Terms and versions.
7. **Given** a published key, **When** staff revert it to the code default, **Then** after the next publish the override is gone (the bundle no longer carries the key) and the app shows whatever its own code default is — including a default changed by a later app release; **When** they restore an earlier published value, **Then** that value is published as a new version. Both audited with the version they replaced.
10. **Given** a key whose code default changed in a new app release while an override exists, **Then** the list marks it *default changed since this override* so staff can review it.
8. **Given** a staff member without `content.edit`, **Then** the page is not in the navigation and every write is 403.
9. **Given** Export, **Then** a file lists key, area, English, Arabic, version, published date.

---

### User Story 2 — The customer app shows published texts and never breaks (Priority: P1)

**Independent Test**: start the app with the Backend down → every screen shows the code text; with the Backend up and an override published → the override shows; a key that is unknown to the Backend or missing a language → the code text shows.

**Acceptance Scenarios**:
1. **Given** a published bundle, **When** the app starts (signed in or not), **Then** it loads the bundle and shows published values for every key it renders, in the selected language.
2. **Given** no network, a failed load, an empty bundle, or a key absent from the bundle, **Then** the app shows the string in its code, with no error, no blank and no layout change. [OWNER]
3. **Given** a published value that contains a placeholder the app cannot fill, **Then** the app shows the code default for that key instead of a raw `{name}`.
4. **Given** a cached bundle from an earlier start, **When** the network is down, **Then** the saved published values show; with nothing saved, the code texts show. [DECIDED Q3]
5. **Given** the first slice is live, **Then** the free-relist card, the relist form and its estimate line, the seller's "No Dahab fee" note, the buyer's "You put this piece back…" note and the rating screen read their texts from keys; the rating screen still has no invite or referral content. [OWNER; spec 018 FR-038]
6. **Given** later stages, **Then** each further screen moves to keys without changing what the customer reads until staff publish a change.
7. **Given** a setting used by a placeholder changes (e.g. the commission rate), **Then** the bundle's version tag changes too, so open apps pick up the new value within 15 minutes without any text being republished.

---

### User Story 3 — The CEO publishes a new version of a legal document (Priority: P1)

**Independent Test**: as CEO publish `terms` v1 → every existing customer (none has an acceptance on record) is asked to accept it before continuing (D2); a new sign-up records it; publish v2 marked material → customers who accepted v1 are asked again; each acceptance is recorded with version, time, device and address.

**Acceptance Scenarios**:
1. **Given** `legal.publish`, **When** the CEO opens Terms and versions, **Then** the seven documents show with live version, published date, number of customers who accepted that version, and history of every version (who, when, material or not). [design D8]
2. **When** they write English and Arabic and publish, **Then** a new version (previous + 1) exists with published date and publisher; earlier versions are untouched forever; audited with the text's size/hash, not the full body. [CONFIRMED D5]
3. **Given** a form showing an older declaration (`ownership_declaration`, `deposit_agreement`, `collection_proxy_authorisation`, `payout_account_declaration`), **When** the customer submits after a new version was published, **Then** the existing refusal applies and the app shows the new text to accept. [CONFIRMED D5]
4. **Given** a version of `terms` or `privacy` marked **material**, **When** a customer whose latest acceptance of that document is older signs in, refreshes or opens the app, **Then** the app shows the new version and asks them to accept before anything else; until they do, every state-changing request is refused `409 legal_acceptance_required` except accepting, signing out and reading; acceptance is recorded (context `material_reaccept` [PROPOSED]). [DOC Terms §13 "before you continue using the platform"; CONFIRMED D6]
4a. **Given** `terms` or `privacy` is published and a customer has **no** recorded acceptance of it, **Then** the same gate applies to the live version (context `first_acceptance` [PROPOSED]). [DECIDED D2]
5. **Given** sign-up, **When** the app sends the ids of the `terms` (and `privacy`) versions it showed, **Then** those acceptances are recorded with context `signup`; an id that is not the live version is refused `legal_document_not_current`; a request without ids (an older app) records nothing and the customer meets the D2 gate at first sign-in. [CONFIRMED schema comment, D6]
6. **Given** a staff member without `legal.publish` (the COO included, D1), **Then** publishing is 403; reading is allowed with `content.edit`.
7. **Given** a version is published, **Then** it cannot be edited or deleted (database guard); a correction is a new version.
8. **Given** the public list (`/reference/legal-documents`), **Then** its existing `data[]` (the four documents) is unchanged and a new `declarations[]` lists the four declarations with published state — non-breaking. [PROPOSED]
10. **Given** the release order, **Then** no `terms`/`privacy` version and no material version is published until the app version with the acceptance screen is live (rollout step). [CONSTRAINT]
9. **Given** Terms §5.5, **Then** this spec delivers only a **draft** wording (Appendix A) for the lawyer; it is published only when the CEO decides, after legal approval. [OWNER]

---

### User Story 4 — Staff edit a notification template (Priority: P2)

**Independent Test**: edit the SMS text of `order.collected` (buyer copy) in Arabic, publish, hand an order over → the buyer's SMS, email and inbox use the new text with the free-relist end filled in; remove the override → the code text is used.

**Acceptance Scenarios**:
1. **Given** `content.edit`, **When** staff open Notifications, **Then** every customer message is listed by event and audience (e.g. *Order collected — buyer*), with its SMS sentence, email subject and email body in EN and AR, its allowed placeholders, and whether it carries a code or link.
2. **When** they publish an edit, **Then** every message sent after publication uses it (rendering happens at send time). [ASSUMPTION A4]
3. **Given** a template that carries a one-time code or a confirmation link, **Then** it is shown read-only: no draft can be saved for it (`content_key_read_only`). [DECIDED D7] The `{code}`/`{link}` requirement stays in the catalogue for when the owner names an editor.
4. **Given** a template with no published override, a missing language, or a rendering error, **Then** the code text is sent; a send never fails because of a template. [OWNER "a missing key never breaks"]
5. **Given** the inbox, **Then** it shows the same words as the SMS/email (spec 017), and collection codes stay masked there. [CONFIRMED D9]
6. **Then** each publish is audited with before/after.

---

### User Story 5 — Customers read the FAQ and staff maintain it (Priority: P2)

**Independent Test**: publish three FAQ entries → Help shows them in order in both languages with the MOCK banner gone; an entry whose answer uses `{commission_gold_pct}` shows the live setting value.

**Acceptance Scenarios**:
1. **Given** `content.edit`, **When** staff add, edit, reorder or hide FAQ entries (question + answer, EN + AR) and publish, **Then** Help shows the published entries in that order. [PROPOSED structure]
2. **Given** nothing is published, **Then** Help shows "Nothing here yet" and *Contact us* (the mock questions are not shown as real). [CONSTRAINT: no invented content]
3. **Given** an answer that states a number which is a setting (commission %, minimum, windows), **Then** it uses a placeholder and shows the current setting value. [design D8]
4. **Then** the nine prototype questions are offered as an **unpublished seed draft**, English from the prototype and Arabic marked AR-DRAFT, for staff to review. [ASSUMPTION A5]
5. **Then** `R.help` leaves `mockScreens`.

---

### User Story 6 — Market-maker codes end to end (Priority: P3) [DECIDED Q5; purchases SIGN-OFF D3]

Delivered in two parts. **6A (staff side)** can ship: codes, approvals, the cost panel and the (empty) use log. **6B (purchases)** — the market mark and filter, using the code at a buy request, and the settlement — is **not built or enabled until Finance confirms D3**.

**Independent Test (6A)**: Finance creates `MM-KARIM` tied to a market-maker customer with a monthly cap and approves a 9-day-old piece; both are audited; the cost panel shows zero purchases. **(6B, after sign-off)**: the tied customer sees the piece marked, buys it with the code → commission and its VAT 0, the spread credited to their wallet; a use from another account is refused and logged.

**Acceptance Scenarios**:
1. **Given** `promo.manage`, **When** staff create a code (kind `market_maker` only in this spec; tied customer required for `market_maker`; commission waived; spread to them; monthly cap), **Then** it exists, audited; turning it off stops new uses at once; anything in progress carries on. [CONFIRMED schema, Part 2, design]
2. **Given** the Code use log, **Then** staff read every use per code; in 6A it is empty, and from 6B every attempted use writes a `promo_code_use` row, allowed or blocked with the reason. [CONFIRMED; writes SIGN-OFF D3]
3. **Given** `market_maker.approve`, **When** staff open Market maker approvals, **Then** they see live pieces older than `marketmaker.min_list_age_days` with the numbers (asking price, gold value or Rapaport guide; views are not measured anywhere yet, so the column shows *not measured*) and choose *Price is sound* (approval recorded with the numbers seen) or *Leave*; and a monthly cost panel (pieces bought, commission and spread given up — zero until 6B ships). [CONFIRMED design, schema]
4. **(6B, [SIGN-OFF])** **Given** a market-maker customer, **Then** approved pieces are marked, with a filter, on an authenticated market read only they get; a buy request with their code on an approved, aged piece is priced like any other (normal locked price and deposit) and settles with commission and its VAT at 0 and the spread line credited to their available wallet (a negative spread stays with Dahab); a younger or unapproved piece, another account (on any device), an inactive code or a cap overrun is refused and logged. The cap counts uses whose buy request is still open or settled. [CONFIRMED Part 3 §8, blueprint §4; DECIDED D3]
4a. **Given** Finance has not signed off D3, **Then** no customer-facing market-maker behaviour exists: no mark, no filter, no `promo_code` field on buy requests. [SIGN-OFF]
5. **Then** `first_sale` codes cannot be created (refused `promo_kind_not_available`); they and the first-sale advance (Part 3 §6) belong to a later money spec. [DECIDED Q5]
6. **Then** percentage-off codes (WELCOME, EID25), usage counts and start/end dates are not built; the design form's options for them are not shown; the sell form's promo card stays hidden. [DECIDED Q5]
7. **Then** the customer app's staff screens `R.codes`, `R.codeuses`, `R.mmapprove`, `R.compensate` are removed; their Dashboard counterparts are Promo codes, Market maker approvals and the existing Compensation page. [CONSTRAINT: staff actions never run on a customer token]

---

### User Story 7 — Switches: category controls and what customers can see (Priority: P3)

**Independent Test**: Operations stops new gold listings with a reason and message → a seller trying to list gold sees the message; the COO pauses diamonds → live diamond pieces with no request disappear from the market and return on reopening; a waiting request for more time on a diamond order is answered *refused — category paused*; pieces with a locked price finish normally.

**Acceptance Scenarios**:
1. **Given** `category.stop_new`, **When** staff stop new listings in a category with a reason and a customer message (EN + AR), **Then** new listings in it are refused with that message; everything live stays. [CONFIRMED]
2. **Given** `category.pause`, **When** a founder pauses a category, **Then** new listings are blocked; pieces in state `live` (nobody has asked) are hidden and return by themselves on reopening; pieces with a request stay visible and buyers may still join their line (no document blocks it [DOC]); anything with a locked price finishes normally; every waiting request for more time on an order in the category is answered *refused — category paused* in the pausing staff member's name, the order keeps its deadline, and new requests are refused while paused [DECIDED D4]; only sellers with pieces in the category are told. [CONFIRMED blueprint §7, design]
3. **Given** `platform.stop_everything`, **When** a founder stops everything with a reason, **Then** in every category no new listings and no buy requests, and no new withdrawal requests; staff **release** of a held withdrawal is refused while stopped; a customer may still **cancel** a pending withdrawal (no money leaves); money held stays held; everything with a locked price (orders, payments, handovers) finishes normally; customers see a paused banner; both founders are emailed and every Dashboard page shows a banner while it is active. [DOC blueprint §7 "plus withdrawals", design "money already held stays held"; DECIDED D5]
4. **Given** `visibility.manage`, **When** a founder hides/shows *Rapaport reference price*, *Payout averages*, *Views and requests on a listing*, *Sharing a piece*, **Then** the market and the app stop/start showing that item; pricing is not affected. Items with no data behind them yet (view counts, the Rapaport suggestion) only hide what exists. [CONFIRMED design; DOC blueprint §8 — settings]
5. **Then** every change is audited with actor, reason and message; clearing a control records who and when. [CONFIRMED schema]

---

### User Story 8 — People to watch (Priority: P3)

**Independent Test**: a seller with 7 sales whose IGI weights came in short 6 times appears for the last 30 days with "6 of 7 came in under, −4.1 % average" as their strongest signal; a person with 4 transactions does not appear; IGI staff never see the panel.

**Acceptance Scenarios**:
1. **Given** permitted staff, **When** they open People to watch (Inspections page), **Then** they see customers with at least `flag.pattern_txn_threshold` transactions in the chosen period (30/60/90 days, this year, custom), with sold/bought counts and the measured figure of each signal — sold pieces whose IGI weight came in under the stated weight (n of m, average difference), accepted requests never paid (n of m), the most frequent counterparty (count), listings sent back for changes (n of m) — sorted by the strongest ratio; no signal is labelled pass/fail. [CONFIRMED design, Part 3 §9.3; DECIDED D6]
2. **Then** it is read-only, never suspends anyone, sends no alert, and can be exported. [CONFIRMED Part 3 §9.3; alerts OUT]
3. **Given** staff without `people_to_watch.view` (IGI included), **Then** the panel is absent and the read is 403; each read is audited. [DECIDED D1]

---

### User Story 9 — Invite a friend (Priority: P3, blocked)

**Acceptance Scenarios**:
1. **Given** no document defines referral, **Then** nothing is built; the account menu's *Invite a friend* row and `R.invite` are hidden from customers (not shown as if real) until the owner defines the rules (OD-2). [OWNER "do not invent behaviour"; CONSTRAINT]
2. **Then** no referral content appears on the rating screen in any case. [OWNER; spec 018 FR-038]

### Edge Cases

- A key is never renamed once released (stable, Q1); if a key is removed in a new app release while an override exists → the override is orphaned, shown in the Dashboard as *not used by the current app*, never sent to old apps that do not ask for it; no screen breaks. [PROPOSED]
- An old app version asks for keys the Backend no longer lists → it uses its code strings.
- Two staff edit the same key → there is one shared draft per key; the second save, made on an outdated copy, is refused with `content_draft_changed` until they reload (optimistic version check). [PROPOSED; A2]
- A published text is longer than the screen comfortably holds → per-key maximum length from the code default × a factor; refused above it. [ASSUMPTION A6]
- Arabic text with Latin digits or mixed direction → stored as typed; the app's existing number formatting applies only to placeholders.
- A template placeholder value missing at send time (e.g. no branch) → the code text is sent for that message.
- A legal version published while a customer has the form open → existing `*_required` refusal; the app reloads the document.
- A material version published, customer offline for months → asked at next sign-in or refresh.
- Publishing an identical text → refused as no change (`content_unchanged`). [PROPOSED]
- Category paused while a piece has buy requests in its line but no acceptance → the piece stays visible and open to new requests; only pieces nobody has asked for are hidden. [DOC blueprint §7, design]
- Stop everything while withdrawals are held for review → no new withdrawals, no release; customers may cancel theirs; held money stays held. [DOC; DECIDED D5]
- A key's override is reverted to default after a new app release changed that default → the customer sees the new code default, never the old wording. [review fix]
- A setting changes while no text is republished → the bundle's version tag still changes (it covers the setting values). [review fix]
- A pause is cleared while a refused request for more time is still relevant → nothing is restored; the seller may ask again. [DECIDED D4]

## Requirements *(mandatory)*

### A. App text

- **FR-001** The Backend MUST hold a registry of customer text keys — readable and stable, named by screen and purpose, one per visible sentence, label or button [DECIDED Q1] — each with: key, area (Home, Selling, Buying, Orders, Wallet, Account, Help, Notifications, Legal-locked), description of where it appears, default EN and AR (the code's), allowed placeholders, and maximum length. [OWNER; PROPOSED fields]
- **FR-002** Each key MAY have published overrides; every publication is kept (version number, EN, AR, publisher, published date). Nothing is ever deleted. **Revert to default** publishes a *default marker* version (no text) that ends the override — the bundle then omits the key and the app shows its own current default; **restore** publishes an earlier value as a new version. [OWNER versioned + published date; review fix]
- **FR-003** Edits are drafts until published; customers never see drafts. *Publish changes* puts **all pending drafts** live together and produces one new bundle version with publisher and published date; there is no edit-in-place and no second approver. [design D8; DECIDED Q2]
- **FR-004** Publishing MUST be refused when: a language is empty, a required placeholder is missing, an unknown placeholder is present, the text exceeds the key's maximum, or nothing changed. [CONSTRAINT]
- **FR-005** The hard-coded string in each app MUST remain the default and the fallback. A missing key, a missing language, an unfillable placeholder, a failed or slow load never breaks or blanks a screen. [OWNER]
- **FR-006** A public, unauthenticated read MUST serve the current published bundle — published overrides of kinds *app* and *faq* only (never notification templates), both languages, the bundle version and the current values of the setting placeholders — cheap to poll: its version tag MUST change whenever a text is published **or** any of those setting values changes, and an unchanged tag answers not-modified. [PROPOSED `GET /reference/app-texts`; review fix]
- **FR-007** The customer app MUST read texts by key, use the published value when present and valid, and else the code string; keep the last published bundle on the device and check for a newer version on start, on return to the foreground and every 15 minutes while open; offline it uses the saved copy, else the code string. [OWNER; DECIDED Q3]
- **FR-008** Staging [OWNER; DECIDED Q1]: **Stage 1** the spec 018 screens (D12); then screen by screen, grouped into stages by area, each stage moving a screen's strings to keys without changing the customer's text. The plan fixes the order.
- **FR-009** Settings-backed numbers in texts MUST be placeholders, never typed numbers. [design D8] Allowed setting placeholders are a fixed list (one per setting a customer text may state, e.g. `{commission_gold_pct}`, `{commission_minimum_egp}`, `{free_relist_hours}`), filled with the live setting value; the same list serves app texts, the FAQ and notification templates. [DECIDED Q4]
- **FR-010** Every new or changed Arabic string introduced by this spec MUST be marked AR-DRAFT in this spec (Appendix B) and **needs owner approval** before it is published to customers. Arabic copied unchanged from the current code keeps its current status. [OWNER]
- **FR-011** Export MUST produce key, area, EN, AR, version, published date for all keys. [design D8]
- **FR-012** Every save and publish MUST be audited (actor, keys, before/after); the audit viewer gains a *Content* category. [CONSTRAINT]

### B. Legal publishing

- **FR-020** Codes managed: `terms`, `privacy`, `selling_rules`, `id_handling`, `ownership_declaration`, `deposit_agreement`, `collection_proxy_authorisation` [OWNER], and `payout_account_declaration` (spec 013), which is a legal text in the same table and could otherwise never be updated. [CONFIRMED D5; review]
- **FR-021** Publishing a new version MUST: require EN and AR bodies, assign version = latest + 1, set published date and publisher, set `is_material` as chosen, and be audited; a published version is immutable (guard refuses UPDATE/DELETE). [CONFIRMED schema; CONSTRAINT]
- **FR-022** Legal bodies are fixed text with no placeholders, because what a customer accepted must not change meaning when a setting changes. [CONSTRAINT]
- **FR-023** The list MUST show, per document, the live version, published date, how many customers accepted the live version, and the history of versions. [design D8]
- **FR-024** Acceptance evidence is unchanged: one immutable row per tick with document, version, time, address and device. [CONFIRMED]
- **FR-025** For `terms` and `privacy` (the documents accepted at sign-up), a customer MUST accept the live version before continuing when (a) they have no recorded acceptance of that document [DECIDED D2], or (b) a **material** version newer than their latest acceptance exists [DOC Terms §13]. The requirement is computed, shown on sign-in, refresh and `GET /customer/me`, and enforced by refusing every state-changing customer request with `409 legal_acceptance_required` except accepting, signing out and reads. Declarations need no gate: each form already re-accepts its current version.
- **FR-026** Registration MAY carry the ids of the `terms` and `privacy` versions the app showed; when present they are recorded with context `signup` (refused `legal_document_not_current` if not live); when absent nothing is recorded and FR-025(a) applies at first sign-in. Non-breaking. [CONFIRMED schema comment; review fix]
- **FR-027** `GET /reference/legal-documents` keeps its `data[]` unchanged and adds `declarations[]` with published state. Non-breaking. [PROPOSED; extends spec 017 FR-045]
- **FR-027a** Release order: the Backend gate and the app's acceptance screen ship **before** any `terms` or `privacy` version or any material version is published; publishing `terms` v1 gates every existing customer at once (D2), so its timing is the CEO's call after legal approval. [CONSTRAINT; DECIDED D2]
- **FR-028** In App text, legal documents appear as *Legal, locked* with a link to Terms and versions; App text cannot change them. [design D8]
- **FR-029** Appendix A carries the proposed Terms §5.5 wording EN + AR as a draft for legal review. It is not seeded or published by this spec. [OWNER]
- **FR-030** Permission: `legal.publish`, seeded to **CEO only** (the COO does not get it by seed; editable in Roles like any code). [DECIDED D1]

### C. Notification templates

- **FR-040** Every customer notification text (SMS sentence, email subject, email body; EN and AR) MUST be a template with a key per event and audience, its code text as default, and a fixed list of allowed placeholders taken from what the code fills today (e.g. `{piece}`, `{order_ref}`, `{branch}`, `{deadline}`, `{amount}`, `{code}`, `{link}`, `{free_relist_until}`), plus the setting placeholders of FR-009; publishing is refused for an unknown name. [OWNER; DECIDED Q4]
- **FR-041** Templates follow the draft → *Publish changes* rules of FR-002–FR-004 and FR-012 (one publish puts all pending template drafts live). [DECIDED Q2]
- **FR-042** Templates carrying a one-time code or a confirmation link are **read-only** in this spec (no draft can be saved: `content_key_read_only`) [DECIDED D7]; their catalogue entries still declare `{code}`/`{link}` as required, so when an editor is named a publish without them is refused. [CONSTRAINT; DECIDED Q4]
- **FR-043** A message is rendered when it is sent (after commit, as today) from the latest published template, falling back to the code text on any problem; a send never fails because of a template. [CONSTRAINT; OWNER]
- **FR-044** The inbox keeps showing exactly the SMS/email words, with codes masked and links never stored. [CONFIRMED spec 017]
- **FR-045** Staff-facing messages (none to customers) and OTP *channels* are out of scope; only the words change. [ASSUMPTION A3]

### D. FAQ and help

- **FR-050** FAQ entries (question + answer, EN + AR, order, shown/hidden) MUST be editable and published with the App text rules; answers may use setting placeholders. [PROPOSED]
- **FR-051** Help MUST show only published entries; none → an empty state with *Contact us*. `R.help` leaves `mockScreens`. [CONSTRAINT]
- **FR-052** Contact details stay in `config/dahab-support.php` (spec 017); not editable here. [CONFIRMED; ASSUMPTION A8]

### E. Market-maker codes *(DECIDED Q5; device rule resolved by blueprint §4; purchases SIGN-OFF D3)*

- **FR-060** Market-maker codes (`promo_code.kind = market_maker`, tied to one customer of type `market_maker`; the code is bound to that account and works on any of its devices — blueprint §4), create / edit / turn off / turn on, audited and idempotent, `promo.manage` (CEO, Finance). Creating any other kind is refused `promo_kind_not_available`. [CONFIRMED; DOC]
- **FR-061** A Code use log per code (6A, empty until purchases exist); from 6B every attempted use MUST be logged (`promo_code_use`), allowed or blocked with a reason. [CONFIRMED; writes SIGN-OFF D3]
- **FR-062** Market-maker approval queue, approval recorded with the numbers seen (unique per listing), cost panel; `market_maker.approve` (CEO, Finance). [CONFIRMED]
- **FR-063 [SIGN-OFF Finance]** A market-maker purchase with a valid code on an approved piece older than the setting is priced like any purchase (normal locked price and deposit) and MUST settle with commission and its VAT 0 (minimum not forced), the spread line credited to the dealer's available wallet instead of `dahab_spread` (a negative spread stays with Dahab), and the seller's proceeds equal to an ordinary sale's at the same rates; only the buyer invoice is issued (DH012 already keys on commission 0); the monthly cap is enforced under a row lock and counts uses whose buy request is open or settled. **Not built or enabled until Finance confirms the allocation and its ledger, invoice and VAT treatment.** [CONFIRMED Part 3 §8, §2.5; DECIDED D3]
- **FR-064 [SIGN-OFF Finance]** The tied customer sees approved pieces marked, with a filter, through an **authenticated** customer read (`/customer/me/market/listings` [PROPOSED]); the public `/market/listings` is unchanged; nobody else sees the mark. Built with FR-063. [CONFIRMED blueprint §4, design]
- **FR-065** `first_sale` codes and the first-sale advance are NOT built here (a later money spec); percentage-off codes, usage counts and date windows are NOT built (undefined). Their customer-app parts stay hidden. [DECIDED Q5]
- **FR-066** The customer app's staff screens (`R.codes`, `R.codeuses`, `R.mmapprove`, `R.compensate`) and `admin_screens.dart` are removed; the sell form's promo card stays hidden until a customer-side code use is defined. [CONSTRAINT]

### F. Switches

- **FR-070** Category controls per schema `category_control` (category NOT NULL, as the schema has it): three levels per category, reason required, EN + AR customer message, audited, idempotent, cleared with actor and time; *Stop everything* writes one `stop_everything` row per category in one transaction and is cleared the same way. Permissions: stop new — CEO, COO, Operations; pause and stop everything — CEO, COO (D1). [CONFIRMED schema §14; DECIDED D1]
- **FR-071** Effects: *stop new* → new listings refused with the message; *pause* → as above plus pieces in state `live` hidden from every market read until reopened (pieces with a request stay and may be requested), waiting requests for more time answered *refused — category paused* and new ones refused (D4), sellers in the category told; *stop everything* → in every category no new listings and no buy requests, no new withdrawal requests and no staff release, customer cancel of a pending withdrawal still allowed, held money stays held, a paused banner for customers, both founders emailed and a Dashboard banner while active (D5). Anything with a locked price always finishes normally. [CONFIRMED blueprint §7, design, Part 2; DECIDED D4, D5]
- **FR-072** Visibility switches for the four items in the design, stored as settings in a new *visibility* group (blueprint §8), changed only through their own endpoint with `visibility.manage` (CEO, COO), audited with the value replaced; the generic settings endpoint refuses that group. [CONFIRMED design; DOC blueprint §8; DECIDED D1]
- **FR-073** The customer app shows the paused/stopped states and honours visibility switches. [CONSTRAINT]

### G. People to watch

- **FR-080** A read-only list per the design (threshold, period, signals, export) on the Inspections page; no suspension, no alert, no change to any record; `people_to_watch.view` (CEO, COO); each read audited. [CONFIRMED; DECIDED D1]
- **FR-081** Each signal shows its measured figure (n of m and, for weight, the average difference) and rows sort by the strongest ratio; no threshold labels anyone. [DECIDED D6]

### H. Invite a friend

- **FR-090** Not built; `R.invite` and the account menu row are hidden from customers until the owner defines referral (OD-2). Never on the rating screen. [OWNER; CONSTRAINT]

### Security and integrity (all parts)

- **FR-100** Every POST/PATCH requires `Idempotency-Key`; every write is audited and names the staff actor. [CONSTRAINT]
- **FR-101** Content tables are not customer data (no RLS needed) except acceptances (existing forced RLS) and promo-code uses (customer reads none; staff only) [PROPOSED]; published history is append-only by database guard. [CONSTRAINT]
- **FR-102** Texts are plain text; the app and the email renderer escape them; no HTML or links are interpreted from a text except a template's `{link}`. [CONSTRAINT]
- **FR-103** Public reads are rate-limited like `/reference/*` (`public.market`). [CONSTRAINT]
- **FR-104** Every migration of this spec has a working `down()` that **refuses** to run while data it would destroy exists (published texts, legal versions newer than the seeds, active or past controls, promo codes, uses or approvals), as spec 018's migration does; rollback with data is a deliberate, reviewed operation. [CONSTRAINT Constitution]
- **FR-105** Concurrency: two publishes at once produce two consecutive bundles and no lost draft; two legal publishes of the same code produce consecutive versions or one 409; replays with the same `Idempotency-Key` return the first answer. [CONSTRAINT]

### Key Entities *(requirements, not DDL — the plan mirrors `docs/Database schema/*.sql`)*

- **Text key** — registry row: key, area, where, defaults EN/AR, placeholders, max length, app (customer app / notification). Seeded from code; not editable by staff except texts.
- **Text version** — key, version, EN, AR (empty for a default marker), state (draft / published / superseded), editor, publisher, published date; drafts may be changed or discarded, published and superseded rows never.
- **Bundle** — a monotonically increasing published version number for the customer app.
- **FAQ entry** — question/answer EN/AR, position, visible; versioned like texts.
- **Notification template** — key per event/audience/part (sms, email subject, email body), versioned like texts.
- **Legal document version** — existing table, plus an immutability guard; acceptances gain contexts `signup`, `material_reaccept`.
- **Promo code / use / market-maker approval** — existing schema tables (section 15), unchanged columns; only `market_maker` rows are created in this spec. [DECIDED Q5]
- **Category control** — existing schema table (section 14), unchanged columns.
- **Visibility switches** — four settings in group *visibility* [DOC blueprint §8].
- **Permissions** — `content.edit`, `legal.publish`, `promo.manage`, `market_maker.approve`, `category.stop_new`, `category.pause`, `platform.stop_everything`, `visibility.manage`, `people_to_watch.view` [PROPOSED names; seeds per D1], each seeded in the phase that ships its feature.

## Proposed surfaces *(names [PROPOSED]; the plan fixes them)*

| Surface | Change | Class |
|---|---|---|
| `GET /reference/app-texts` (public) | new: published bundle + version | Non-breaking |
| `GET/PUT/POST/DELETE /dashboard/app-texts*`, `/dashboard/faq*` | new: list, draft, publish, revert, export (FAQ entries ride in the bundle's `faq[]`; templates are `kind=notification` rows of the same endpoints) | Non-breaking |
| `GET /reference/legal-documents` | `data[]` unchanged; adds `declarations[]` | Non-breaking |
| `GET /reference/controls` (public) | new: active controls and visibility | Non-breaking |
| `GET/POST /dashboard/legal-documents*` | new: list with counts, history, publish | Non-breaking |
| `GET /customer/me` (and sign-in/refresh answers) | add `legal_acceptance_required: [ {code, version, legal_doc_id} ]` | Non-breaking (optional field) |
| `POST /customer/me/legal-acceptances` | new: accept a material version | Non-breaking |
| Registration final step | optional `terms_legal_doc_id`, `privacy_legal_doc_id` | Non-breaking (optional fields) |
| `/dashboard/promo-codes*`, `/dashboard/market-maker*`, `/dashboard/category-controls*`, `/dashboard/visibility*`, `/dashboard/people-to-watch` | new | Non-breaking |
| `/market/listings` (public) | pieces of a paused category in state `live` are absent | Behaviour change on pause only |
| `GET /customer/me/market/listings` · optional `promo_code` on `POST /customer/me/buy-requests` | **[SIGN-OFF]** market-maker mark/filter and code use — not built before Finance confirms D3 | Non-breaking when added |
| New refusal codes | `content_invalid_placeholder`, `content_language_missing`, `content_too_long`, `content_unchanged`, `content_draft_changed`, `content_key_read_only`, `legal_acceptance_required`, `legal_document_not_current`, `category_stopped`, `category_paused`, `platform_stopped` … | New codes on new paths; `legal_acceptance_required`, `category_*` and `platform_stopped` on existing listing/buy/withdrawal/extension POSTs are **potentially breaking** for clients that switch on codes — both frontends updated in the same release |
| Staff permission catalogue | nine new codes, each added in the phase that ships its feature | Potentially breaking only for exhaustive permission maps — Dashboard adds them |

## Dashboard requirements

Types → services → composables → pages, per `CLAUDE.md` order. Un-hide and build: **App text** (`/dashboard/content`: list, filters by area, edit EN/AR with placeholder check, draft badge, Publish changes, revert, history, export, *Notifications* tab for templates, *Help* tab for FAQ), **Terms and versions** (`/dashboard/docs`), **Promo codes** and **Market maker approvals** (staff side; 6A), **Switches** with a Stop-everything banner on every page while active; **People to watch** panel on Inspections gated by `people_to_watch.view`. Permission strings added to `src/types/staff.ts`. English UI. Remove nothing existing.

## Customer App requirements

A text service (load bundle, cache, fall back) and a `context.k(key, defaultEn)`-style lookup [PROPOSED] that keeps the code's English and Arabic as defaults; Stage 1 moves the spec 018 screens; later stages per FR-008. Help reads the FAQ; the legal acceptance screen (first acceptance and material) and sign-up sending the shown terms/privacy ids; paused/stopped notices; visibility switches honoured; market-maker mark, filter and code only after Finance sign-off (6B); remove `admin_screens.dart` and its routes; hide Invite; `mockScreens` updated; fake backend and flow tests extended.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001** A published text change reaches every customer with the app open within 15 minutes, and every customer at their next app start or return to the app, in both languages, without an app release. [DECIDED Q3]
- **SC-002** With the Backend unreachable, 100 % of screens render with their code texts and no blank or raw placeholder (verified by the app's tests with the network off).
- **SC-003** 0 customer-visible texts in the Stage 1 screens are hard-wired without a key; every later stage reports the same for its screens.
- **SC-004** Every legal version ever published is retrievable unchanged; 100 % of acceptances name document, version, time and device.
- **SC-005** A customer with a required, unaccepted `terms`/`privacy` version gets 100 % of state-changing requests refused until they accept; an accepted one is never asked again for that version.
- **SC-010** A setting change reaches open apps' placeholder values within 15 minutes with no text republished.
- **SC-011** Market-maker customer behaviour is absent (0 routes, 0 fields) until Finance signs off D3.
- **SC-006** 100 % of customer notifications are editable; 0 sends fail because of a template (fallback test).
- **SC-007** 0 mock screens remain in the customer app for the items of this spec, except those the owner leaves blocked (Invite; the first-sale/percentage promo card), which are hidden rather than shown as real.
- **SC-008** Every content, legal, promo, control and visibility change appears in the audit log with the staff member's name.
- **SC-009** Every new or changed Arabic string is listed in Appendix B as AR-DRAFT.

## Test requirements

Pest through the HTTP boundary on `dahab_wt019` only: registry seeding; draft/publish/revert/versioning; placeholder and length validation; bundle read and not-modified; permissions per code (with and without); audit; legal publish (immutability guard, numbering, counts, CEO-only seed), material re-acceptance (sign-in flag, acceptance, refusal paths), signup acceptance; template rendering with overrides and every fallback; code/link placeholders enforced; FAQ; concurrency (two publishes at once, two legal publishes of one code, idempotent replays); rollback refusal when data exists; MM staff side (codes, approvals, use log, cost) and, after sign-off only, the MM purchase ledger shape and the cap race; category controls and their effects on listing, buy request, withdrawal and market reads; visibility; People to watch signals; permission catalogue and audit inventories extended. Dashboard: type-check, lint, build. Flutter: analyze, test (fallback with network off, bundle override, setting-only refresh, first slice, Help, first and material acceptance, sign-up ids, removed admin routes), build web. People to watch panel absent for IGI; audit entries name the version replaced (admin-roles §6).

## Documentation updates

`#[OA]` on every new/changed endpoint and `composer swagger:generate`; Postman requests; `docs/platform/api-contract.md` (bundle read, new codes); Technical Spec "Changed by spec 019" notes: Part 1 §4.1–§4.3 (codes, the CEO-only legal decision), Part 2 §10 (content, legal, promo, MM, controls), Part 3 §8 / §9.3; schema SQL for the new tables and guards; `docs/features/app-content.md`; `CLAUDE.md` current state after implementation.

## Decisions and sign-offs

| Item | Status | Resolution |
|---|---|---|
| OD-1 Legal publishing / payout declaration | **Decided D1** | CEO only; `payout_account_declaration` managed (FR-020) |
| OD-2 Referral | **Open — owner** | Hidden until defined; blocks nothing |
| OD-3 Promo kinds | Decided Q5 | Market-maker only |
| OD-4 MM device | **Resolved [DOC]** | Blueprint §4: bound to the account, any device |
| OD-5 Material re-acceptance | **Resolved [DOC] + Decided D2** | Terms §13 gate; first acceptance for everyone (D2); optional sign-up ids |
| OD-6 People to watch | **Decided D1, D6** | CEO + COO; figures without thresholds |
| OD-7 Stop everything | **Resolved [DOC] + Decided D5** | No new withdrawals, no release, cancel allowed; founders emailed + Dashboard banner |
| OD-8 Visibility storage | **Resolved [DOC]** | Settings, group *visibility* (blueprint §8); CEO + COO (D1) |
| D3 Market-maker spread | **[SIGN-OFF] Finance** | Allocation, ledger, invoice and VAT treatment — blocks 6B |
| D4 Pause and requests for more time | Decided D4 | Waiting ones refused, orders run on |
| D7 Code/link templates | Decided D7 (interim) | Read-only until the owner names an editor — **open: who may edit them** |
| OI-1.3 Alert routing (beyond D5) | **Open — owner** | Not needed for this spec |
| Terms text (v1, §5.5 and every legal body) | **[SIGN-OFF] lawyer** | Nothing is published by this spec; Appendix A is a draft |
| Arabic (Appendix B, FAQ seed) | **[SIGN-OFF] owner** | AR-DRAFT until approved |
| Spec 018 items | Open (not decided here) | Finance on no seller invoice at 0 %; lawyer on §5.5 |

## Rollout and verification

1. Branches `feature/app-content` exist locally in all three repos from the verified bases; nothing pushed.
2. Order per stage: Backend → contract → Dashboard → Customer App.
3. Tests only on `dahab_wt019` / `dahab_wt019_dev` (owned by `dahab`, created by the owner). The main `dahab` database is never used.
4. Known failures on `main`, reported not fixed: 3 Invoices tests (demo issuer config), 2 Flutter withdraw tests.
5. Report in the Step 5 shape of `CLAUDE.md`.
6. Deploy step for every phase that adds keys: `php artisan app-texts:sync` after migrations.
7. Legal sequencing (FR-027a): Backend gate + app acceptance screen live → lawyer approves the text → CEO publishes `terms`/`privacy`.
8. Market-maker purchases (6B) start only after Finance's written sign-off of D3.

## Assumptions

- **A1** "Every user-facing string" means every **customer-facing** string (customer app + customer notifications); Dashboard staff labels are not editable.
- **A2** One shared draft per key (not per staff member), with an optimistic version check.
- **A3** Only the words of notifications are editable; which channels a message uses stays in code.
- **A4** Messages are rendered when the queued job sends them (as built), so a message queued before a publish and sent after it uses the newly published text (research R13).
- **A5** The nine prototype FAQ entries are seeded as an unpublished draft, not as published content.
- **A6** Maximum length per key = the larger of 2× the default's length and a floor (plan sets the numbers).
- **A7** *(Superseded by D6: no thresholds.)*
- **A8** Support contact details stay in config (spec 017).
- **A9** Legal documents use no placeholders (FR-022); the §5.5 draft writes "twelve" and must be checked against `deadline.free_relist_working_hours` at publication.

---

## Appendix A — Proposed Terms §5.5 (DRAFT for legal review; AR-DRAFT, needs owner approval)

> Not seeded, not published. Replaces the draft's last sentence of §5.5 only when the lawyer approves and the CEO publishes a new `terms` version. Spec 018's legal-review item stays open.

**English (proposed)**

All purchases become final once the inspection is confirmed, to protect both sides from movements in the gold price. When you pay the balance, the money is distributed at that moment: the seller is paid and Dahab takes its commission and, on gold, the spread. Collection afterwards is a physical handover only and moves no money.

If you change your mind after collecting, you may put the piece back on the market once, with no Dahab commission, within twelve working hours counted from the moment the branch hands it to you. Working hours are those of that branch, so closures and public holidays are excluded. The piece goes back on the market as a new listing in your name, at the price you set; your purchase is not reopened and stays final. When that listing sells, no commission and no VAT on commission are charged, but on gold the difference between the buying and selling rates (the spread) still applies. As with any sale, once you accept a buyer you take the piece to the branch at your own cost, and it is inspected again free of charge. You may do this once for each purchase, and a piece you buy from such a listing does not carry this offer again. If the time passes, you can still list the piece in the ordinary way, at the ordinary commission.

**Arabic (proposed) — AR-DRAFT, needs owner approval**

كل عمليات الشراء بتبقى نهائية بمجرد تأكيد الفحص، حمايةً للطرفين من تقلبات سعر الذهب. ولما تدفع باقي المبلغ، الفلوس بتتوزّع في اللحظة دي: البائع بياخد مستحقاته ودهب بتاخد عمولتها، وعلى الذهب بتاخد الفرق (السبريد). والاستلام بعد كده تسليم فيزيائي بس ومفيش أي فلوس بتتحرك فيه.

ولو غيّرت رأيك بعد الاستلام، تقدر تعرض القطعة للبيع تاني مرة واحدة من غير عمولة دهب، خلال اثنتي عشرة ساعة عمل محسوبة من لحظة ما الفرع يسلّمك القطعة. ساعات العمل هي ساعات الفرع ده، فبتتستبعد أيام الإغلاق والعطلات الرسمية. القطعة بتنزل السوق كإعلان جديد باسمك وبالسعر اللي تحدده؛ وعملية الشراء بتاعتك ما بتتفتحش تاني وبتفضل نهائية. ولما الإعلان ده يتباع، مفيش عمولة ولا ضريبة قيمة مضافة على العمولة، لكن على الذهب الفرق بين سعر الشراء وسعر البيع (السبريد) بيفضل مطبّق. وزي أي بيعة، أول ما تقبل مشتري بتوصّل القطعة للفرع على نفقتك، وبتتفحص تاني من غير مقابل. تقدر تعمل ده مرة واحدة لكل عملية شراء، والقطعة اللي تشتريها من إعلان زي ده ما بيكونش ليها نفس الميزة. ولو المدة عدّت، تقدر برضه تعرض القطعة بالطريقة العادية وبالعمولة العادية.

**What changed against the draft, and why** (each from spec 018): "once" (FR-004/FR-011); "counted from the moment the branch hands it to you" and branch working hours (FR-001/FR-003); "a new listing in your name … your purchase is not reopened" (FR-005, D6); "the spread still applies" (FR-020); "no VAT on commission" (FR-020); "a piece you buy from such a listing does not carry this offer" (FR-013); "inspected again free of charge" (FR-023, D12); "at your own cost" kept, now tied to accepting a buyer; "ordinary way, ordinary commission" after expiry (FR-011).

## Appendix B — Arabic strings introduced or changed by this spec (all AR-DRAFT, need owner approval)

| Where | English | Arabic (AR-DRAFT) |
|---|---|---|
| Terms §5.5 | Appendix A | Appendix A |
| Help, empty | Nothing here yet. Contact us if you have a question. | لسه مفيش أسئلة هنا. كلمنا لو عندك سؤال. |
| Material terms prompt, title | We updated our terms | حدّثنا الشروط بتاعتنا |
| Material terms prompt, body | Please read and accept the new version to carry on. | من فضلك اقرا النسخة الجديدة ووافق عليها عشان تكمّل. |
| Material terms prompt, button | I accept | موافق |
| Category stopped (default message) | We have paused new listings in this category for a short while. Anything already listed carries on as normal. | وقفنا الإعلانات الجديدة في الفئة دي لفترة قصيرة. أي حاجة معروضة بالفعل بتكمّل عادي. |
| Accept terms (first time, D2), title | Please accept our terms | من فضلك وافق على الشروط بتاعتنا |
| Request for more time refused on pause | Refused — category paused. Your deadline has not changed. | اترفض — الفئة متوقفة مؤقتاً. ميعادك ما اتغيرش. |
| Platform stopped banner | Dahab is paused for a short while. Your money and pieces are safe. | دهب متوقفة لفترة قصيرة. فلوسك وقطعك في أمان. |
| FAQ seed (9 entries) | prototype English | to be drafted in the plan, AR-DRAFT |

The design's "Message to sellers" for a pause and every Dashboard-entered customer message are written by staff, not by this spec. The Stage 1 keys reuse the existing English and Arabic of spec 018 unchanged; the spec 018 estimate line (A6) stays pending owner approval as spec 018 left it.
