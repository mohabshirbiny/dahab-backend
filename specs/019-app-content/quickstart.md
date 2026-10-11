# Quickstart — validating spec 019

## Prerequisites

- Databases `dahab_wt019` (tests) and `dahab_wt019_dev` (seeded app), owned by `dahab`, created by the owner. Never the main `dahab` database.
- Backend env for every command: `DB_DATABASE=… DB_USERNAME=dahab DB_PASSWORD=…` passed explicitly.

```bash
# Backend (dahab-backend)
DB_DATABASE=dahab_wt019_dev DB_USERNAME=dahab DB_PASSWORD=… php artisan migrate:fresh --seed
DB_DATABASE=dahab_wt019_dev … php artisan app-texts:sync          # registry from database/data/app_text_keys.json + PHP catalogue
DB_DATABASE=dahab_wt019 … ./vendor/bin/pest tests/Feature/Content  # targeted, between stages
DB_DATABASE=dahab_wt019 … composer test                              # full suite: baseline once, end once (~30 min, sequential)
./vendor/bin/pint --test && composer swagger:generate

# Dashboard
npm run type-check && npm run lint && npm run build

# Customer App
flutter analyze && flutter test && flutter build web --release
```

Known failures on `main` (report, do not fix): 3 Invoices tests (demo issuer config), 2 Flutter withdraw tests.

## Scenarios (each maps to a user story; details in `contracts/app-content-api.md`)

1. **Text override and fallback (US1, US2).** As Operations, draft `order.free_relist.card_title` EN+AR → `GET /reference/app-texts` unchanged (draft invisible) → *Publish changes* → bundle version +1, ETag changes, the app shows the new title in both languages. Stop the Backend → saved copy; clear app storage → code text. Revert to default and publish → the key leaves the bundle and the app shows its own default.
2. **Validation and concurrency (US1).** Draft with an unknown placeholder → `422 content_invalid_placeholder`; empty Arabic → publish refused; two staff saving one draft → second `409 content_draft_changed`; two publishes at once → two consecutive bundles; replay with the same `Idempotency-Key` → first answer.
3. **Setting change (US2).** Change `commission.gold_pct` without republishing → the bundle's ETag changes; an FAQ answer with `{commission_gold_pct}` shows the new value within 15 minutes.
4. **Legal (US3).** As COO publish → 403. Ship the gate + app screen first. As CEO publish `terms` v1 → every existing customer gets `legal_acceptance_required` (reason `first`); any POST except accept/sign-out → `409`; accept → cleared (`first_acceptance` row). New sign-up sending `terms_legal_doc_id` → `signup` row, no prompt. Publish v2 material → prompt again (`material`). Two publishes of one code at once → consecutive versions or `409 legal_version_conflict`.
5. **Template (US4).** Override `order.collected.buyer.sms` (AR) → hand over an order → SMS, email body and inbox show it with the free-relist end filled; try to draft an OTP template → `409 content_key_read_only`; corrupt override → code text sent; golden test green with no overrides.
6. **FAQ (US5).** Publish 3 entries, one with `{commission_gold_pct}` → Help shows them in order with the live value; no MOCK banner.
7. **Switches (US7).** Operations stops new gold → listing create refused with the message; COO pauses diamond → unrequested diamond pieces gone from every market read, requested ones still visible and requestable, a waiting request for more time answered *refused — category paused* in the COO's name, the order's deadline unchanged; clear → pieces back. COO stops everything → listing, buy request and new withdrawal refused, staff release refused, customer cancel of a pending withdrawal works, both founders receive the email, every Dashboard page shows the banner. Hide *payout averages* → app hides them.
8. **Market makers, staff side (US6, 6A).** Finance creates `MM-TEST` tied to a market-maker customer → audited; a `first_sale` code → `422 promo_kind_not_available`; approve a 9-day-old piece; a 3-day-old one → `422 listing_too_new`; cost panel zero. **6B scenarios run only after Finance signs off D3.**
9. **People to watch (US8).** A seller with 7 sales, 6 short → listed with "6 of 7 came in under" first; a customer with 4 transactions → absent; an IGI account → panel absent, read 403.
10. **Rollback.** Each migration rolls back on an empty database; with data present (a published text, a legal v2, a control, a promo code) its `down()` refuses.
11. **Mock removal.** `R.codes`, `R.codeuses`, `R.mmapprove`, `R.compensate` and the payout-averages toggle are gone; Invite unreachable; `mockScreens` = `{ R.branch, R.editprice }`.
