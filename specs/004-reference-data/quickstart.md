# Quickstart — Reference Data

```bash
DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
composer test -- --filter=Reference
composer test -- --filter=WorkingCalendar
```

## Expected

- `GET /dashboard/karats` as `ceo@` returns five karats, with 20 and 22 off.
- `POST /dashboard/karats/22/toggle {enabled:true}` as `finance@` → 200; as `operations@` → 403.
- As `operations@`: create a branch with a Sunday–Thursday 10–18 week, then add an all-branch holiday. Both are audited.
- The resolver scenario suite (SC-002) passes. For example, Thursday 16:00 + 12 working hours at a Sunday–Thursday 10–18 branch = Monday 12:00.
- `PUT /dashboard/staff/{igi}/branch {branch_id: 1, reason}` as `ceo@` → 200; the IGI account's profile shows `branch_id: 1`.
- Dashboard: the Karats and Branches and hours pages load and allow the actions above for the right accounts.
