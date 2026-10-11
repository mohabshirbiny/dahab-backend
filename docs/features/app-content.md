# App content — controls and content (spec 019)

> File: `docs/features/app-content.md` · Branch: `feature/app-content` in each affected repo
> Status: in progress — **first release only** (tasks T001–T026) · Date: 2026-10-11
> Spec: [`specs/019-app-content/`](../../specs/019-app-content/spec.md) (spec, plan, research R1–R17, data model, contract, tasks)

## Goal

Everything content-related in one place, delivered in releases. **This release** is the platform
every later part sits on: staff change any customer-app text from the Dashboard, publish a batch
of changes as one bundle, and the app shows the new wording without an app release — and never
breaks when the Backend, a key, a language or a placeholder is missing.

Not in this release (later phases of the same spec): legal publishing and the acceptance gate,
notification templates, FAQ, switches (categories and visibility), market-maker codes and
approvals, People to watch, the removal of the app's staff screens, text stages 2–7.

## Impact Summary

```
Backend:      YES — registry, versions, actions, public bundle, sync command
Database:     YES — app_text_key, app_text_version, app_text_bundle (+ DH017 guard)
API:          YES — GET /reference/app-texts; /dashboard/app-texts*, /dashboard/content/placeholders
Dashboard:    YES — App text page (content.edit)
Customer App: YES — text service, Stage 1 key catalogue (the spec 018 screens)
Auth:         NO
Permissions:  YES — content.edit (CEO, COO, Operations)
API models/types: YES — Dashboard src/types/content.ts; Flutter lib/core/text/*
```

Classification: **non-breaking** — new endpoints and one new permission string; nothing renamed or
removed. The app treats a missing bundle (404 from an older Backend, or any failure) as "no
overrides", so each side can deploy first.

## Backend Impact

- `app/Models/{AppTextKey,AppTextVersion,AppTextBundle}.php`, cast `Casts/PostgresTextArray`.
- `app/Actions/Content/{SaveDraftAction,DiscardDraftAction,RevertTextAction,PublishTextsAction,ListAppTextsAction,ExportAppTextsAction}.php`.
- `app/Support/Content/{SettingPlaceholders,PlaceholderValidator,BundleBuilder}.php`.
- `app/Console/Commands/SyncAppTexts.php` — `app-texts:sync`.
- `AppTextController` (Dashboard) and `ReferenceController::appTexts` (public).
- `StaffPermission::CONTENT_EDIT`, audit events `content.draft_saved|draft_discarded|published|reverted|exported`
  and category `content`; error codes below; SQLSTATE `DH017`.

### How a text reaches a customer

1. **Keys come from the app.** The Flutter code declares every text as a `TextKey`
   (`lib/core/text/keys/*.dart`); a test generates `assets/text/keys.json`, which is copied to
   `database/data/app_text_keys.json`. `php artisan app-texts:sync` registers the keys and their
   defaults, stamps `default_changed_at` when a default moved, and retires keys that left the
   manifest. Nothing is deleted.
2. **Staff edit.** A save is a *draft* — one shared draft per key — invisible to customers.
3. **Publish changes.** All drafts go live together as one bundle, under an advisory lock, after
   every draft is validated again. Any failure publishes nothing and lists every key.
4. **Revert.** *Revert to default* makes a default-marker draft; published, it ends the override
   (the bundle drops the key), so the app shows its own *current* default — never an old one
   pinned. *Restore* publishes an earlier value as a new version.
5. **The app reads the bundle** on start, on return to the foreground and every 15 minutes, keeps
   the last copy on the device, and falls back to its own text on any problem.

### Rules enforced on every save and publish

Both languages present · within the key's maximum (default `max(2 × default, 40)`) · only the
key's own `{placeholders}` and the setting placeholders · braces balanced · every required
placeholder (`{code}`, `{link}`) kept · different from what customers see now. A read-only key
(a template carrying a code or link) takes no draft (D7).

### Setting placeholders

`{commission_gold_pct}`, `{commission_stone_pct}`, `{commission_minimum_egp}`, `{vat_pct}`,
`{deposit_buyer_pct}`, `{reach_branch_hours}`, `{buyer_pay_days}`, `{collect_weeks}`,
`{seller_return_weeks}`, `{free_relist_hours}`, `{withdrawal_pause_hours}`,
`{weight_tolerance_pct}`, `{saved_max}`. The bundle carries their live values; its version tag
changes when any of them changes, even if no text is republished.

## Database Impact

Migration `2026_10_12_000010_app_content.php` (mirrored in `docs/Database schema/00_schema_full.sql`
and `05_schema_security.sql`, "Changed by spec 019"):

