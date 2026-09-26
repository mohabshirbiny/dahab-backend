# Quickstart — Customer Data Isolation

Run DB commands as the `dahab` user (never the `.env` `postgres` superuser: superusers bypass RLS entirely, so nothing would be tested).

```bash
DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed
composer test -- --filter=Isolation
composer test
```

## Expected

1. `CustomerRowIsolationTest`: as customer A, unfiltered reads of each table return only A's rows. Updates or deletes of B's rows affect 0 rows, and inserting a row owned by B fails.
2. `ActorBindingTest`:
   - no actor → 0 rows and writes refused;
   - after a request or job for A ends, the next unit on the same connection sees nothing of A's, including after an exception.
3. `ElevationTest`: registration, login (known and new device), OTP, staff customer list and identity review all work. A queued job writes one `rls.system_elevation` audit row as the system actor.
4. `CustomerTableIsolationTest`: fails, naming the table, if a table with `customer_id` / `actor_customer_id` / `buyer_id` / `seller_id` lacks forced RLS.
5. The whole existing suite passes (SC-004).

## Manual check (psql as `dahab`)

```sql
SELECT count(*) FROM customer;                                   -- 0 (no scope)
SELECT set_config('app.rls_scope','customer',false),
       set_config('app.current_customer_id','<uuid of A>',false);
SELECT count(*) FROM customer;                                   -- 1
SELECT set_config('app.rls_scope','staff',false);
SELECT count(*) FROM customer;                                   -- all
```
