---
name: "laravel-auth-authorization"
description: "Implement Sanctum token auth, token abilities, policies, gates and staff roles for Dahab's customer/staff principals. Use when a task touches login, tokens, permissions, or who-can-do-what."
argument-hint: "Auth flow or permission to implement"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

## Source of truth

`docs/Technical Spec/dahab-spec-part1-auth.md` (principals, flows, token lifetimes, lockouts) and `docs/dahab-admin-roles.docx` (staff roles and permissions). Follow them exactly.

## Authentication (Sanctum)

- Two principal types (customer, staff), each `HasApiTokens`. If they're separate models, configure separate guards/providers in `config/auth.php`. Never merge them into one `users` table with a type flag unless the docs say so.
- Guards: `customer` (Sanctum driver, `customers` provider) and `staff` (Sanctum driver, `staff` provider) in `config/auth.php`. Sanctum only resolves a token whose owner is the provider's model, so a token of one principal is 401 on the other's guard. Never use `auth:sanctum`.
- Tokens are issued only through `IssueTokenFamilyAction` (one access + one refresh token per family), each with exactly one ability from `App\Enums\TokenAbility`: `customer:access`, `customer:refresh`, `staff:access`, `staff:refresh`. Never mint `*`.
- Protect routes with the guard plus the ability middleware (`abilities:` / `ability:` aliases, registered in `bootstrap/app.php`), in this order:
  ```php
  Route::middleware(['auth:customer', 'abilities:customer:access'])->group(...);   // customer API
  Route::middleware(['auth:staff', 'abilities:staff:access'])->group(...);         // dashboard API (+ staff.permission:<code>)
  Route::post('/refresh', ...)->middleware(['auth:customer', 'abilities:customer:refresh', 'throttle:auth.refresh']);
  ```
  A refresh token on an access route (or the reverse) is 403 `forbidden`; the wrong principal's token is 401.
- Login/OTP/password-reset endpoints: dedicated `RateLimiter::for(...)` limits in `AppServiceProvider`, generic failure messages (no user enumeration), audit every success and failure.
- Logout revokes the current token (`$request->user()->currentAccessToken()->delete()`).
- Passwords `Hash::make` — the default hasher is Argon2id (`config/hashing.php`, spec FR-X-007); legacy bcrypt hashes still verify and are rehashed on sign-in. OTPs are stored hashed and compared with `hash_equals`.
- Rate limiters are per concern and never shared: `auth.customer.register`, `auth.customer.login`, `auth.staff.login` (dashboard), `auth.otp.send|verify`, `auth.password_reset.request`, `auth.refresh`. An identity lockout is `account_locked` (thrown from the limit's `->response()` via `AppServiceProvider::lockout()`); everything else is `too_many_requests`. Never branch on the URL.

## Authorization

- One policy per model: `php artisan make:policy <Model>Policy --model=<Model>`. Laravel 12 discovers policies automatically.
- FormRequest `authorize()` → `$this->user()->can('approve', $inspection)`. In controllers, `Gate::authorize(...)`.
- Staff permissions: map roles to abilities in one place (enum or config) and check abilities, never role names, in policies.
- Ownership checks in policies are defence in depth. The real isolation is RLS (Constitution II).
- Denials produce 403 with `code: forbidden` (already rendered in `bootstrap/app.php`). Don't leak whether the resource exists: return 404 for other customers' resources where the spec says so.

## Tests

For every protected endpoint: unauthenticated → 401, other principal's token → 401, right principal but wrong ability (e.g. a refresh token) → 403, owner → 2xx. Use `Sanctum::actingAs($customer, ['customer:access'], 'customer')` (the third argument is the guard; it defaults to `sanctum`, which no route uses). To exercise real tokens, issue them with `IssueTokenFamilyAction` and call `$this->bearer($token)` (resets the cached guard user between requests).