| Table | Purpose |
|---|---|
| `app_text_key` | The registry: key, kind (`app` · `faq` · `notification`), area, where, default EN/AR, placeholders, required placeholders, `read_only`, `max_length`, `default_changed_at`, `retired_at` |
| `app_text_version` | Versions: `draft` → `published` → `superseded`; `is_default_marker`; editor, publisher, bundle. Partial uniques: one draft and one published per key |
| `app_text_bundle` | One row per *Publish changes* |

Guard `DH017` (`content_history_immutable`): a published version only becomes superseded; a
superseded one never changes; history is never deleted; a bundle never changes; a read-only key
takes no version. No RLS (no customer data). `down()` **refuses** while any published version or
bundle exists (R16).

The permission is **not** a migration row: permissions follow the `StaffPermission` catalogue
(`SyncPermissionCatalogueAction` gives a new code to the CEO and its seed roles the first time it
appears), as for every code since spec 002.

## API Changes

| Method · Path | Notes |
|---|---|
| `GET /reference/app-texts` | Public, `throttle:public.market`. `{ tag, version, published_at, texts{key:{en,ar}}, faq[], settings{} }`. `ETag: "<bundle_version>-<settings fingerprint>"`, `If-None-Match` → `304`. Overrides of kinds `app` and `faq` only; never notification templates, retired keys or default markers |
| `GET /dashboard/app-texts` | `kind`, `area`, `q`, `changed`, `state` (`draft`·`published`·`default`), `include_retired`, `cursor`, `per_page` |
| `PUT /dashboard/app-texts/{key}/draft` | `{ text_en, text_ar, draft_version?, is_hidden? }` → draft |
| `DELETE /dashboard/app-texts/{key}/draft` | `204` |
| `POST /dashboard/app-texts/{key}/revert` | `{ to: "default" \| <version> }` → draft |
| `POST /dashboard/app-texts/publish` | `201 { bundle_version, published_at, keys[] }` |
| `GET /dashboard/app-texts/{key}/history` | every version with editor/publisher |
| `GET /dashboard/app-texts/export` | CSV (UTF-8 BOM), audited |
| `GET /dashboard/content/placeholders` | setting placeholders with live values |

Every write needs an `Idempotency-Key`. New error codes: `content_draft_changed` 409,
`content_key_read_only` 409, `content_key_retired` 409, `content_no_draft` 404,
`content_invalid_placeholder` 422, `content_missing_required_placeholder` 422,
`content_language_missing` 422, `content_too_long` 422, `content_unchanged` 422,
`content_nothing_to_publish` 422, `content_invalid` 422 (`details.keys`),
`content_history_immutable` 409 (DH017 — a bug if reached through the API).

## Dashboard Impact

`src/types/content.ts`, `src/services/content.service.ts`, `src/composables/useContent.ts`,
`src/pages/content/index.vue` + `src/components/content/*`; the `content` entry of the navigation is
un-hidden behind `content.edit`; `content.edit` added to `src/types/staff.ts`.

## Customer App Impact

`lib/core/text/` (`TextKey`, `TextBundle`, `TextsController`, `TextsStore`, `context.k`),
`lib/core/text/keys/orders_after_collection.dart` (47 keys — the spec 018 free-relist card and
notes, the relist form, the rating screen), `lib/services/api/content_api.dart`; the Stage 1
screens read their text through `context.k`. No customer-visible word changes, no invite or
referral text (spec 018 FR-038). Tests: `text_manifest_test`, `texts_test`, `stage1_keys_test`.

## Authentication / Authorization

Dashboard routes: `auth:staff`, `staff.standing`, `staff.permission:content.edit`, `idempotent` on
writes. `content.edit` is seeded to the CEO (every code), the COO and Operations; editable in Roles.
The public bundle needs no account.

## Error Handling

The Dashboard shows the code's own message next to the field or key. A `content_invalid` publish
lists each key with its reason; `content_draft_changed` asks the editor to reload. The app never
shows an error for a text: any failure keeps the saved copy or the code's text.

## UI States

Dashboard: loading, empty (no keys yet — run `app-texts:sync`), draft badge, *default changed*
badge, *Not used by the current app* for retired keys, locked row for read-only keys, publish
confirmation with the key list, permission-denied (page absent from the navigation).
App: no state — a text is always there.

## Testing

Backend (Pest, `dahab_wt019` only): `tests/Feature/Content/*` (sync, drafts, publish, revert,
bundle, permissions, export, migration rollback, concurrency), `tests/Unit/Content/*`, the
permission and audit catalogue tests. Dashboard: `npm run type-check`, `lint`, `build`. Flutter:
`flutter analyze`, `flutter test`, `flutter build web --release`.

## Breaking Changes

None.

## Migration / Compatibility

**Deploy order: Backend → Dashboard → Customer App.**

