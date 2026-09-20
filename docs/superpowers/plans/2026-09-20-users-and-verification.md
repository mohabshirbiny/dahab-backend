# Users and Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `/dashboard/users` "Users and verification" page so staff can work the queue of customers waiting for identity verification and decide each one with Verify / Ask again / Reject.

**Architecture:** A new customer-rooted read surface (`GET /api/v1/dashboard/customers`, `/{id}`) behind a new `customer.view` permission supplies the queue and the reviewer's customer file. The decision itself stays on the **existing** `POST /dashboard/identity-documents/{id}/review` endpoint, whose `decision` widens to accept `changes_requested` ("Ask again") — which persists as `status='rejected'` plus `review_outcome='changes_requested'`, because `SubmitIdentityDocumentAction` blocks a re-upload while any document is `pending`. The Vue page reuses the existing image-loading, permission, error and review machinery wholesale.

**Tech Stack:** Laravel 12 · PHP 8.3+ · PostgreSQL 16 · Sanctum · Spatie Permission · Pest 3 · l5-swagger || Vue 3 · TypeScript · Vuetify · Vite · TanStack Query

**Spec:** `docs/superpowers/specs/2026-09-20-users-and-verification-design.md`

## Global Constraints

- **Backend repo `D:\laragon\www\dahab-backend` is NOT a git repository.** `git rev-parse` fails. **Skip every `git add` / `git commit` step in Phase A** unless the user runs `git init` first. Verify each Phase A task with its Pest run instead. Do not run `git init` on your own initiative.
- **Dashboard repo `D:\laragon\www\dahab-dashboard` IS a git repository.** Phase B commits normally. Commit to a branch, never to the default branch. Do not push.
- **The two repos get separate change sets. Never one mixed commit.** Never move or copy a file between them.
- **Run `php artisan migrate` as the `dahab` database user, not the `postgres` user in `.env`.** Migrating as `postgres` breaks table ownership and every subsequent test fails.
- **Repo uses LF line endings.** Use Write/Edit tools. If scripting a file edit in Python, open with `newline=''`.
- Backend field names are `snake_case`; dashboard types are `camelCase`. Services map between them.
- `identity_document.status` keeps `CHECK (status IN ('pending','approved','rejected'))` **unchanged**. No task may add a status value.
- Never invent an endpoint or a field. If something is missing, report it.
- Commands: `composer test` (Pest) · `./vendor/bin/pint` (format) · `composer swagger:generate` (OpenAPI) · `npm run type-check` · `npm run lint`.

---

## File Structure

### Phase A — Backend (`dahab-backend`)

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_20_000010_add_city_to_customer.php` | Create: nullable `city` on `customer` |
| `database/migrations/**2026_09_20_000020_add_review_outcome_to_identity_document**.php` | Create: `review_outcome`, `review_issues`, `review_note` + queue index |
| `app/Enums/StaffPermission.php` | Modify: add `CUSTOMER_VIEW` |
| `app/Enums/IdentityReviewIssue.php` | Create: the six issue codes |
| `app/Enums/IdentityReviewOutcome.php` | Create: `approved` / `changes_requested` / `refused` |
| `app/Models/Customer.php` | Modify: `city` fillable |
| `app/Models/IdentityDocument.php` | Modify: review columns fillable + casts |
| `app/Support/MaskedPhone.php` | Create: the only place a phone is masked |
| `app/Http/Requests/Auth/Customer/StartRegistrationRequest.php` | Modify: optional `city` |
| `app/Actions/Auth/Customer/StartCustomerRegistrationAction.php` | Modify: carry `city` in the cache session |
| `app/Actions/Auth/Customer/CompleteCustomerRegistrationAction.php` | Modify: write `city` |
| `app/Http/Requests/Identity/ReviewIdentityDocumentRequest.php` | Modify: widen `decision`, add `issues` |
| `app/Actions/Identity/ReviewIdentityDocumentAction.php` | Modify: decision→state map, persist outcome, notify after commit |
| `app/Notifications/IdentityDocumentReviewedNotification.php` | Create: queued SMS/mail over the existing `SmsSender` |
| `app/Http/Resources/Staff/IdentityDocumentResource.php` | Modify: expose the three review fields |
| `app/Http/Resources/Staff/CustomerResource.php` | Create: `StaffCustomer` schema |
| `app/Http/Requests/Dashboard/ListCustomersRequest.php` | Create: `verification_status`, `per_page` |
| `app/Actions/Dashboard/ListCustomersForVerificationAction.php` | Create: the waiting queue query |
| `app/Actions/Dashboard/ShowCustomerAction.php` | Create: one customer + all documents |
| `app/Http/Controllers/Api/V1/Dashboard/CustomerController.php` | Create: thin controller + OpenAPI |
| `routes/api.php` | Modify: the two customer routes |
| `database/factories/CustomerFactory.php` | Modify: `city` state |
| `tests/Feature/Dashboard/CustomerQueueTest.php` | Create |
| `tests/Feature/Identity/IdentityAskAgainTest.php` | Create |
| `postman/Dahab-Backend.postman_collection.json` | Modify |

### Phase B — Dashboard (`dahab-dashboard`)

| File | Responsibility |
|---|---|
| `src/types/customer.ts` | Create: customer view models + the issue catalogue |
| `src/types/identity.ts` | Modify: review outcome fields |
| `src/types/api.ts` | Modify: `ApiCustomer` wire shape |
| `src/types/staff.ts` | Modify: `customerView` permission |
| `src/api/endpoints.ts` | Modify: two customer paths |
| `src/services/customer.service.ts` | Create: fetch + snake→camel mapping |
| `src/services/identity-document.service.ts` | Modify: `askAgainOnDocument` |
| `src/composables/useCustomers.ts` | Create: queries + `customerKeys` |
| `src/composables/useIdentityDocuments.ts` | Modify: `useAskAgainIdentityDocument` |
| `src/components/identity/AskAgainModal.vue` | Create: six checkboxes + note |
| `src/components/customers/CustomerQueueTable.vue` | Create: the waiting list |
| `src/components/customers/CustomerVerificationPanel.vue` | Create: the review workspace |
| `src/pages/customers/index.vue` | Create: the split-view page |
| `src/router/index.ts` | Modify: real route replaces the placeholder |

---

## Phase A — Backend

### Task 1: `city` on the customer, set at registration

**Files:**
- Create: `database/migrations/2026_09_20_000010_add_city_to_customer.php`
- Modify: `app/Models/Customer.php` (the `$fillable` array)
- Modify: `database/factories/CustomerFactory.php`
- Modify: `app/Http/Requests/Auth/Customer/StartRegistrationRequest.php`
- Modify: `app/Actions/Auth/Customer/StartCustomerRegistrationAction.php`
- Modify: `app/Actions/Auth/Customer/CompleteCustomerRegistrationAction.php`
- Test: `tests/Feature/Auth/Customer/RegisterTest.php` (append)

**Interfaces:**
- Consumes: nothing.
- Produces: `customer.city` (nullable string, max 80). `CustomerFactory::inCity(string $city)`. `registerCustomer(['city' => 'Cairo'])` works through the existing `tests/Pest.php` helper, which merges its array over the default payload and posts it to `register/start`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Auth/Customer/RegisterTest.php`:

```php
it('stores an optional city given at the start of registration', function () {
    registerCustomer(['city' => 'Cairo'])->assertCreated();

    expect(Customer::query()->sole()->city)->toBe('Cairo');
});

it('registers without a city', function () {
    registerCustomer()->assertCreated();

    expect(Customer::query()->sole()->city)->toBeNull();
});

it('refuses a city longer than 80 characters', function () {
    $this->postJson('/api/v1/customer/auth/register/start', [
        'phone' => '+201000000009',
        'password' => 'correct-horse-battery',
        'preferred_lang' => 'en',
        'city' => str_repeat('x', 81),
    ])->assertStatus(422)->assertJsonValidationErrors('city', responseKey: 'errors');

    expect(Customer::query()->count())->toBe(0);
});
```

If `Customer` is not already imported at the top of that file, add `use App\Models\Customer;`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter="optional city"`
Expected: FAIL — `city` is not a column, so `->city` is null / the insert is rejected.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_20_000010_add_city_to_customer.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The city shown next to a customer's name on the verification queue.
     * Nullable and unbackfilled: nothing collected a city before this, so
     * every existing row stays null until that customer registers again.
     */
    public function up(): void
    {
        Schema::table('customer', function (Blueprint $table) {
            $table->string('city', 80)->nullable()->after('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('customer', function (Blueprint $table) {
            $table->dropColumn('city');
        });
    }
};
```

- [ ] **Step 4: Wire `city` through the model, factory, request and actions**

In `app/Models/Customer.php`, add `'city',` to `$fillable` immediately after `'full_name',`.

In `database/factories/CustomerFactory.php`, add this method after `verified()`:

```php
    public function inCity(string $city = 'Cairo'): static
    {
        return $this->state(fn () => ['city' => $city]);
    }
```

In `app/Http/Requests/Auth/Customer/StartRegistrationRequest.php`, add to `rules()` after the `full_name` rule:

```php
            'city' => ['nullable', 'string', 'max:80'],
```

and add to the `#[OA\Schema]` `properties` array, after the `full_name` property:

```php
        new OA\Property(property: 'city', type: 'string', nullable: true, maxLength: 80),
```

In `app/Actions/Auth/Customer/StartCustomerRegistrationAction.php`, add to the `$this->sessions->begin([...])` array after the `'full_name'` line:

```php
            'city' => $input['city'] ?? null,
```

and widen the `@param` docblock array shape to include `city?: ?string`.

In `app/Actions/Auth/Customer/CompleteCustomerRegistrationAction.php`, add to the `Customer::query()->create([...])` array after the `'full_name'` line:

```php
                    'city' => $session['city'] ?? null,
```

The `?? null` matters: a registration session begun before this deploy has no `city` key, and reading it bare would throw mid-flight.

- [ ] **Step 5: Migrate and run the tests**

Run: `php artisan migrate` **as the `dahab` DB user**, then `php artisan test --filter=RegisterTest`
Expected: PASS, including the two pre-existing registration tests.

- [ ] **Step 6: Format**

Run: `./vendor/bin/pint`
Expected: the touched files are clean.

---

### Task 2: The `customer.view` permission

**Files:**
- Modify: `app/Enums/StaffPermission.php`
- Test: `tests/Feature/Auth/Staff/StaffRolesAndPermissionsTest.php` (append)

**Interfaces:**
- Consumes: nothing.
- Produces: `StaffPermission::CUSTOMER_VIEW` with value `'customer.view'`, held by `StaffRole::CEO` and `StaffRole::VERIFICATION`. Routes gate on it via the `staff.permission:customer.view` middleware. `DashboardRolesAndPermissionsSeeder` picks it up automatically — it iterates `StaffPermission::cases()`, so it needs no edit.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Auth/Staff/StaffRolesAndPermissionsTest.php`:

```php
it('grants customer.view to the founders and the verification role only', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    expect(Staff::factory()->role(StaffRole::CEO)->create()->can('customer.view'))->toBeTrue()
        ->and(Staff::factory()->role(StaffRole::VERIFICATION)->create()->can('customer.view'))->toBeTrue()
        ->and(Staff::factory()->role(StaffRole::OPERATIONS)->create()->can('customer.view'))->toBeFalse()
        ->and(Staff::factory()->role(StaffRole::FINANCE)->create()->can('customer.view'))->toBeFalse();
});
```

Check the file's existing imports first and add only what is missing: `use App\Enums\StaffRole;`, `use App\Models\Staff;`, `use Database\Seeders\DashboardRolesAndPermissionsSeeder;`. If the file already calls `$this->seed(...)` in a `beforeEach`, drop the seed line from the test body.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter="customer.view"`
Expected: FAIL — the permission does not exist, so `can()` is false for the CEO.

- [ ] **Step 3: Add the enum case**

In `app/Enums/StaffPermission.php`, add after the `CUSTOMER_SUSPEND` case:

```php
    /** Read a customer's file and the verification queue, including name and masked phone. */
    case CUSTOMER_VIEW = 'customer.view';
```

and add to the `match ($this)` in `roles()`, as its own arm:

```php
            // The verification queue shows customer PII, so it follows identity.view's holders.
            self::CUSTOMER_VIEW => [StaffRole::CEO, StaffRole::VERIFICATION],
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=StaffRolesAndPermissionsTest`
Expected: PASS.

- [ ] **Step 5: Format**

Run: `./vendor/bin/pint`

---

