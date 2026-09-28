# Quickstart: Audit Log Viewer

Run artisan as the `dahab` DB user. After migrating, run `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder` to add the two audit permissions.

1. **Catalogue**: `composer test -- --filter=AuditCatalogue` — every `AuditEvent` has a label and a category.
2. **CEO**: after a price entry, a karat toggle and a role change, `GET /dashboard/audit-log` lists all three, newest first, with labels, subjects and summaries. `category=pricing` keeps the price entry only. Sign-ins do not appear until `category=sessions`.
3. **Own actions**: as Finance, the list holds Finance's entries only; opening the CEO's entry by id → 404; `actor=<ceo id>` → empty.
4. **Pagination**: with 120 entries, pages of 50 via `next_cursor` return all 120 once each, even when a new entry is written between pages.
5. **Export**: `GET /dashboard/audit-log/export?category=pricing` downloads a CSV that opens in Excel with Arabic intact and the same rows as the list.
6. **Dashboard**: the Audit log page shows the chips, period, count, table, Load more, entry details and Export; the menu item is hidden for IGI.
