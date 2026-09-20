---
name: "laravel-actions-services"
description: "Put state changes in single-purpose Action classes with DB transactions, row locking, actor attribution, ledger entries, audit logging and after-commit events. Use for any business rule or anything that moves money, gold, listings or verification state."
argument-hint: "The state change (e.g. 'approve inspection') and its spec section"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

## Source of truth

Business rules and state machines come from `docs/Technical Spec/dahab-spec-part3-logic.md` and `docs/dahabctoblueprint.md`. Don't invent transitions. If a rule is missing, stop and raise it (`/speckit-clarify`).

## Shape

- `app/Actions/<Domain>/<Verb><Noun>.php`, `final`, one public `handle()` method, dependencies through the constructor.
- `app/Services/` only for reusable domain services used by several Actions (pricing, fee calculation), or for wrappers around external integrations behind an interface bound in a service provider.
- Inputs: the acting principal first, then a typed DTO or validated array. Output: the model it changed.

```php
final class ApproveInspection
{
    public function __construct(private readonly LedgerWriter $ledger) {}

    public function handle(Staff $actor, Inspection $inspection, array $data): Inspection
    {
        return DB::transaction(function () use ($actor, $inspection, $data) {
            $inspection = Inspection::whereKey($inspection->id)->lockForUpdate()->firstOrFail();

            if ($inspection->status !== InspectionStatus::Pending) {
                throw InvalidTransition::from($inspection->status, InspectionStatus::Approved);
            }

            $inspection->update([...$data, 'status' => InspectionStatus::Approved, 'approved_by_staff_id' => $actor->id]);

            AuditLog::record(actor: $actor, action: 'inspection.approved', subject: $inspection);

            InspectionApproved::dispatch($inspection); // listeners/jobs use afterCommit

            return $inspection;
        });
    }
}
```

## Rules

- **Actor** (Constitution I): every Action takes exactly one named actor (customer or staff) and writes it to ledger/audit rows. No actor, no write.
- **Transactions**: wrap the whole state change in `DB::transaction()`. Lock rows you read-then-write (`lockForUpdate()`), always in a consistent order (e.g. by id) to avoid deadlocks.
- **Money/gold arithmetic**: decimal strings with `bcadd`/`bcmul`/`bccomp` (or `Brick\Math` if added), with the scale from the schema. Never floats.
- **Ledger**: double-entry rows that balance within the transaction. Balances are derived from, or updated with, ledger rows in the same transaction.
- **Idempotency**: payment/settlement Actions accept an idempotency key and return the existing result on replay (unique index plus catching the conflict).
- **State machines**: transitions are checked explicitly against a backed enum. Invalid transitions throw a domain exception with a stable error `code`.
- **Side effects** (notifications, jobs, webhooks) happen after commit: `ShouldDispatchAfterCommit` on events, `->afterCommit()` on jobs.
- **RLS context**: when running as a customer, set the session variables your RLS policies read (`SET LOCAL app.customer_id = ...`) inside the transaction. Any elevation that bypasses RLS must be explicit and audited (Constitution II).
- Actions never read `request()` or `auth()` directly; the controller passes them in.