### Task 3: Review outcome columns

**Files:**
- Create: `database/migrations/2026_09_20_000020_add_review_outcome_to_identity_document.php`
- Create: `app/Enums/IdentityReviewOutcome.php`
- Create: `app/Enums/IdentityReviewIssue.php`
- Modify: `app/Models/IdentityDocument.php`
- Test: `tests/Feature/Identity/IdentityDocumentSchemaTest.php` (append)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `IdentityReviewOutcome` enum — `APPROVED = 'approved'`, `CHANGES_REQUESTED = 'changes_requested'`, `REFUSED = 'refused'`, with `->status(): IdentityDocumentStatus`.
  - `IdentityReviewIssue` enum — `BLURRED_OR_GLARE = 'blurred_or_glare'`, `CUT_OFF = 'cut_off'`, `NAME_MISMATCH = 'name_mismatch'`, `EXPIRED = 'expired'`, `BACK_MISSING = 'back_missing'`, `UNREADABLE_TEXT = 'unreadable_text'`.
  - `identity_document.review_outcome` (nullable, CHECK-constrained), `.review_issues` (nullable JSONB, cast to `array`), `.review_note` (nullable text). All three fillable.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Identity/IdentityDocumentSchemaTest.php`:

```php
it('stores a review outcome, its issue codes and its note', function () {
    $document = IdentityDocument::factory()->create([
        'review_outcome' => IdentityReviewOutcome::CHANGES_REQUESTED,
        'review_issues' => ['blurred_or_glare', 'name_mismatch'],
        'review_note' => 'Take it again in daylight.',
    ]);

    $fresh = $document->fresh();
    expect($fresh->review_outcome)->toBe(IdentityReviewOutcome::CHANGES_REQUESTED)
        ->and($fresh->review_issues)->toBe(['blurred_or_glare', 'name_mismatch'])
        ->and($fresh->review_note)->toBe('Take it again in daylight.');
});

it('refuses a review outcome outside the three known values', function () {
    expect(fn () => DB::table('identity_document')->insert([
        'document_id' => Str::uuid()->toString(),
        'customer_id' => Customer::factory()->create()->customer_id,
        'doc_kind' => 'egyptian_id',
        'storage_ref' => 'identity/x.enc',
        'status' => 'rejected',
        'review_outcome' => 'maybe',
    ]))->toThrow(QueryException::class);
});

it('leaves the review columns null on a pending document', function () {
    $document = IdentityDocument::factory()->create();

    expect($document->review_outcome)->toBeNull()
        ->and($document->review_issues)->toBeNull()
        ->and($document->review_note)->toBeNull();
});
```

Add any missing imports at the top of the file: `use App\Enums\IdentityReviewOutcome;`, `use App\Models\Customer;`, `use App\Models\IdentityDocument;`, `use Illuminate\Database\QueryException;`, `use Illuminate\Support\Facades\DB;`, `use Illuminate\Support\Str;`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter="review outcome"`
Expected: FAIL — the columns do not exist.

- [ ] **Step 3: Write the two enums**

Create `app/Enums/IdentityReviewIssue.php`:

```php
<?php

namespace App\Enums;

/**
 * What a reviewer says is wrong with a document when they ask for it again.
 *
 * The code is the contract; the customer-facing wording lives in the lang
 * files under `identity.issue.<value>`, so the app can render it in the
 * customer's own language rather than echoing whatever the reviewer saw.
 */
enum IdentityReviewIssue: string
{
    case BLURRED_OR_GLARE = 'blurred_or_glare';
    case CUT_OFF = 'cut_off';
    case NAME_MISMATCH = 'name_mismatch';
    case EXPIRED = 'expired';
    case BACK_MISSING = 'back_missing';
    case UNREADABLE_TEXT = 'unreadable_text';
}
```

Create `app/Enums/IdentityReviewOutcome.php`:

```php
<?php

namespace App\Enums;

/**
 * `identity_document.review_outcome` — the staff decision, which is finer
 * grained than `status`.
 *
 * `changes_requested` and `refused` are both `status = 'rejected'`, and that
 * is deliberate: SubmitIdentityDocumentAction refuses a new upload while any
 * document is still `pending`, so leaving an "ask again" document pending
 * would block the very re-upload it asks for. The two differ in what the
 * customer is told, not in what the record is.
 */
enum IdentityReviewOutcome: string
{
    case APPROVED = 'approved';
    case CHANGES_REQUESTED = 'changes_requested';
    case REFUSED = 'refused';

    public function status(): IdentityDocumentStatus
    {
        return match ($this) {
            self::APPROVED => IdentityDocumentStatus::APPROVED,
            self::CHANGES_REQUESTED, self::REFUSED => IdentityDocumentStatus::REJECTED,
        };
    }
}
```

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_09_20_000020_add_review_outcome_to_identity_document.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The staff decision in finer grain than `status`, plus the structured
     * reasons the customer is shown.
     *
     * `status` and its CHECK constraint are deliberately untouched: an
     * "ask again" document is `rejected`, because a pending document blocks
     * the customer's re-upload.
     *
     * The audit row remains the append-only legal record. These columns are
     * the serving copy, because a trigger-protected append-only table must
     * never be the thing a customer-facing read queries.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE identity_document
                ADD COLUMN review_outcome TEXT NULL
                    CHECK (review_outcome IN ('approved','changes_requested','refused')),
                ADD COLUMN review_issues  JSONB NULL,
                ADD COLUMN review_note    TEXT NULL
        ");

        // Serves the waiting queue's EXISTS (customer_id, status='pending').
        DB::statement("
            CREATE INDEX idx_identity_document_pending
                ON identity_document (customer_id)
                WHERE status = 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_identity_document_pending');
        DB::statement('
            ALTER TABLE identity_document
                DROP COLUMN IF EXISTS review_outcome,
                DROP COLUMN IF EXISTS review_issues,
                DROP COLUMN IF EXISTS review_note
        ');
    }
};
```

- [ ] **Step 5: Wire the model**

In `app/Models/IdentityDocument.php`, add to `$fillable` after `'image_deleted_at',`:

```php
        'review_outcome',
        'review_issues',
        'review_note',
```

and add to the `casts()` array:

```php
            'review_outcome' => IdentityReviewOutcome::class,
            'review_issues' => 'array',
```

Add `use App\Enums\IdentityReviewOutcome;` to the imports.

- [ ] **Step 6: Migrate and run the tests**

Run: `php artisan migrate` **as the `dahab` DB user**, then `php artisan test --filter=IdentityDocumentSchemaTest`
Expected: PASS.

- [ ] **Step 7: Verify the migration reverses cleanly**

Run: `php artisan migrate:rollback --step=1 && php artisan migrate`
Expected: both succeed with no error.

- [ ] **Step 8: Format**

Run: `./vendor/bin/pint`

---

### Task 4: "Ask again" through the existing review endpoint

**Files:**
- Modify: `app/Http/Requests/Identity/ReviewIdentityDocumentRequest.php`
- Modify: `app/Actions/Identity/ReviewIdentityDocumentAction.php`
- Modify: `app/Http/Resources/Staff/IdentityDocumentResource.php`
- Test: `tests/Feature/Identity/IdentityAskAgainTest.php` (create)

**Interfaces:**
- Consumes: `IdentityReviewOutcome`, `IdentityReviewIssue` and the three columns from Task 3.
- Produces:
  - Request accessors `outcome(): IdentityReviewOutcome`, `issues(): ?array` (list of `IdentityReviewIssue`), `reason(): ?string` (unchanged).
  - `ReviewIdentityDocumentAction::handle(Staff $actor, string $documentId, IdentityReviewOutcome $outcome, ?array $issues, ?string $reason, RequestContext $ctx): IdentityDocument` — **the third parameter changes type** from `IdentityDocumentStatus` to `IdentityReviewOutcome`, and `?array $issues` is inserted as the fourth.
  - `StaffIdentityDocument` gains `review_outcome` (nullable string), `review_issues` (nullable array of string), `review_note` (nullable string).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Identity/IdentityAskAgainTest.php`:

```php
<?php

use App\Enums\AuditEvent;
use App\Enums\IdentityDocumentStatus;
use App\Enums\IdentityReviewOutcome;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const ASK_BASE = '/api/v1/dashboard/identity-documents';

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    Sanctum::actingAs(Staff::factory()->role(StaffRole::VERIFICATION)->create(), ['staff:access'], 'staff');
});

it('asks again: rejects the document, records the issues and leaves the customer unverified', function () {
    $document = IdentityDocument::factory()->create();

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', [
        'decision' => 'changes_requested',
        'issues' => ['blurred_or_glare', 'name_mismatch'],
        'reason' => 'Retake it in daylight.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.review_outcome', 'changes_requested')
        ->assertJsonPath('data.review_issues', ['blurred_or_glare', 'name_mismatch'])
        ->assertJsonPath('data.review_note', 'Retake it in daylight.')
        ->assertJsonPath('data.customer.is_verified', false);

    $fresh = $document->fresh();
    expect($fresh->status)->toBe(IdentityDocumentStatus::REJECTED)
        ->and($fresh->review_outcome)->toBe(IdentityReviewOutcome::CHANGES_REQUESTED)
        ->and($fresh->review_issues)->toBe(['blurred_or_glare', 'name_mismatch'])
        ->and($fresh->customer->fresh()->is_verified)->toBeFalse();
});

it('lets the customer submit a new document after being asked again', function () {
    $document = IdentityDocument::factory()->create();

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', [
        'decision' => 'changes_requested',
        'issues' => ['cut_off'],
    ])->assertOk();

    // No pending document is left behind, which is the whole point of using
    // `rejected` rather than leaving it `pending`.
    expect(IdentityDocument::query()->where('customer_id', $document->customer_id)->pending()->exists())->toBeFalse();
});

it('records a plain rejection as refused, distinct from asking again', function () {
    $document = IdentityDocument::factory()->create();

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', [
        'decision' => 'rejected',
        'reason' => 'Not an acceptable document.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.review_outcome', 'refused');

    expect($document->fresh()->review_outcome)->toBe(IdentityReviewOutcome::REFUSED);
});

it('records an approval as approved', function () {
    $document = IdentityDocument::factory()->create();

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.review_outcome', 'approved');

    expect($document->fresh()->review_outcome)->toBe(IdentityReviewOutcome::APPROVED);
});

it('requires at least one issue when asking again', function (array $body) {
    $document = IdentityDocument::factory()->create();

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('issues', responseKey: 'errors');

    expect($document->fresh()->status)->toBe(IdentityDocumentStatus::PENDING);
})->with([
    'missing' => [['decision' => 'changes_requested']],
    'empty' => [['decision' => 'changes_requested', 'issues' => []]],
]);

it('refuses an unknown issue code', function () {
    $document = IdentityDocument::factory()->create();

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', [
        'decision' => 'changes_requested',
        'issues' => ['blurred_or_glare', 'dog_ate_it'],
    ])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect($document->fresh()->status)->toBe(IdentityDocumentStatus::PENDING);
});

it('asks again without a note', function () {
    $document = IdentityDocument::factory()->create();

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', [
        'decision' => 'changes_requested',
        'issues' => ['expired'],
    ])->assertOk()->assertJsonPath('data.review_note', null);
});

it('audits asking again as a rejection carrying its issue codes', function () {
    $document = IdentityDocument::factory()->create();

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', [
        'decision' => 'changes_requested',
        'issues' => ['back_missing'],
        'reason' => 'Add the back of the card.',
    ])->assertOk();

    $audit = AuditLog::query()->where('action', AuditEvent::IDENTITY_DOCUMENT_REJECTED->value)->sole();
    expect($audit->after_json['status'])->toBe('rejected')
        ->and($audit->after_json['review_outcome'])->toBe('changes_requested')
        ->and($audit->after_json['review_issues'])->toBe(['back_missing'])
        ->and($audit->reason)->toBe('Add the back of the card.');
});

it('asks again exactly once', function () {
    $document = IdentityDocument::factory()->create();
    $body = ['decision' => 'changes_requested', 'issues' => ['unreadable_text']];

    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', $body)->assertOk();
    $this->postJson(ASK_BASE.'/'.$document->document_id.'/review', $body)
        ->assertStatus(409)
        ->assertJsonPath('code', 'illegal_document_transition');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=IdentityAskAgainTest`
Expected: FAIL — `changes_requested` is rejected by the `decision` rule as an invalid value (422).

- [ ] **Step 3: Widen the FormRequest**

Replace the body of `app/Http/Requests/Identity/ReviewIdentityDocumentRequest.php` below the namespace with:

```php
use App\Enums\IdentityDocumentStatus;
use App\Enums\IdentityReviewIssue;
use App\Enums\IdentityReviewOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReviewIdentityDocumentRequest',
    required: ['decision'],
    properties: [
        new OA\Property(
            property: 'decision',
            type: 'string',
            enum: ['approved', 'changes_requested', 'rejected'],
            description: '`changes_requested` ("ask again") persists as `status=rejected` with `review_outcome=changes_requested`, so the customer can upload a replacement.',
        ),
        new OA\Property(property: 'issues', type: 'array', items: new OA\Items(type: 'string', enum: ['blurred_or_glare', 'cut_off', 'name_mismatch', 'expired', 'back_missing', 'unreadable_text']), description: 'Required, non-empty, when `decision` is `changes_requested`.'),
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, description: 'Required when `decision` is `rejected`; an optional note when asking again. Kept on the audit row and on `review_note`.'),
    ],
)]
class ReviewIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(IdentityReviewOutcome::class)->except(IdentityReviewOutcome::REFUSED)],
            'issues' => [
                Rule::requiredIf(fn () => $this->input('decision') === IdentityReviewOutcome::CHANGES_REQUESTED->value),
                'array',
                'min:1',
            ],
            'issues.*' => [Rule::enum(IdentityReviewIssue::class)],
            'reason' => [
                Rule::requiredIf(fn () => $this->input('decision') === IdentityDocumentStatus::REJECTED->value),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * The API speaks of a decision; the record keeps an outcome. `rejected`
     * on the wire is `refused` in the model, because `changes_requested` is
     * also a rejection and the two must stay distinguishable.
     */
    public function outcome(): IdentityReviewOutcome
    {
        $decision = $this->validated('decision');

        return $decision === IdentityDocumentStatus::REJECTED->value
            ? IdentityReviewOutcome::REFUSED
            : IdentityReviewOutcome::from($decision);
    }

    /** @return list<IdentityReviewIssue>|null */
    public function issues(): ?array
    {
        $issues = $this->validated('issues');

        if (! is_array($issues) || $issues === []) {
            return null;
        }

        return array_values(array_map(IdentityReviewIssue::from(...), $issues));
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
```

`->except(REFUSED)` keeps `refused` an internal value: the wire word for it stays `rejected`, so no existing client changes.

- [ ] **Step 4: Update the Action**

In `app/Actions/Identity/ReviewIdentityDocumentAction.php`:

Replace the signature and the guard at the top of `handle()`:

```php
    /**
     * pending → approved | rejected, once. Approval is what flips
     * `customer.is_verified`; a rejection — whether a refusal or a request for
     * changes — leaves the customer as they were. Decision, customer flag and
     * audit row commit together or not at all.
     *
     * @param  list<IdentityReviewIssue>|null  $issues
     */
    public function handle(
        Staff $actor,
        string $documentId,
        IdentityReviewOutcome $outcome,
        ?array $issues,
        ?string $reason,
        RequestContext $ctx,
    ): IdentityDocument {
        $decision = $outcome->status();

        return DB::transaction(function () use ($actor, $documentId, $outcome, $decision, $issues, $reason, $ctx) {
```

The old `if ($decision === IdentityDocumentStatus::PENDING)` guard is deleted — `IdentityReviewOutcome` has no pending case, so the type makes that state unrepresentable.

Replace the `$document->update([...])` call with:

```php
            $issueCodes = $issues === null
                ? null
                : array_map(fn (IdentityReviewIssue $issue) => $issue->value, $issues);

            $document->update([
                'status' => $decision,
                'reviewed_by' => $actor->staff_id,
                'reviewed_at' => now(),
                'review_outcome' => $outcome,
                'review_issues' => $issueCodes,
                'review_note' => $reason,
            ]);
```

Add `'review_outcome' => $outcome->value,` and `'review_issues' => $issueCodes,` to the audit payload array, after `'status' => $decision->value,`.

Add these imports: `use App\Enums\IdentityReviewIssue;`, `use App\Enums\IdentityReviewOutcome;`.

In `app/Http/Controllers/Api/V1/Dashboard/IdentityDocumentController.php`, update the `review()` call to match the new signature:

```php
        $reviewed = $this->review->handle($request->user('staff'), $document, $request->outcome(), $request->issues(), $request->reason(), $ctx);
```

and add `changes_requested` to the endpoint's `#[OA\Post]` description.

- [ ] **Step 5: Expose the fields on the Resource**

In `app/Http/Resources/Staff/IdentityDocumentResource.php`, add to the returned array after `'image_available' => ...`:

```php
            'review_outcome' => $d->review_outcome?->value,
            'review_issues' => $d->review_issues,
            'review_note' => $d->review_note,
```

and add to the `#[OA\Schema]` `properties`, after the `image_available` property:

```php
        new OA\Property(property: 'review_outcome', type: 'string', enum: ['approved', 'changes_requested', 'refused'], nullable: true),
        new OA\Property(property: 'review_issues', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'review_note', type: 'string', nullable: true),
```

- [ ] **Step 6: Run the new and the existing review tests**

Run: `php artisan test --filter="IdentityAskAgainTest|IdentityReviewTest|IdentityReviewAuthorizationTest|IdentityVerificationFlowTest"`
Expected: PASS, all four files. `IdentityReviewTest` must still pass untouched — that is the proof the change is non-breaking for existing `approved` / `rejected` bodies.

- [ ] **Step 7: Format**

Run: `./vendor/bin/pint`

---

### Task 5: Tell the customer what to fix

**Files:**
- Create: `app/Notifications/IdentityDocumentReviewedNotification.php`
- Create: `lang/en/identity.php`
- Create: `lang/ar/identity.php`
- Modify: `app/Actions/Identity/ReviewIdentityDocumentAction.php`
- Test: `tests/Feature/Identity/IdentityReviewNotificationTest.php` (create)

**Interfaces:**
- Consumes: `IdentityReviewOutcome`, `IdentityReviewIssue`, the review Action from Task 4.
- Produces: `IdentityDocumentReviewedNotification(IdentityReviewOutcome $outcome, array $issues, ?string $note)` — queued, `tries = 3`, `via()` returns `['sms']` plus `'mail'` when the customer has an email. Dispatched from `ReviewIdentityDocumentAction` **after** the transaction commits.
- Note: no new abstraction is built. `App\Services\Sms\SmsSender` is already bound in `AppServiceProvider` by `config('sms.default')` alone, behind `SmsChannel`. Wiring a real provider later is an `.env` change.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Identity/IdentityReviewNotificationTest.php`:

```php
<?php

use App\Enums\IdentityReviewOutcome;
use App\Enums\StaffRole;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Notifications\IdentityDocumentReviewedNotification;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const NOTIFY_BASE = '/api/v1/dashboard/identity-documents';

beforeEach(function () {
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    Sanctum::actingAs(Staff::factory()->role(StaffRole::VERIFICATION)->create(), ['staff:access'], 'staff');
});

it('tells the customer by sms what to fix when asked again', function () {
    $customer = Customer::factory()->create(['email' => null, 'preferred_lang' => 'en']);
    $document = IdentityDocument::factory()->for($customer)->create();

    $this->postJson(NOTIFY_BASE.'/'.$document->document_id.'/review', [
        'decision' => 'changes_requested',
        'issues' => ['blurred_or_glare'],
        'reason' => 'Try again by a window.',
    ])->assertOk();

    Notification::assertSentTo($customer, IdentityDocumentReviewedNotification::class, function ($notification) use ($customer) {
        expect($notification->via($customer))->toBe(['sms']);
        $body = $notification->toSms($customer)->content;

        return str_contains($body, 'blurred') && str_contains($body, 'Try again by a window.');
    });
});

it('adds the mail channel only when the customer gave an email', function () {
    $customer = Customer::factory()->create(['email' => 'mona@example.test']);
    $document = IdentityDocument::factory()->for($customer)->create();

    $this->postJson(NOTIFY_BASE.'/'.$document->document_id.'/review', ['decision' => 'approved'])->assertOk();

    Notification::assertSentTo($customer, IdentityDocumentReviewedNotification::class, function ($notification) use ($customer) {
        return $notification->via($customer) === ['mail', 'sms'];
    });
});

it('writes the sms in the customer preferred language', function () {
    $customer = Customer::factory()->create(['email' => null, 'preferred_lang' => 'ar']);
    $document = IdentityDocument::factory()->for($customer)->create();

    $this->postJson(NOTIFY_BASE.'/'.$document->document_id.'/review', [
        'decision' => 'changes_requested',
        'issues' => ['expired'],
    ])->assertOk();

    Notification::assertSentTo($customer, IdentityDocumentReviewedNotification::class, function ($notification) use ($customer) {
        // The Arabic catalogue renders no Latin letters for this issue code.
        return preg_match('/[A-Za-z]/', $notification->toSms($customer)->content) === 0;
    });
});

it('notifies on an approval and on a refusal too', function (string $decision, array $extra, string $outcome) {
    $customer = Customer::factory()->create(['email' => null]);
    $document = IdentityDocument::factory()->for($customer)->create();

    $this->postJson(NOTIFY_BASE.'/'.$document->document_id.'/review', ['decision' => $decision] + $extra)->assertOk();

    Notification::assertSentTo($customer, IdentityDocumentReviewedNotification::class, function ($notification) use ($outcome) {
        return $notification->outcome->value === $outcome;
    });
})->with([
    'approved' => ['approved', [], 'approved'],
    'refused' => ['rejected', ['reason' => 'No.'], 'refused'],
]);

it('sends nothing when the decision is refused by the conflict guard', function () {
    $customer = Customer::factory()->create();
    $document = IdentityDocument::factory()->for($customer)->approved()->create();

    $this->postJson(NOTIFY_BASE.'/'.$document->document_id.'/review', ['decision' => 'approved'])->assertStatus(409);

    Notification::assertNothingSent();
});