1. `php artisan migrate`
2. **`php artisan app-texts:sync`** — registers the app's keys from `database/data/app_text_keys.json`
   (run after migrations in every deploy that adds keys; safe to repeat).
3. Roles: `content.edit` is created and given to the CEO, the COO and Operations on the first
   permission sync (`php artisan db:seed --class=DashboardRolesAndPermissionsSeeder`, as in earlier
   specs).

Older apps that never ask for the bundle are unaffected; an app that asks an older Backend gets a
404 and keeps its own text. Rollback: `migrate:rollback` refuses once anything is published.

When a screen's strings move to keys in a later stage, the manifest is regenerated
(`UPDATE_TEXT_MANIFEST=1 flutter test test/text_manifest_test.dart`), copied to
`database/data/app_text_keys.json`, and `app-texts:sync` is run.

On the dev database: `DB_DATABASE=dahab_wt019_dev … php artisan migrate:fresh --seed` then
`DB_DATABASE=dahab_wt019_dev … php artisan app-texts:sync`.

## Release rules added by later phases

- **Legal acceptance (T040).** The app with the legal-accept screen (spec 019 Phase 5) must be
  live before the CEO publishes any `terms` or `privacy` version, or marks a version material.
  Publishing `terms` v1 gates **every existing customer** (D2: nobody has an acceptance row yet),
  so an older app would meet `409 legal_acceptance_required` with no screen to answer it.
  Terms §5.5 is only a proposal (`docs/legal/terms-5-5-proposal.md`); nothing is seeded.
- **Code and link messages (D7).** Messages that carry a one-time code or a link
  (`auth.*.sms`, `order.collection_code.*`, `withdrawal.confirmation_link.*`, …) are `read_only`.
  Editing one needs the separate permission `content.edit_secure` **in addition to**
  `content.edit`. It is seeded to no role: the CEO assigns it (CEO holds every code). The text
  must still contain its `{code}` / `{link}` — the validator refuses a template that drops it.
  Without the permission the Backend answers `409 content_key_read_only` and the dashboard
  shows the row locked.
- **Every screen's text (stages 2-7).** The customer app translates every sentence through
  `context.t`. The app's catalogue of keys is generated by `tool/gen_text_catalogue.py` (Flutter
  repo) from the sentences that have an Arabic entry in the app's dictionary, plus the regex
  patterns of `i18n.dart` (sentences with a number, a name or a date; the placeholders are
  `{amount}`, `{count}`, `{weight}`, `{text}`, …). Keys are `<area>.<words of the sentence>` and
  never change once released; the default is the English the screen always showed, the Arabic
  default is the dictionary's, so no customer-visible word changed. A published override reaches
  the screen through the same call: the English the screen built is looked up in the catalogue,
  patterns by their expression. A sentence shown twice on different screens is one key. Run
  `tool/gen_text_catalogue.py`, then `UPDATE_TEXT_MANIFEST=1 flutter test
  test/text_manifest_test.dart`, copy `assets/text/keys.json` to `database/data/app_text_keys.json`
  and run `php artisan app-texts:sync`. `flutter test test/text_coverage_test.dart` fails when a
  sentence handed to a translated widget is not in the catalogue.
- **Notification templates (spec 019 US4).** Every SMS, email subject/body/action and inbox text
  is a key of kind `notification` (`<event>.<audience>.<part>`); the email body defaults to the SMS
  sentence until it is edited on its own (`…mail_body`). Publishing flushes the template cache
  after commit, so the next message uses the new wording; anything that cannot be filled falls
  back to the code text and never fails a send. `tests/Fixtures/notification-golden.json` pins
  the default output of all 146 cases.

## Open items that do not block this release

Finance sign-off on the market-maker spread (blocks only 6B); the lawyer on every legal text; the
owner's approval of the AR-DRAFT strings (spec Appendix B) and of the one Stage 1 sentence with no
Arabic today (`order.rating.given_note`); referral (OD-2); who is given `content.edit_secure` (D7: permission built, no role seeded);
alert routing beyond D5 (OI-1.3).

## Invite a friend (OD-2)

No document defines the referral rules, so nothing is built behind it. At the owner's request the prototype screen is back
in the customer app as a red-flagged MOCK (`lib/features/account/invite_screen.dart`, route `invite`): fake code and figures,
Share and Copy do nothing real. Its words are kept out of the App text catalogue until the owner defines the rules.

## Status of the later phases

Built and verified in this branch: legal publishing and acceptance, notification templates, Help, the generated screen
catalogue, Switches, market makers (staff side and purchases) and People to watch. **Finance has not signed off D3**
(the spread to the dealer): the purchase side was built on the owner's instruction and must be confirmed before it is used
with real money. Every Arabic string added by this spec is an AR-DRAFT (spec Appendix B) until the owner approves it.

