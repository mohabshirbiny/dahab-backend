# Dashboard performance

> File: `docs/features/dashboard-performance.md` · Branch: `claude/dahab-dashboard-performance-4bfab5` in Backend and Dashboard
> Status: done (not deployed) · Date: 2026-10-28

## Goal

Staff found the Dashboard slow. The performance audit of 2026-10-20 named the causes: the Overview's two
endpoints read the whole ledger on every request, the page waited for all of it, six badge requests polled every
minute, and each staff member had 60 requests a minute across all their tabs. This work fixes those causes.
Phase 0 (measuring production: 429s, table sizes, a HAR of a cold load) is still to do on the server.

## Impact Summary

```
Backend:      YES
Database:     YES (one index)
API:          YES (one new endpoint, non-breaking)
Dashboard:    YES
Customer App: NO — none of the changed endpoints is used by the app; /reference/controls keeps its answer
Auth:         YES (the general API limiter for staff)
Permissions:  NO (nav-counts reuses each page's code)
```

## Backend Impact

- `ShowLedgerOverviewAction` (`GET /dashboard/wallets/overview`): one pass over the postings, summed per account
  then per kind, instead of the `solvency_check` and `ledger_global_zero` views (same figures and signs: bank =
  −SUM(bank)).
- `ShowOverviewAction` (`GET /dashboard/overview`): *held now* only for the selected orders' buy requests; earnings
  read from the two Dahab accounts' postings; *needs decision* counts in the same query (window count).
- `OverviewCache`: the global sections (`wallets`, `earnings`, `orders`, `this_month`) reused for
  `dahab-finance.overview_cache_seconds` (env `DAHAB_OVERVIEW_CACHE_SECONDS`, default 30; 0 turns it off). One key
  per section, never per viewer; a section is read only after the viewer's permission check. *Needs decision*
  is not cached. No money action reads these values. `phpunit.xml` sets 0; `OverviewCacheTest` turns it on.
- `NavCountsAction` (`GET /dashboard/nav-counts`): every navigation badge and the Users tabs in one read.
- The `api` rate limiter: a signed-in staff member gets `dahab-auth.staff_api_per_minute` (env
  `DAHAB_AUTH_STAFF_API_PER_MINUTE`, default 240) a minute; customers and anonymous callers keep 60. The sign-in,
  MFA, device code, refresh and reset limiters are unchanged.
- `Settings::bools()`: the switches' visibility flags in one read (still fresh each request), for
  `/reference/controls`.

## Database Impact

Migration `2026_10_28_000030_ledger_txn_buy_request_index`: partial index `idx_ledger_txn_buy_request` on
`ledger_transaction(buy_request_id) WHERE buy_request_id IS NOT NULL`. Read path only; nothing posted changes.

## API Changes

`GET /api/v1/dashboard/nav-counts` (`auth:staff`, `staff:access`, `staff.standing`; no single permission). A key is
absent (not 0) without its code. Each count uses the filter of the list it badges:

| Key | Code | Filter (same as) |
|---|---|---|
| `customers_pending`, `customers_by_status` | `customer.view` | `customer.status` (Users list and its tabs) |
| `topups_open` | `topup.match` | status pending or on hold, any date (Incoming transfers) |
| `listings_in_review` | `listing.review` / `request_changes` / `takedown` | `state = in_review` (`meta.counts.in_review`) |
| `withdrawals_waiting` | `withdrawal.release` | requested or under review (`figures.waiting_count`) |
| `disputes_unresolved` | `dispute.handle` | open or passed on (`meta.counts`) |
| `listing_reports_open` | `listing_report.handle` | `state = open` (`meta.counts.open`) |

`meta.generated_at` is the time of the read. Classification: non-breaking (new endpoint). The Overview endpoints
keep their shape; their figures can now be up to 30 seconds old.

## Dashboard Impact

- Overview: each panel loads and fails on its own (orders and decisions, wallets, gold price, this month).
- Sidebar: one `useNavCountsQuery` (60 s) replaces six list requests; the Users tabs read the same cache entry
  (`select`), so the five count requests and the duplicate badge request are gone. Every successful mutation
  refreshes it (`MutationCache` in `plugins/vue-query.ts`). The six badge-only queries and services were removed.
- Polling: notices 30 s → 60 s plus a refresh when the bell opens; the stop banner 60 s → 120 s (a switch change
  still refreshes it at once).
- Bundle: Vuetify's icons from its built-in SVG set (`vuetify/iconsets/mdi-svg`), so the Material Design Icons
  font and its CSS are no longer loaded; Roboto trimmed to 400/500/700; the unrouted starter files
  (`HelloWorld.vue`, `pages/index.vue`, `pages/dashboard/index.vue`, `layouts/DashboardLayout.vue`) removed.
  `@mdi/font` stays in `package.json` (removing it changes the shared `node_modules`).

## Customer App Impact

Not affected — it uses none of the changed endpoints; `/reference/controls` answers the same.

## Measurements

Synthetic ledger of 1.8 M postings (scratch database, SQL alone, no RLS or PHP):

| Statement | Before | After |
|---|---|---|
| Wallet figures | 0.9–1.6 s | 0.5–0.8 s |
| Held per order (300 orders) | ~1.06 s | ~0.07 s |
| Earnings this month | 0.5–1.1 s | 0.35–0.6 s |

With the cache, a repeat within 30 s reads nothing. Dashboard build (`vite build`): main CSS 518 kB (128 kB gz) →
87 kB (28 kB gz); main JS 313 kB → 303 kB; `dist` 8.8 MB → 2.6 MB. Steady requests of one idle Overview tab: about
10 a minute → about 5 (nav counts, notices, overview, gold price, banner every two minutes).

## Tests

`tests/Feature/Overview/OverviewGoldenTest.php` (the new SQL against the original statements),
`NavCountsTest.php` (each key with its code only, equal to its list), `OverviewCacheTest.php` (reuse and expiry,
cached = fresh, no section to a viewer without its code, the staff limiter). `PrincipalIsolationTest` route count
updated (it was already two routes behind on `main`).