it('sends nothing when the transaction rolls back', function () {
    $customer = Customer::factory()->create();
    $document = IdentityDocument::factory()->for($customer)->create();

    DB::unprepared("
        CREATE FUNCTION audit_refuse_all() RETURNS trigger AS \$\$
        BEGIN RAISE EXCEPTION 'audit unavailable'; END;
        \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER audit_refuse_all BEFORE INSERT ON audit_log
            FOR EACH ROW EXECUTE FUNCTION audit_refuse_all();
    ");

    $this->postJson(NOTIFY_BASE.'/'.$document->document_id.'/review', ['decision' => 'approved'])->assertStatus(500);

    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=IdentityReviewNotificationTest`
Expected: FAIL — `IdentityDocumentReviewedNotification` does not exist.

- [ ] **Step 3: Write the lang catalogues**

Create `lang/en/identity.php`:

```php
<?php

return [
    'reviewed' => [
        'approved' => 'Your identity document was approved. You can now buy and sell.',
        'changes_requested' => 'We need a clearer copy of your identity document:',
        'refused' => 'Your identity document was not accepted.',
    ],

    'issue' => [
        'blurred_or_glare' => 'The photo is blurred or there is glare on the card. Take it again in daylight, away from a window.',
        'cut_off' => 'Part of the card is cut off. Make sure the whole card is inside the frame.',
        'name_mismatch' => 'The name on the card does not match the name on your account.',
        'expired' => 'The card has expired. Please upload a valid one.',
        'back_missing' => 'The back of the card is missing. Please add it.',
        'unreadable_text' => 'The text on the card cannot be read. Please take a sharper photo.',
    ],
];
```

Create `lang/ar/identity.php`:

```php
<?php

return [
    'reviewed' => [
        'approved' => 'تم قبول وثيقة هويتك. يمكنك الآن البيع والشراء.',
        'changes_requested' => 'نحتاج نسخة أوضح من وثيقة هويتك:',
        'refused' => 'لم يتم قبول وثيقة هويتك.',
    ],

    'issue' => [
        'blurred_or_glare' => 'الصورة غير واضحة أو بها انعكاس ضوء. صوّرها مرة أخرى في ضوء النهار بعيدًا عن النافذة.',
        'cut_off' => 'جزء من البطاقة مقصوص. تأكد من ظهور البطاقة كاملة داخل الإطار.',
        'name_mismatch' => 'الاسم على البطاقة لا يطابق الاسم على حسابك.',
        'expired' => 'انتهت صلاحية البطاقة. من فضلك ارفع بطاقة سارية.',
        'back_missing' => 'ظهر البطاقة غير مرفق. من فضلك أضفه.',
        'unreadable_text' => 'النص على البطاقة غير مقروء. من فضلك التقط صورة أوضح.',
    ],
];
```

- [ ] **Step 4: Write the notification**

Create `app/Notifications/IdentityDocumentReviewedNotification.php`:

```php
<?php

namespace App\Notifications;

use App\Enums\IdentityReviewIssue;
use App\Enums\IdentityReviewOutcome;
use App\Models\Customer;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Here is what we decided about your identity document, and what to fix."
 *
 * Queued, and dispatched from ReviewIdentityDocumentAction only *after* its
 * transaction has committed. A provider outage is retried by the queue and
 * never unwinds a decision.
 *
 * Nothing here names an SMS provider: the `sms` channel resolves
 * App\Services\Sms\SmsSender, which AppServiceProvider binds from
 * `config('sms.default')` alone.
 */
class IdentityDocumentReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param list<IdentityReviewIssue> $issues */
    public function __construct(
        public readonly IdentityReviewOutcome $outcome,
        public readonly array $issues = [],
        public readonly ?string $note = null,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        $channels = ['sms'];

        if ($notifiable instanceof Customer && filled($notifiable->email)) {
            array_unshift($channels, 'mail');
        }

        return $channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        /** @var Customer $notifiable */
        $locale = $this->locale($notifiable);

        return (new MailMessage)
            ->subject(__('identity.reviewed.'.$this->outcome->value, locale: $locale))
            ->line(__('identity.reviewed.'.$this->outcome->value, locale: $locale))
            ->lines($this->issueLines($locale))
            ->when($this->note !== null, fn (MailMessage $m) => $m->line($this->note));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $locale = $this->locale($notifiable);

        $parts = [__('identity.reviewed.'.$this->outcome->value, locale: $locale)];
        $parts = array_merge($parts, $this->issueLines($locale));

        if ($this->note !== null) {
            $parts[] = $this->note;
        }

        return SmsMessage::make(implode(' ', $parts));
    }

    /** @return list<string> */
    private function issueLines(string $locale): array
    {
        return array_values(array_map(
            fn (IdentityReviewIssue $issue) => __('identity.issue.'.$issue->value, locale: $locale),
            $this->issues,
        ));
    }

    private function locale(Customer $customer): string
    {
        return $customer->preferred_lang === 'ar' ? 'ar' : 'en';
    }
}
```

`MailMessage::lines()` does not exist in Laravel 12 — if `->lines()` errors, replace that call with a `foreach` that calls `->line()` per entry. Verify at Step 6 and fix if needed.

- [ ] **Step 5: Dispatch it after commit**

In `app/Actions/Identity/ReviewIdentityDocumentAction.php`, the `handle()` method currently `return`s the `DB::transaction(...)` result directly. Capture it instead, notify, then return:

```php
        $document = DB::transaction(function () use (...) {
            // ... unchanged body, still ending in:
            // return $document->setRelation('customer', $customer);
        });

        // Past this line the decision is committed. A delivery failure is the
        // queue's problem and must never unwind the decision, which is why
        // this sits outside the transaction — the rule registration follows.
        $document->customer->notify(
            new IdentityDocumentReviewedNotification($outcome, $issues ?? [], $reason)
        );

        return $document;
```

Add `use App\Notifications\IdentityDocumentReviewedNotification;` to the imports.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="IdentityReviewNotificationTest|IdentityAskAgainTest|IdentityReviewTest"`
Expected: PASS. If `MailMessage::lines()` throws, apply the Step 4 note and re-run.

- [ ] **Step 7: Format**

Run: `./vendor/bin/pint`

---

### Task 6: The customer resource and phone masking

**Files:**
- Create: `app/Support/MaskedPhone.php`
- Create: `app/Http/Resources/Staff/CustomerResource.php`
- Test: `tests/Unit/MaskedPhoneTest.php` (create)

**Interfaces:**
- Consumes: `customer.city` (Task 1).
- Produces:
  - `MaskedPhone::mask(string $phone): string`.
  - `CustomerResource` → the `StaffCustomer` OpenAPI schema. Emits `pending_documents` when the `identityDocuments` relation is loaded and `$this->additional['all_documents']` is not set; emits `documents` when it is. Fields: `customer_id`, `display_ref`, `full_name`, `phone_masked`, `city`, `is_verified`, `is_suspended`, `created_at`, and one of the two document arrays.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MaskedPhoneTest.php`:

```php
<?php

use App\Support\MaskedPhone;

it('keeps the country and operator prefix and the last four digits', function () {
    expect(MaskedPhone::mask('+201012344417'))->toBe('+20 10 •••• 4417');
});

it('masks a short number without exposing more than the last four digits', function () {
    expect(MaskedPhone::mask('+2010123'))->toBe('+20 10 •••• 0123');
});

it('never returns the full number', function (string $phone) {
    expect(MaskedPhone::mask($phone))->not->toBe($phone);
})->with(['+201012344417', '+447700900123', '+12025550147']);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=MaskedPhoneTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `MaskedPhone`**

Create `app/Support/MaskedPhone.php`:

```php
<?php

namespace App\Support;

/**
 * The one place a customer phone is turned into something a dashboard may
 * show. The raw number never reaches an API response: staff need to recognise
 * a customer, not to be able to dial or export every customer.
 */
final class MaskedPhone
{
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $last = str_pad(substr($digits, -4), 4, '0', STR_PAD_LEFT);

        // +20 10 •••• 4417 — country code, operator prefix, then the tail.
        $country = substr($digits, 0, 2);
        $operator = substr($digits, 2, 2);

        return sprintf('+%s %s •••• %s', $country, $operator, $last);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=MaskedPhoneTest`
Expected: PASS.

- [ ] **Step 5: Write `CustomerResource`**

Create `app/Http/Resources/Staff/CustomerResource.php`:

```php
<?php

namespace App\Http\Resources\Staff;

use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Support\MaskedPhone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffCustomer',
    description: 'A customer as the dashboard sees them. The raw phone is never included; `phone_masked` is computed.',
    properties: [
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'display_ref', type: 'string'),
        new OA\Property(property: 'full_name', type: 'string', nullable: true, description: 'Optional at registration'),
        new OA\Property(property: 'phone_masked', type: 'string', example: '+20 10 •••• 4417'),
        new OA\Property(property: 'city', type: 'string', nullable: true, description: 'Optional at registration; null for every customer registered before it existed'),
        new OA\Property(property: 'is_verified', type: 'boolean'),
        new OA\Property(property: 'is_suspended', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'pending_documents', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffIdentityDocument'), description: 'On the list endpoint: the documents waiting for review'),
        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffIdentityDocument'), description: 'On the detail endpoint: every document, newest first'),
    ],
)]
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Customer $c */
        $c = $this->resource;

        $key = ($this->additional['all_documents'] ?? false) ? 'documents' : 'pending_documents';

        return [
            'customer_id' => $c->customer_id,
            'display_ref' => $c->display_ref,
            'full_name' => $c->full_name,
            'phone_masked' => MaskedPhone::mask($c->phone),
            'city' => $c->city,
            'is_verified' => (bool) $c->is_verified,
            'is_suspended' => (bool) $c->is_suspended,
            'created_at' => $c->created_at->toIso8601String(),
            $key => $this->whenLoaded(
                'identityDocuments',
                fn () => $c->identityDocuments
                    ->map(fn (IdentityDocument $d) => IdentityDocumentResource::make($d)->toArray($request))
                    ->values()
                    ->all(),
            ),
        ];
    }
}
```

- [ ] **Step 6: Format**

Run: `./vendor/bin/pint`

The resource has no test of its own — Task 7 and Task 8 exercise it through the HTTP boundary, which is where this codebase asserts on response shapes.

---

### Task 7: The waiting queue endpoint

**Files:**
- Create: `app/Http/Requests/Dashboard/ListCustomersRequest.php`
- Create: `app/Actions/Dashboard/ListCustomersForVerificationAction.php`
- Create: `app/Http/Controllers/Api/V1/Dashboard/CustomerController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Dashboard/CustomerQueueTest.php` (create)

**Interfaces:**
- Consumes: `CustomerResource` + `MaskedPhone` (Task 6), `customer.view` (Task 2), `city` (Task 1).
- Produces: `GET /api/v1/dashboard/customers?verification_status=waiting&per_page=N&page=N` → `{ data: StaffCustomer[], links, meta }`, oldest waiting first. Route name `api.v1.dashboard.customers.index`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Dashboard/CustomerQueueTest.php`:

```php
<?php

use App\Enums\StaffRole;
use App\Models\Customer;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const CUSTOMERS_BASE = '/api/v1/dashboard/customers';

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

function queueViewer(StaffRole $role = StaffRole::VERIFICATION): Staff
{
    $staff = Staff::factory()->role($role)->create();
    Sanctum::actingAs($staff, ['staff:access'], 'staff');

    return $staff;
}

it('lists only customers with a pending document, oldest submission first', function () {
    queueViewer();
    $newer = Customer::factory()->create();
    IdentityDocument::factory()->for($newer)->create(['created_at' => now()->subMinute()]);
    $older = Customer::factory()->create();
    IdentityDocument::factory()->for($older)->create(['created_at' => now()->subHour()]);

    // Neither of these is waiting for anything.
    IdentityDocument::factory()->approved()->create();
    Customer::factory()->create();

    $response = $this->getJson(CUSTOMERS_BASE)->assertOk();

    expect(collect($response->json('data'))->pluck('customer_id')->all())
        ->toBe([$older->customer_id, $newer->customer_id]);
    $response->assertJsonPath('meta.total', 2);
});

it('returns the name, masked phone, city and the pending document', function () {
    queueViewer();
    $customer = Customer::factory()->inCity('Cairo')->create([
        'full_name' => 'Mona Hassan Ibrahim',
        'phone' => '+201012344417',
    ]);
    $document = IdentityDocument::factory()->for($customer)->passport()->create();

    $this->getJson(CUSTOMERS_BASE)
        ->assertOk()
        ->assertJsonPath('data.0.full_name', 'Mona Hassan Ibrahim')
        ->assertJsonPath('data.0.phone_masked', '+20 10 •••• 4417')
        ->assertJsonPath('data.0.city', 'Cairo')
        ->assertJsonPath('data.0.is_verified', false)
        ->assertJsonPath('data.0.is_suspended', false)
        ->assertJsonPath('data.0.pending_documents.0.document_id', $document->document_id)
        ->assertJsonPath('data.0.pending_documents.0.doc_kind', 'passport');
});

it('never exposes the raw phone or the storage reference', function () {
    queueViewer();
    $customer = Customer::factory()->create(['phone' => '+201012344417']);
    IdentityDocument::factory()->for($customer)->create();

    $body = $this->getJson(CUSTOMERS_BASE)->assertOk()->getContent();

    expect($body)->not->toContain('+201012344417')
        ->not->toContain('201012344417')
        ->not->toContain('storage_ref')
        ->not->toContain('identity/');
});

it('shows a customer once however many documents they have pending', function () {
    queueViewer();
    $customer = Customer::factory()->create();
    IdentityDocument::factory()->for($customer)->create();
    IdentityDocument::factory()->for($customer)->passport()->create();

    $this->getJson(CUSTOMERS_BASE)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(2, 'data.0.pending_documents');
});

it('excludes a customer once their document is decided', function () {
    queueViewer();
    $customer = Customer::factory()->create();
    $document = IdentityDocument::factory()->for($customer)->create();

    $this->getJson(CUSTOMERS_BASE)->assertOk()->assertJsonCount(1, 'data');

    $document->update(['status' => 'approved']);

    $this->getJson(CUSTOMERS_BASE)->assertOk()->assertJsonCount(0, 'data');
});

it('paginates', function () {
    queueViewer();
    foreach (range(1, 3) as $i) {
        IdentityDocument::factory()->create(['created_at' => now()->subHours($i)]);
    }

    $this->getJson(CUSTOMERS_BASE.'?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2);
});

it('opens no document image and logs no view', function () {
    queueViewer();
    IdentityDocument::factory()->withImage(pngBytes())->create();

    $this->getJson(CUSTOMERS_BASE)->assertOk();

    expect(DocumentViewLog::query()->count())->toBe(0);
});

it('validates the filters', function (string $query) {
    queueViewer();

    $this->getJson(CUSTOMERS_BASE.$query)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
})->with(['?verification_status=verified', '?verification_status=suspended', '?per_page=0', '?per_page=500', '?per_page=abc']);

it('refuses a staff member without customer.view', function () {
    queueViewer(StaffRole::OPERATIONS);
    IdentityDocument::factory()->create();

    $this->getJson(CUSTOMERS_BASE)->assertStatus(403)->assertJsonPath('code', 'permission_denied');
});

it('refuses an unauthenticated caller and a customer token', function () {
    $this->getJson(CUSTOMERS_BASE)->assertStatus(401);

    Sanctum::actingAs(Customer::factory()->create(), ['customer:access'], 'customer');
    $this->getJson(CUSTOMERS_BASE)->assertStatus(401);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CustomerQueueTest`
Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the FormRequest**

Create `app/Http/Requests/Dashboard/ListCustomersRequest.php`:

```php
<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCustomersRequest extends FormRequest
{
    /**
     * Deliberately a one-value enum. Verified / rejected / suspended are not
     * built yet, and refusing them outright is what keeps the dashboard from
     * quietly showing a "waiting" list under a "verified" chip.
     */
    public const STATUSES = ['waiting'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verification_status' => ['sometimes', Rule::in(self::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function verificationStatus(): string
    {
        return (string) $this->validated('verification_status', 'waiting');
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
```

- [ ] **Step 4: Write the Action**

Create `app/Actions/Dashboard/ListCustomersForVerificationAction.php`:

```php
<?php

namespace App\Actions\Dashboard;

use App\Enums\IdentityDocumentStatus;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListCustomersForVerificationAction
{
    /**
     * Customers with at least one document still waiting, oldest submission
     * first, so the queue is worked in the order people joined it — the same
     * promise the document queue makes.
     *
     * Rooted in `customer`, not in `identity_document`, so a customer who
     * submitted two documents is one row rather than two.
     *
     * @return LengthAwarePaginator<int, Customer>
     */
    public function handle(Staff $actor, int $perPage): LengthAwarePaginator
    {
        $oldestPending = IdentityDocument::query()
            ->selectRaw('MIN(created_at)')
            ->whereColumn('identity_document.customer_id', 'customer.customer_id')
            ->where('status', IdentityDocumentStatus::PENDING->value);

        return Customer::query()
            ->whereHas('identityDocuments', fn ($q) => $q->pending())
            ->with(['identityDocuments' => fn ($q) => $q->pending()->orderBy('created_at')])
            ->select('customer.*')
            ->selectSub($oldestPending, 'oldest_pending_at')
            ->orderBy('oldest_pending_at')
            ->orderBy('customer_id')
            ->paginate($perPage);
    }
}
```

- [ ] **Step 5: Write the controller**

Create `app/Http/Controllers/Api/V1/Dashboard/CustomerController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Dashboard\ListCustomersForVerificationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ListCustomersRequest;
use App\Http\Resources\Staff\CustomerResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class CustomerController extends Controller
{
    public function __construct(
        private readonly ListCustomersForVerificationAction $list,
    ) {}

    #[OA\Get(
        path: '/dashboard/customers',
        operationId: 'dashboardListCustomers',
        summary: 'List customers waiting for identity verification (oldest first)',
        description: 'Requires permission `customer.view`. Returns the customer name and a masked phone; the raw phone is never included. Listing opens no document image and writes no view log. `verification_status` currently accepts only `waiting`.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [
            new OA\Parameter(name: 'verification_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['waiting'], default: 'waiting')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated customers', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffCustomer')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied (lacks `customer.view`)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListCustomersRequest $request): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            $this->list->handle($request->user('staff'), $request->perPage())
        );
    }
}
```

- [ ] **Step 6: Add the route**

In `routes/api.php`, inside the `Route::prefix('dashboard')` group and **before** the `identity-documents` group, add:

```php
        // The verification queue, rooted in the customer rather than the document.
        // Reading it is `customer.view`; it exposes a name and a masked phone,
        // which `identity.view` deliberately does not.
        Route::middleware(['auth:staff', 'abilities:staff:access'])
            ->prefix('customers')
            ->name('customers.')
            ->group(function () {
                Route::get('/', [CustomerController::class, 'index'])
                    ->middleware('staff.permission:customer.view')
                    ->name('index');
            });
```

Add the import at the top: `use App\Http\Controllers\Api\V1\Dashboard\CustomerController;`.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=CustomerQueueTest`
Expected: PASS, every case.

- [ ] **Step 8: Format**

Run: `./vendor/bin/pint`

---

### Task 8: The customer detail endpoint

**Files:**
- Create: `app/Actions/Dashboard/ShowCustomerAction.php`
- Modify: `app/Http/Controllers/Api/V1/Dashboard/CustomerController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Dashboard/CustomerDetailTest.php` (create)

**Interfaces:**
- Consumes: everything from Task 7.
- Produces: `GET /api/v1/dashboard/customers/{customer}` → `{ data: StaffCustomer }` with a `documents` array (every status, newest first). Route name `api.v1.dashboard.customers.show`. `ShowCustomerAction::handle(Staff $actor, string $customerId): Customer`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Dashboard/CustomerDetailTest.php`:

```php
<?php

use App\Enums\StaffRole;
use App\Models\Customer;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const CUSTOMER_DETAIL_BASE = '/api/v1/dashboard/customers';

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

function detailViewer(StaffRole $role = StaffRole::VERIFICATION): Staff
{
    $staff = Staff::factory()->role($role)->create();
    Sanctum::actingAs($staff, ['staff:access'], 'staff');

    return $staff;
}

it('shows the customer file with every document, newest first', function () {
    detailViewer();
    $customer = Customer::factory()->inCity('Giza')->create([
        'full_name' => 'Youssef N. Kamal',
        'phone' => '+201012344417',
    ]);
    $old = IdentityDocument::factory()->for($customer)->rejected()->create(['created_at' => now()->subDays(2)]);
    $current = IdentityDocument::factory()->for($customer)->create(['created_at' => now()->subHour()]);

    $response = $this->getJson(CUSTOMER_DETAIL_BASE.'/'.$customer->customer_id)
        ->assertOk()
        ->assertJsonPath('data.customer_id', $customer->customer_id)
        ->assertJsonPath('data.full_name', 'Youssef N. Kamal')
        ->assertJsonPath('data.phone_masked', '+20 10 •••• 4417')
        ->assertJsonPath('data.city', 'Giza');

    expect(collect($response->json('data.documents'))->pluck('document_id')->all())
        ->toBe([$current->document_id, $old->document_id]);
    $response->assertJsonMissingPath('data.pending_documents');
});

it('shows a customer with no documents at all', function () {
    detailViewer();
    $customer = Customer::factory()->create();

    $this->getJson(CUSTOMER_DETAIL_BASE.'/'.$customer->customer_id)
        ->assertOk()
        ->assertJsonPath('data.documents', []);
});

it('carries the review outcome of a decided document', function () {
    detailViewer();
    $customer = Customer::factory()->create();
    IdentityDocument::factory()->for($customer)->rejected()->create([
        'review_outcome' => 'changes_requested',
        'review_issues' => ['cut_off'],
        'review_note' => 'Whole card in frame, please.',
    ]);

    $this->getJson(CUSTOMER_DETAIL_BASE.'/'.$customer->customer_id)
        ->assertOk()
        ->assertJsonPath('data.documents.0.review_outcome', 'changes_requested')
        ->assertJsonPath('data.documents.0.review_issues', ['cut_off'])
        ->assertJsonPath('data.documents.0.review_note', 'Whole card in frame, please.');
});

it('opens no image and logs no view', function () {
    detailViewer();
    $customer = Customer::factory()->create();
    IdentityDocument::factory()->for($customer)->withImage(pngBytes())->create();

    $this->getJson(CUSTOMER_DETAIL_BASE.'/'.$customer->customer_id)->assertOk();

    expect(DocumentViewLog::query()->count())->toBe(0);
});

it('answers 404 for an unknown or malformed customer id', function () {
    detailViewer();

    $this->getJson(CUSTOMER_DETAIL_BASE.'/'.Str::uuid())->assertStatus(404)->assertJsonPath('code', 'not_found');
    $this->getJson(CUSTOMER_DETAIL_BASE.'/not-a-uuid')->assertStatus(404);
});

it('cannot tell a missing customer from an existing one without the permission', function () {
    detailViewer(StaffRole::OPERATIONS);
    $customer = Customer::factory()->create();

    $this->getJson(CUSTOMER_DETAIL_BASE.'/'.$customer->customer_id)->assertStatus(403)->assertJsonPath('code', 'permission_denied');
    $this->getJson(CUSTOMER_DETAIL_BASE.'/'.Str::uuid())->assertStatus(403)->assertJsonPath('code', 'permission_denied');
});

it('refuses an unauthenticated caller', function () {
    $customer = Customer::factory()->create();

    $this->getJson(CUSTOMER_DETAIL_BASE.'/'.$customer->customer_id)->assertStatus(401);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=CustomerDetailTest`
Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the Action**

Create `app/Actions/Dashboard/ShowCustomerAction.php`:

```php
<?php

namespace App\Actions\Dashboard;

use App\Models\Customer;
use App\Models\Staff;

final class ShowCustomerAction
{
    /**
     * Looked up here rather than by route-model binding, so the permission
     * middleware answers before the lookup and an unauthorised caller can
     * never tell a missing customer from an existing one — the rule the
     * identity routes already follow.
     */
    public function handle(Staff $actor, string $customerId): Customer
    {
        return Customer::query()
            ->with(['identityDocuments' => fn ($q) => $q->orderByDesc('created_at')])
            ->whereKey($customerId)
            ->firstOrFail();
    }
}
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/Api/V1/Dashboard/CustomerController.php`, add `ShowCustomerAction $show` to the constructor, add the imports `use App\Actions\Dashboard\ShowCustomerAction;` and `use Illuminate\Http\Request;`, then add:

```php
    #[OA\Get(
        path: '/dashboard/customers/{customer}',
        operationId: 'dashboardShowCustomer',
        summary: 'Show a customer file with their identity documents',
        description: 'Requires permission `customer.view`. Returns every document, newest first, as review metadata only — use the identity-documents `/image` endpoint to open an image, which is logged.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The customer', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/StaffCustomer'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied (lacks `customer.view`)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, string $customer): CustomerResource
    {
        return CustomerResource::make($this->show->handle($request->user('staff'), $customer))
            ->additional(['all_documents' => true]);
    }
```

`additional(['all_documents' => true])` is what switches the resource's document key from `pending_documents` to `documents` — see `CustomerResource::toArray()` in Task 6. Note it also appears in the JSON envelope as a top-level `all_documents: true`; if the Step 6 test run flags that as unwanted, replace the mechanism with a public property set via a small named constructor on the resource rather than `additional()`.

- [ ] **Step 5: Add the route**

In `routes/api.php`, inside the `customers` group added in Task 7, after the index route:

```php
                Route::get('/{customer}', [CustomerController::class, 'show'])
                    ->whereUuid('customer')
                    ->middleware('staff.permission:customer.view')
                    ->name('show');
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter="CustomerDetailTest|CustomerQueueTest"`
Expected: PASS, both files.

- [ ] **Step 7: Format**

Run: `./vendor/bin/pint`

---

### Task 9: Contract artefacts and the backend quality gate

**Files:**
- Modify: `postman/Dahab-Backend.postman_collection.json`
- Generated: `storage/api-docs/api-docs.json` (gitignored)

**Interfaces:**
- Consumes: every endpoint from Tasks 1–8.
- Produces: a regenerated OpenAPI document and a Postman collection that mirrors every route in `routes/api.php`.

- [ ] **Step 1: Regenerate the OpenAPI document**

Run: `composer swagger:generate`
Expected: succeeds with no error. `tests/Feature/Auth/Shared/OpenApiGenerationTest.php` covers this — if it fails, an `#[OA\…]` attribute added in Tasks 4, 7 or 8 is malformed.

- [ ] **Step 2: Confirm the new schemas and paths are in the generated document**

Run:

```bash
node -e "const d=require('./storage/api-docs/api-docs.json');console.log(Object.keys(d.paths).filter(p=>p.includes('customers')));console.log(Object.keys(d.components.schemas).filter(s=>s.includes('Customer')))"
```

Expected: `/dashboard/customers` and `/dashboard/customers/{customer}`, plus `StaffCustomer`.

- [ ] **Step 3: Update the Postman collection**

Read `postman/README.md` first and follow its steps exactly. Add, under the existing Dashboard folder:

- `GET {{base_url}}/dashboard/customers?verification_status=waiting&per_page=25` — Bearer `{{staff_token}}`, matching the existing dashboard requests' auth setup.
- `GET {{base_url}}/dashboard/customers/{{customer_id}}` — same auth.

and update the existing review request's body to the widened contract:

```json
{
  "decision": "changes_requested",
  "issues": ["blurred_or_glare", "name_mismatch"],
  "reason": "Take it again in daylight."
}
```

Add a `customer_id` variable to `postman/` environment file if one is not already defined.

- [ ] **Step 4: Verify the collection is valid JSON and every route is covered**

Run:

```bash
node -e "const c=require('./postman/Dahab-Backend.postman_collection.json');const u=JSON.stringify(c).match(/dashboard\/customers[^\"]*/g);console.log(u)"
```

Expected: both customer paths appear.

- [ ] **Step 5: Run the full backend quality gate**

Run in order:

```bash
./vendor/bin/pint
composer test
php artisan migrate:fresh --seed
php artisan route:list --path=api/v1/dashboard
```

Expected: Pint clean · **the whole Pest suite green, not just the new files** · `migrate:fresh --seed` succeeds (run as the `dahab` DB user) · the route list shows `customers.index` and `customers.show` with the `staff.permission:customer.view` middleware.

- [ ] **Step 6: Apply the `laravel-quality-gates` skill**

Invoke the `laravel-quality-gates` skill and work through its checklist, paying attention to its N+1 section: `ListCustomersForVerificationAction` eager-loads `identityDocuments`, and the check is that the queue endpoint issues a constant number of queries regardless of page size.

---

## Phase B — Dashboard

> Phase B cannot start until Phase A is merged and running: every endpoint below must exist. Verify with `curl` or Postman against a running backend before writing frontend code against it.

### Task 10: Types and the customer service

**Files:**
- Create: `src/types/customer.ts`
- Modify: `src/types/api.ts`
- Modify: `src/types/identity.ts`
- Modify: `src/types/staff.ts`
- Modify: `src/api/endpoints.ts`
- Create: `src/services/customer.service.ts`
- Modify: `src/services/identity-document.service.ts`

**Interfaces:**
- Consumes: the Phase A endpoints.
- Produces:
  - `PERMISSIONS.customerView = 'customer.view'`.
  - `endpoints.customers`, `endpoints.customer(id)`.
  - `types/customer.ts`: `CustomerListItem`, `CustomerDetail`, `CustomerPendingDocument`, `CustomerFilters`, `CustomerListResult`, `CUSTOMER_PAGE_SIZE = 10`, `DEFAULT_CUSTOMER_FILTERS`.
  - `types/identity.ts`: `IdentityReviewIssue` union, `IDENTITY_ISSUE_LABELS`, `IdentityReviewOutcome` union; `IdentityDocument` gains `reviewOutcome`, `reviewIssues`, `reviewNote`.
  - `customerService.listCustomers(params)`, `customerService.getCustomer(id)`.
  - `identityDocumentService.askAgainOnDocument(id, issues, note)`.

- [ ] **Step 1: Add the permission and the endpoints**

In `src/types/staff.ts`, add to `PERMISSIONS`:

```ts
  customerView: 'customer.view',
```

In `src/api/endpoints.ts`, add before the closing `} as const`:

```ts
  customers: '/dashboard/customers',
  customer: (id: string) => `/dashboard/customers/${encodeURIComponent(id)}`,
```

- [ ] **Step 2: Extend the identity types**

In `src/types/identity.ts`, add:

```ts
// What a reviewer said was wrong. The Backend sends the code; the wording is ours.
export type IdentityReviewIssue =
  | 'blurred_or_glare'
  | 'cut_off'
  | 'name_mismatch'
  | 'expired'
  | 'back_missing'
  | 'unreadable_text'

export const IDENTITY_ISSUE_LABELS: Record<IdentityReviewIssue, string> = {
  blurred_or_glare: 'Blurred or glare',
  cut_off: 'Card is cut off',
  name_mismatch: 'Name does not match',
  expired: 'Card has expired',
  back_missing: 'Back is missing',
  unreadable_text: 'Text is not readable',
}

// The staff decision, finer grained than `status`. `changes_requested` and
// `refused` are both `status: 'rejected'` — a pending document would block
// the customer's re-upload, so asking again has to reject.
export type IdentityReviewOutcome = 'approved' | 'changes_requested' | 'refused'
```

and add to the `IdentityDocument` interface:

```ts
  reviewOutcome: IdentityReviewOutcome | null
  reviewIssues: IdentityReviewIssue[] | null
  reviewNote: string | null
```

In `src/types/api.ts`, add the same three fields to `ApiIdentityDocument` in snake_case (`review_outcome`, `review_issues`, `review_note`, all nullable), and add:

```ts
export interface ApiCustomer {
  customer_id: string
  display_ref: string
  full_name: string | null
  phone_masked: string
  city: string | null
  is_verified: boolean
  is_suspended: boolean
  created_at: string
  pending_documents?: ApiIdentityDocument[]
  documents?: ApiIdentityDocument[]
}
```

- [ ] **Step 3: Write `src/types/customer.ts`**

```ts
import type { IdentityDocument, IdentityDocumentListItem } from './identity'

// A row on the waiting queue. `fullName` and `city` are both optional at
// registration, so both can be null for a real customer.
export interface CustomerListItem {
  id: string
  displayRef: string
  fullName: string | null
  phoneMasked: string
  city: string | null
  isVerified: boolean
  isSuspended: boolean
  joinedAt: string
  pendingDocuments: IdentityDocumentListItem[]
}

export interface CustomerDetail extends Omit<CustomerListItem, 'pendingDocuments'> {
  // Every document, newest first.
  documents: IdentityDocument[]
}

// Only `waiting` is a real filter today; the Backend refuses any other value.
export type CustomerVerificationStatus = 'waiting'

export interface CustomerFilters {
  status: CustomerVerificationStatus
  page: number
}

export interface CustomerListParams extends CustomerFilters {
  pageSize: number
}

export interface CustomerListResult {
  items: CustomerListItem[]
  total: number
  page: number
  pageSize: number
}

export const CUSTOMER_PAGE_SIZE = 10

export const DEFAULT_CUSTOMER_FILTERS: CustomerFilters = {
  status: 'waiting',
  page: 1,
}
```

- [ ] **Step 4: Write `src/services/customer.service.ts`**

```ts
import type { ApiCustomer, ApiEnvelope, ApiPaginated } from '@/types/api'
import type { CustomerDetail, CustomerListItem, CustomerListParams, CustomerListResult } from '@/types/customer'
import { http } from '@/api/axios'
import { endpoints } from '@/api/endpoints'
import { toIdentityDocument, toIdentityListItem } from './identity-document.service'

function toListItem (c: ApiCustomer): CustomerListItem {
  return {
    id: c.customer_id,
    displayRef: c.display_ref,
    fullName: c.full_name,
    phoneMasked: c.phone_masked,
    city: c.city,
    isVerified: c.is_verified,
    isSuspended: c.is_suspended,
    joinedAt: c.created_at,
    pendingDocuments: (c.pending_documents ?? []).map(d => toIdentityListItem(d)),
  }
}

export const customerService = {
  // GET /dashboard/customers?verification_status=&per_page=&page=   (customer.view)
  // Oldest waiting first. Opens no image, so nothing here is logged.
  async listCustomers (params: CustomerListParams): Promise<CustomerListResult> {
    const { data } = await http.get<ApiPaginated<ApiCustomer>>(endpoints.customers, {
      params: { verification_status: params.status, per_page: params.pageSize, page: params.page },
    })
    return {
      items: data.data.map(c => toListItem(c)),
      total: data.meta.total,
      page: data.meta.current_page,
      pageSize: data.meta.per_page,
    }
  },

  // GET /dashboard/customers/{id}   (customer.view). Metadata only.
  async getCustomer (id: string): Promise<CustomerDetail> {
    const { data } = await http.get<ApiEnvelope<ApiCustomer>>(endpoints.customer(id))
    const c = data.data
    const { pendingDocuments, ...rest } = toListItem(c)
    return { ...rest, documents: (c.documents ?? []).map(d => toIdentityDocument(d)) }
  },
}
```

- [ ] **Step 5: Export the mappers and add "ask again" to the identity service**

In `src/services/identity-document.service.ts`, change `function toListItem` to `export function toIdentityListItem` and `function toDocument` to `export function toIdentityDocument`, updating their call sites inside that file.

Add the three new fields to `toIdentityDocument`'s returned object:

```ts
    reviewOutcome: doc.review_outcome,
    reviewIssues: doc.review_issues,
    reviewNote: doc.review_note,
```

Add to the exported `identityDocumentService` object, after `rejectIdentityDocument`:

```ts
  // Ask the customer to send a better copy. The Backend records this as
  // `rejected` with `review_outcome: 'changes_requested'` — a pending document
  // would block the very re-upload we are asking for.
  askAgainOnDocument (id: string, issues: IdentityReviewIssue[], note: string): Promise<IdentityDocument> {
    const trimmed = note.trim()
    return review(id, { decision: 'changes_requested', issues, ...(trimmed ? { reason: trimmed } : {}) })
  },
```

Widen the private `review()` helper's body parameter type to:

```ts
  body:
    | { decision: 'approved' }
    | { decision: 'rejected', reason: string }
    | { decision: 'changes_requested', issues: IdentityReviewIssue[], reason?: string },
```

and add `IdentityReviewIssue` to the file's type imports from `@/types/identity`.

- [ ] **Step 6: Type-check**

Run: `npm run type-check`
Expected: PASS, no errors.

- [ ] **Step 7: Commit**

```bash
git add src/types src/api/endpoints.ts src/services
git commit -m "feat(customers): types, endpoints and services for the verification queue

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 11: Query composables

**Files:**
- Create: `src/composables/useCustomers.ts`
- Modify: `src/composables/useIdentityDocuments.ts`

**Interfaces:**
- Consumes: `customerService`, `identityDocumentService.askAgainOnDocument` (Task 10).
- Produces: `customerKeys` (`all`, `list`, `detail`), `useCustomersQuery(filters)`, `useCustomerQuery(id)`, `useAskAgainIdentityDocument()`. All three review mutations invalidate `customerKeys.all` as well as `identityKeys.all`.

- [ ] **Step 1: Write `src/composables/useCustomers.ts`**

```ts
import type { CustomerFilters } from '@/types/customer'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { computed, type MaybeRefOrGetter, toValue } from 'vue'
import { errorCodeOf, isServiceError } from '@/services/errors'
import { customerService } from '@/services/customer.service'
import { CUSTOMER_PAGE_SIZE } from '@/types/customer'

export const customerKeys = {
  all: ['customers'] as const,
  list: (filters: CustomerFilters) => [...customerKeys.all, 'list', filters] as const,
  detail: (id: string) => [...customerKeys.all, 'detail', id] as const,
}

// Retrying a 403 or 404 only delays the message. Transient failures get one more try.
function retryTransient (failureCount: number, error: unknown): boolean {
  const code = errorCodeOf(error)
  const transient = !isServiceError(error) || code === 'network' || code === 'unknown'
  return transient && failureCount < 1
}

export function useCustomersQuery (filters: MaybeRefOrGetter<CustomerFilters>) {
  return useQuery({
    queryKey: computed(() => customerKeys.list(toValue(filters))),
    queryFn: () => customerService.listCustomers({
      ...toValue(filters),
      pageSize: CUSTOMER_PAGE_SIZE,
    }),
    placeholderData: keepPreviousData,
    retry: retryTransient,
  })
}

// Only runs once a customer is selected; `null` keeps the panel empty.
export function useCustomerQuery (id: MaybeRefOrGetter<string | null>) {
  return useQuery({
    queryKey: computed(() => customerKeys.detail(toValue(id) ?? '')),
    queryFn: () => customerService.getCustomer(toValue(id)!),
    enabled: computed(() => toValue(id) !== null),
    retry: retryTransient,
  })
}
```

- [ ] **Step 2: Add the ask-again mutation and widen the invalidations**

In `src/composables/useIdentityDocuments.ts`, add the import:

```ts
import { customerKeys } from './useCustomers'
```

Add a shared helper above the three mutations:

```ts
// A decision removes the customer from the waiting queue as well as the
// document queue, so both caches are refreshed.
function invalidateQueues (queryClient: ReturnType<typeof useQueryClient>) {
  queryClient.invalidateQueries({ queryKey: identityKeys.all })
  queryClient.invalidateQueries({ queryKey: customerKeys.all })
}
```

In `useApproveIdentityDocument` and `useRejectIdentityDocument`, replace each
`queryClient.invalidateQueries({ queryKey: identityKeys.all })` inside `onSuccess`
with `invalidateQueues(queryClient)`. In `refreshOnConflict`, replace the same call
with `invalidateQueues(queryClient)`.

Add the new mutation:

```ts
export function useAskAgainIdentityDocument () {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { id: string, issues: IdentityReviewIssue[], note: string }) =>
      identityDocumentService.askAgainOnDocument(input.id, input.issues, input.note),
    onError: refreshOnConflict(queryClient),
    onSuccess: doc => {
      queryClient.setQueryData(identityKeys.detail(doc.id), doc)
      invalidateQueues(queryClient)
    },
  })
}
```

Add `IdentityReviewIssue` to the type import from `@/types/identity`.

- [ ] **Step 3: Type-check**

Run: `npm run type-check`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/composables
git commit -m "feat(customers): queries for the waiting queue and the ask-again mutation

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 12: The "Ask again" modal

**Files:**
- Create: `src/components/identity/AskAgainModal.vue`

**Interfaces:**
- Consumes: `IDENTITY_ISSUE_LABELS`, `IdentityReviewIssue` (Task 10).
- Produces: `<AskAgainModal v-model="open" :loading :submit-error @confirm="(issues, note) => …" />`. `confirm` fires only when at least one issue is ticked. Modelled on the existing `RejectDocumentModal` — read that file first and match its structure, prop names and styling.

- [ ] **Step 1: Read the component it mirrors**

Read `src/components/identity/RejectDocumentModal.vue` in full. Match its `defineProps` / `defineEmits` shape, its use of `DModal`, `DBtn`, `DTextarea` and `FormField`, and its error display. Do not invent a different convention.

- [ ] **Step 2: Write the component**

```vue
<template>
  <DModal v-model="open" :max-width="520" title="What is wrong with it">
    <p class="d-ask__lead">
      They get a notification and a text message, and the app shows them exactly what to fix.
    </p>

    <label v-for="issue in ISSUES" :key="issue" class="d-ask__check">
      <input v-model="picked" :value="issue" type="checkbox">
      {{ IDENTITY_ISSUE_LABELS[issue] }}
    </label>

    <FormField class="d-ask__note" label="Anything else, in your own words">
      <DTextarea v-model="note" :maxlength="1000" placeholder="This goes to them." :rows="3" />
    </FormField>

    <NoteBanner v-if="submitError" class="d-ask__error" role="alert" variant="bad">{{ submitError }}</NoteBanner>

    <template #actions>
      <DBtn :disabled="loading" @click="open = false">Cancel</DBtn>
      <DBtn :disabled="picked.length === 0" kind="primary" :loading="loading" @click="onConfirm">Send it</DBtn>
    </template>
  </DModal>
</template>

<script lang="ts" setup>
  import { ref, watch } from 'vue'
  import DBtn from '@/components/ui/DBtn.vue'
  import DModal from '@/components/ui/DModal.vue'
  import DTextarea from '@/components/ui/DTextarea.vue'
  import FormField from '@/components/ui/FormField.vue'
  import NoteBanner from '@/components/ui/NoteBanner.vue'
  import { IDENTITY_ISSUE_LABELS, type IdentityReviewIssue } from '@/types/identity'

  const props = defineProps<{ loading: boolean, submitError: string }>()
  const emit = defineEmits<{ (e: 'confirm', issues: IdentityReviewIssue[], note: string): void }>()

  const open = defineModel<boolean>({ required: true })

  // The order the design lists them in.
  const ISSUES: IdentityReviewIssue[] = [
    'blurred_or_glare',
    'cut_off',
    'name_mismatch',
    'expired',
    'back_missing',
    'unreadable_text',
  ]

  const picked = ref<IdentityReviewIssue[]>([])
  const note = ref('')

  // A reopened dialog starts clean, so yesterday's ticks never travel to another customer.
  watch(open, isOpen => {
    if (isOpen) {
      picked.value = []
      note.value = ''
    }
  })

  function onConfirm () {
    if (picked.value.length === 0) return
    emit('confirm', [...picked.value], note.value)
  }
</script>

<style lang="scss" scoped>
.d-ask__lead { margin: 0 0 12px; font-size: 11.5px; color: var(--ink-3); }
.d-ask__check { display: flex; align-items: center; gap: 8px; margin-bottom: 7px; font-size: 12px; cursor: pointer; }
.d-ask__note { margin-top: 12px; }
.d-ask__error { margin-top: 12px; }
</style>
```

If `RejectDocumentModal` uses a different actions-slot name, a different modal prop for width, or does not use `defineModel`, follow **its** convention rather than this sketch — the codebase's existing pattern wins.

- [ ] **Step 3: Type-check and lint**

Run: `npm run type-check && npm run lint`
Expected: PASS both.

- [ ] **Step 4: Commit**

```bash
git add src/components/identity/AskAgainModal.vue
git commit -m "feat(identity): ask-again modal with the six issue reasons

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 13: The queue table and the verification panel

**Files:**
- Create: `src/components/customers/CustomerQueueTable.vue`
- Create: `src/components/customers/CustomerVerificationPanel.vue`

**Interfaces:**
- Consumes: `CustomerListItem`, `CustomerDetail`, `useCustomerQuery`, the three review mutations, `AskAgainModal`, `RejectDocumentModal`, `ConfirmDialog`, `DocumentPreview`.
- Produces:
  - `<CustomerQueueTable :rows="CustomerListItem[]" :selected-id="string | null" @select="(id: string) => …" />`
  - `<CustomerVerificationPanel :customer-id="string | null" @decided="() => …" />`

- [ ] **Step 1: Read the components these mirror**

Read `src/components/identity/IdentityDocumentsTable.vue` and `src/components/identity/IdentityReviewPanel.vue` in full. The table must follow the former's use of `DataTable` and its emit convention; the panel must follow the latter's permission gate, `FAILURE_TEXT` map, `describe()` helper and `notice` ref. Reuse `describe()` and `FAILURE_TEXT` by lifting them into the new panel verbatim — they encode error handling already agreed with the backend's error codes.

- [ ] **Step 2: Write `CustomerQueueTable.vue`**

Columns, per the design: **Person** (name over `city · joined <relative>`), **Document** (the pending document's type label), **Submitted** (relative), and a right-aligned **Review** button. There is deliberately **no Type column** — customer type lives in `market_maker_approval`, which is not migrated, and inventing it is forbidden.

```vue
<template>
  <DataTable :columns="COLUMNS" :rows="rows">
    <template #row="{ row }">
      <td>
        <div class="d-queue__name">{{ row.fullName ?? `Customer ${row.displayRef}` }}</div>
        <div class="d-queue__sub">{{ subtitle(row) }}</div>
      </td>
      <td>{{ documentLabel(row) }}</td>
      <td>{{ submittedLabel(row) }}</td>
      <td class="d-queue__actions">
        <DBtn :kind="row.id === selectedId ? 'primary' : 'default'" @click="emit('select', row.id)">Review</DBtn>
      </td>
    </template>
  </DataTable>
</template>

<script lang="ts" setup>
  import type { CustomerListItem } from '@/types/customer'
  import DataTable from '@/components/ui/DataTable.vue'
  import DBtn from '@/components/ui/DBtn.vue'
  import { formatRelative } from '@/composables/useFormatters'
  import { IDENTITY_TYPE_LABELS } from '@/types/identity'

  defineProps<{ rows: CustomerListItem[], selectedId: string | null }>()
  const emit = defineEmits<{ (e: 'select', id: string): void }>()

  const COLUMNS = [
    { key: 'person', label: 'Person' },
    { key: 'document', label: 'Document' },
    { key: 'submitted', label: 'Submitted' },
    { key: 'actions', label: '', align: 'right' as const },
  ]

  // "Cairo · joined 3 days ago", or just the joined part when no city was given.
  function subtitle (row: CustomerListItem): string {
    const joined = `joined ${formatRelative(row.joinedAt)}`
    return row.city ? `${row.city} · ${joined}` : joined
  }

  function documentLabel (row: CustomerListItem): string {
    const first = row.pendingDocuments[0]
    if (!first) return '—'
    const extra = row.pendingDocuments.length - 1
    return extra > 0
      ? `${IDENTITY_TYPE_LABELS[first.type]} +${extra}`
      : IDENTITY_TYPE_LABELS[first.type]
  }

  function submittedLabel (row: CustomerListItem): string {
    const first = row.pendingDocuments[0]
    return first ? formatRelative(first.submittedAt) : '—'
  }
</script>

<style lang="scss" scoped>
.d-queue__name { font-size: 12.5px; }
.d-queue__sub { font-size: 11px; color: var(--ink-3); margin-top: 2px; }
.d-queue__actions { text-align: right; }
</style>
```

Check `src/composables/useFormatters.ts` for the actual relative-time export name before using `formatRelative`; if it is named differently (for example `formatRelativeTime`), use the real name. Check `DataTable.vue`'s real props and slot contract and match it — the sketch above assumes `columns` + a `row` scoped slot.

- [ ] **Step 3: Write `CustomerVerificationPanel.vue`**

The panel: header with the customer's name and the document type; `DocumentPreview` for each pending document; `KeyValueRow`s for **Name on the account**, **Phone**, **Where**; the info `NoteBanner` from the design; then the three buttons.

Rules this component must honour:
- **No "Payout account" row.** `payout_account` is not migrated. Omit it rather than render a stand-in.
- **One preview tile per pending document**, not a Front/Back pair — `identity_document` has one `storage_ref` per row.
- Show `NoteBanner variant="wait"` with "You can view this customer but not decide" when `!can(PERMISSIONS.identityReview)`, exactly as `IdentityReviewPanel` does.
- Show a `NoteBanner variant="bad"` when `customer.isSuspended` — the reviewer must know.
- After a successful decision, `emit('decided')` and let the page move on.

```vue
<script lang="ts" setup>
  import { computed, ref } from 'vue'
  import AskAgainModal from '@/components/identity/AskAgainModal.vue'
  import DocumentPreview from '@/components/identity/DocumentPreview.vue'
  import RejectDocumentModal from '@/components/identity/RejectDocumentModal.vue'
  import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
  import DBtn from '@/components/ui/DBtn.vue'
  import EmptyState from '@/components/ui/EmptyState.vue'
  import ErrorState from '@/components/ui/ErrorState.vue'
  import KeyValueRow from '@/components/ui/KeyValueRow.vue'
  import LoadingState from '@/components/ui/LoadingState.vue'
  import NoteBanner from '@/components/ui/NoteBanner.vue'
  import Panel from '@/components/ui/Panel.vue'
  import { useCustomerQuery } from '@/composables/useCustomers'
  import {
    useApproveIdentityDocument,
    useAskAgainIdentityDocument,
    useRejectIdentityDocument,
  } from '@/composables/useIdentityDocuments'
  import { usePermissions } from '@/composables/usePermissions'
  import { useToast } from '@/composables/useToast'
  import { errorCodeOf, isServiceError, type ServiceErrorCode } from '@/services/errors'
  import { IDENTITY_TYPE_LABELS, type IdentityReviewIssue } from '@/types/identity'
  import { PERMISSIONS } from '@/types/staff'

  const props = defineProps<{ customerId: string | null }>()
  const emit = defineEmits<{ (e: 'decided'): void }>()

  const { can } = usePermissions()
  const { ok } = useToast()
  const { data: customer, error, isPending, isError, refetch } = useCustomerQuery(() => props.customerId)

  const approve = useApproveIdentityDocument()
  const askAgain = useAskAgainIdentityDocument()
  const reject = useRejectIdentityDocument()

  const approveOpen = ref(false)
  const askOpen = ref(false)
  const rejectOpen = ref(false)
  const approveError = ref('')
  const askError = ref('')
  const rejectError = ref('')
  const notice = ref('')

  const canReview = computed(() => can(PERMISSIONS.identityReview))
  // Decisions are per document. The queue orders oldest first, so the first
  // pending document is the one being decided.
  const target = computed(() => customer.value?.documents.find(d => d.status === 'pending') ?? null)
  const typeLabel = computed(() => (target.value ? IDENTITY_TYPE_LABELS[target.value.type] : ''))

  // Lifted verbatim from IdentityReviewPanel: these map the Backend's own error codes.
  const FAILURE_TEXT: Partial<Record<ServiceErrorCode, string>> = {
    conflict: 'Someone else already decided on this document. The page now shows the latest decision.',
    forbidden: 'Your account does not have permission to review documents.',
    not_found: 'This document no longer exists.',
    network: 'The server could not be reached. Nothing was changed. Try again.',
    validation: 'Check what you sent and try again.',
    too_many_requests: 'Too many requests. Wait a moment and try again.',
  }

  function describe (err: unknown): { text: string, keepOpen: boolean } {
    const code = errorCodeOf(err)
    const fieldMessage = isServiceError(err) && code === 'validation'
      ? (err.fields.reason?.[0] ?? err.fields.issues?.[0])
      : undefined
    return {
      text: fieldMessage ?? FAILURE_TEXT[code] ?? 'Something went wrong. Nothing was changed. Try again.',
      keepOpen: code !== 'conflict' && code !== 'forbidden' && code !== 'not_found',
    }
  }

  async function run (
    action: () => Promise<unknown>,
    slot: typeof approveError,
    close: typeof approveOpen,
    message: string,
  ) {
    slot.value = ''
    try {
      await action()
      close.value = false
      // What was recorded and queued. The response is the decision, not the
      // delivery: the notification is queued after commit and the Backend
      // reports no delivery status, so this must never claim one.
      ok(message)
      emit('decided')
    } catch (err) {
      const { text, keepOpen } = describe(err)
      if (keepOpen) slot.value = text
      else {
        close.value = false
        notice.value = text
      }
    }
  }

  function onApprove () {
    if (!target.value) return
    run(() => approve.mutateAsync(target.value!.id), approveError, approveOpen,
      'Verified. They can now buy and sell.')
  }

  function onAskAgain (issues: IdentityReviewIssue[], note: string) {
    if (!target.value) return
    run(() => askAgain.mutateAsync({ id: target.value!.id, issues, note }), askError, askOpen,
      'Asked again. They have been told what to fix by notification and text message.')
  }

  function onReject (reason: string) {
    if (!target.value) return
    run(() => reject.mutateAsync({ id: target.value!.id, reason }), rejectError, rejectOpen,
      'Rejected. The reason is recorded with your name.')
  }
</script>
```

Write the matching `<template>`: `EmptyState` when `customerId === null` ("Pick someone from the queue"), `LoadingState` while `isPending`, `ErrorState` (non-retryable when `errorCodeOf(error) === 'forbidden'`) while `isError`, and otherwise the panel body described above plus the three dialogs (`ConfirmDialog` for Verify, `AskAgainModal`, `RejectDocumentModal`) wired to the three handlers.

- [ ] **Step 4: Type-check and lint**

Run: `npm run type-check && npm run lint`
Expected: PASS both.

- [ ] **Step 5: Commit**

```bash
git add src/components/customers
git commit -m "feat(customers): waiting-queue table and verification panel

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 14: The page and its route

**Files:**
- Create: `src/pages/customers/index.vue`
- Modify: `src/router/index.ts`

**Interfaces:**
- Consumes: everything from Tasks 10–13.
- Produces: route `name: 'users'`, `path: 'dashboard/users'`, `meta: { title: 'Users and verification', permissions: [PERMISSIONS.customerView] }`, replacing the placeholder entry.

- [ ] **Step 1: Swap the placeholder for the real route**

In `src/router/index.ts`, **delete** this line from the `placeholderChildren` array:

```ts
  { path: 'users', name: 'users', component: Placeholder, meta: { title: 'Users and verification' } },
```

and add a real child route alongside the existing identity-documents routes:

```ts
  {
    path: 'dashboard/users',
    name: 'users',
    component: () => import('@/pages/customers/index.vue'),
    meta: { title: 'Users and verification', permissions: [PERMISSIONS.customerView] },
  },
```

Place it exactly where the identity-documents route is declared, following the same registration pattern that file already uses for authenticated children.

- [ ] **Step 2: Write the page**

```vue
<template>
  <div class="d-page">
    <PageLead>
      Identity documents are the most sensitive data here. Every view is logged, whether or not it
      leads to a decision.
    </PageLead>

    <FilterChips :model-value="filters.status" :options="statusOptions" @update:model-value="() => {}" />

    <div class="d-split">
      <Panel class="d-split__left" title="Waiting for verification">
        <template #filters>
          <DBtn disabled title="There is no export endpoint yet.">Export all customers</DBtn>
        </template>

        <LoadingState v-if="isPending" label="Loading the queue…" />

        <ErrorState
          v-else-if="isError"
          :message="errorMessage"
          :retryable="!isForbidden"
          title="Could not load the queue"
          @retry="refetch()"
        />

        <EmptyState
          v-else-if="!data || data.items.length === 0"
          description="Everyone who submitted a document has been reviewed."
          title="Nobody is waiting"
        />

        <template v-else>
          <div :class="{ 'd-page__stale': isPlaceholderData }">
            <CustomerQueueTable :rows="data.items" :selected-id="selectedId" @select="select" />
          </div>

          <Pagination
            :page="data.page"
            :page-size="data.pageSize"
            :total="data.total"
            @update:page="goToPage"
          />
        </template>
      </Panel>

      <CustomerVerificationPanel class="d-split__right" :customer-id="selectedId" @decided="onDecided" />
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { computed, watch } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import CustomerQueueTable from '@/components/customers/CustomerQueueTable.vue'
  import CustomerVerificationPanel from '@/components/customers/CustomerVerificationPanel.vue'
  import DBtn from '@/components/ui/DBtn.vue'
  import EmptyState from '@/components/ui/EmptyState.vue'
  import ErrorState from '@/components/ui/ErrorState.vue'
  import FilterChips from '@/components/ui/FilterChips.vue'
  import LoadingState from '@/components/ui/LoadingState.vue'
  import PageLead from '@/components/ui/PageLead.vue'
  import Pagination from '@/components/ui/Pagination.vue'
  import Panel from '@/components/ui/Panel.vue'
  import { useCustomersQuery } from '@/composables/useCustomers'
  import { errorCodeOf } from '@/services/errors'

  const route = useRoute()
  const router = useRouter()

  // The selected customer lives in the URL, so the panel survives a reload and
  // Back steps through the queue.
  const selectedId = computed(() => {
    const value = route.query.customer
    return typeof value === 'string' && value !== '' ? value : null
  })

  const filters = computed(() => ({
    status: 'waiting' as const,
    page: Number(route.query.page ?? 1) || 1,
  }))

  const { data, error, isPending, isError, isPlaceholderData, refetch } = useCustomersQuery(filters)

  const failure = computed(() => errorCodeOf(error.value))
  const isForbidden = computed(() => failure.value === 'forbidden')
  const errorMessage = computed(() => {
    if (isForbidden.value) return 'Your account does not have permission to view customers.'
    if (failure.value === 'too_many_requests') return 'Too many requests. Wait a moment and try again.'
    return 'The queue could not be loaded. Check your connection and try again.'
  })

  // Only `waiting` is a real filter. The other three stay visible so the page
  // matches the design, and stay disabled because no endpoint lists them —
  // a chip that silently showed the waiting list would be a lie.
  const statusOptions = computed(() => [
    { value: 'waiting' as const, label: 'Waiting', count: data.value?.total },
    { value: 'verified' as const, label: 'Verified', disabled: true, title: 'Not available yet.' },
    { value: 'rejected' as const, label: 'Rejected', disabled: true, title: 'Not available yet.' },
    { value: 'suspended' as const, label: 'Suspended', disabled: true, title: 'Not available yet.' },
  ])

  function select (id: string) {
    router.replace({ query: { ...route.query, customer: id } })
  }

  function goToPage (page: number) {
    const { customer, ...rest } = route.query
    router.replace({ query: { ...rest, page: String(page) } })
  }

  // The decided customer leaves the queue, so the panel must not keep showing them.
  function onDecided () {
    const { customer, ...rest } = route.query
    router.replace({ query: rest })
  }

  // A decision shortens the queue: if the page being shown no longer exists, go to the last one.
  watch(data, result => {
    if (result && result.items.length === 0 && result.total > 0 && result.page > 1) {
      goToPage(Math.ceil(result.total / result.pageSize))
    }
  })
</script>

<style lang="scss" scoped>
.d-page { display: block; }
.d-page__stale { opacity: .55; transition: opacity .15s ease; }
.d-split {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 18px;
  align-items: start;
}
.d-split__left, .d-split__right { min-width: 0; }
@media (max-width: 1100px) {
  .d-split { grid-template-columns: 1fr; gap: 14px; }
}
</style>
```

`FilterChips` is generic over `T extends string`, so the disabled options may carry values the query never uses; `@update:model-value` is a no-op because the only enabled chip is already selected.

- [ ] **Step 3: Type-check and lint**

Run: `npm run type-check && npm run lint`
Expected: PASS both.

- [ ] **Step 4: Commit**

```bash
git add src/pages/customers src/router/index.ts
git commit -m "feat(customers): users and verification page replaces the placeholder

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 15: End-to-end verification against the real backend

**Files:** none — this task only verifies.

**Interfaces:**
- Consumes: everything.
- Produces: evidence that the page works against the running API.

- [ ] **Step 1: Seed data to review**

In `dahab-backend`, run `php artisan migrate:fresh --seed` (as the `dahab` DB user), then use `php artisan tinker` to create three customers with pending documents and a stored image:

```php
\App\Models\Customer::factory()->count(3)->inCity('Cairo')->create()
    ->each(fn ($c) => \App\Models\IdentityDocument::factory()->for($c)->withImage()->create());
```

- [ ] **Step 2: Run both sides**

Start the backend (Laragon or `php artisan serve`). In `dahab-dashboard`, confirm `.env` has `VITE_API_BASE_URL=<backend origin>/api/v1`, confirm the backend's `CORS_ALLOWED_ORIGINS` includes the Vite origin, then run `npm run dev`.

- [ ] **Step 3: Walk the flow and confirm each claim**

Sign in as a staff member holding `customer.view` and `identity.review`, open `/dashboard/users`, and verify:

1. Three customers appear, oldest first, each with name, city and "joined …".
2. Verified / Rejected / Suspended chips are visible, greyed and unclickable.
3. Clicking **Review** puts `?customer=<uuid>` in the URL and loads the panel.
4. The document image renders — and **`document_view_log` gains exactly one row per panel open, and none from loading the list.** Check with `select count(*) from document_view_log;` before and after.
5. **Ask again** with two issues ticked → the row leaves the queue; `identity_document` shows `status='rejected'`, `review_outcome='changes_requested'`, `review_issues` holding both codes.
6. The queued notification is visible in `storage/logs/laravel.log` (the `log` SMS driver), carrying the two issue sentences.
7. **Verify** on another → that customer's `is_verified` becomes true.
8. Reload with `?customer=<uuid>` still in the URL → the panel restores.
9. Sign in as a staff member **without** `customer.view` (for example the `operations` role) → `/dashboard/users` lands on the forbidden page.

- [ ] **Step 4: Report honestly**

Write up what passed and what did not, with the actual output for anything that failed. Do not report the feature complete unless every one of the nine checks above passed.

- [ ] **Step 5: Final gate on both repos**

```bash
# dahab-backend
./vendor/bin/pint && composer test && composer swagger:generate

# dahab-dashboard
npm run type-check && npm run lint && npm run build
```

Expected: all green.

---

## Self-Review

**Spec coverage.** Every spec section maps to a task: permission → 2 · `city` migration and registration → 1 · review columns → 3 · `IdentityReviewIssue` / `Outcome` → 3 · widened review contract → 4 · decision→state table → 4 · notification over the existing abstraction → 5 · `MaskedPhone` and `StaffCustomer` → 6 · list endpoint and ordering → 7 · detail endpoint and no-route-model-binding → 8 · OpenAPI and Postman → 9 · dashboard reuse table → 10–13 · chips and deviations → 14 · gaps reported → Step 4 of Task 15.

**Known soft spots**, flagged rather than hidden — each has an explicit fallback in its step:
- `MailMessage::lines()` may not exist in Laravel 12 (Task 5 Step 4).
- `->additional(['all_documents' => true])` leaks a top-level key into the envelope (Task 8 Step 4).
- `DataTable`, `RejectDocumentModal` and `useFormatters` contracts are assumed from sibling usage; each step says to read the real file and follow it (Tasks 12, 13).

**Type consistency.** `IdentityReviewOutcome` / `IdentityReviewIssue` keep the same names and values across PHP enums, the wire, `types/identity.ts` and the components. The Action's signature change in Task 4 is stated in that task's Interfaces block, and its only caller — `IdentityDocumentController::review` — is updated in the same step. `toIdentityListItem` / `toIdentityDocument` are renamed in Task 10 Step 5 and consumed under those names by `customer.service.ts` in the same task.
