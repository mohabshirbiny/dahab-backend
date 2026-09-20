---
name: "laravel-api-endpoints"
description: "Build a /api/v1 endpoint end to end — route, thin controller, FormRequest validation, API Resource, ApiResponse envelope, error codes and OpenAPI (l5-swagger) attributes. Use for any new or changed HTTP endpoint."
argument-hint: "Endpoint (METHOD /path) and the spec section it implements"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

## Source of truth

Read the endpoint's contract in `docs/Technical Spec/dahab-spec-part2-api.md` (paths, payloads, status codes, error codes) and the feature's `contracts/` from `/speckit-plan`. Implement it exactly as written.

## Generate

```bash
php artisan make:controller Api/V1/<Name>Controller
php artisan make:request <Domain>/<Action>Request
php artisan make:resource <Name>Resource
```

## Layers

1. **Route** in `routes/api.php` inside the existing `Route::prefix('v1')->name('api.v1.')` group. Named routes (`api.v1.<surface>.<resource>.<action>`). Customer endpoints live under `/api/v1/customer/*` behind `auth:customer` + `abilities:customer:access`; dashboard endpoints under `/api/v1/dashboard/*` behind `auth:staff` + `abilities:staff:access` (+ `staff.permission:<code>`). Never `auth:sanctum` (`laravel-auth-authorization`).
2. **FormRequest**: all validation in `rules()`; `authorize()` delegates to a policy/gate. Money/grams validated as `decimal:0,<scale>` strings, not numbers.
3. **Controller**: thin — get validated data, call one Action, return a Resource. No queries, no transactions, no business rules.
   ```php
   public function store(CreateBuyRequestRequest $request, CreateBuyRequest $action): JsonResponse
   {
       $buyRequest = $action->handle($request->user(), $request->validated());

       return BuyRequestResource::make($buyRequest)->response()->setStatusCode(201);
   }
   ```
4. **Resource**: explicit whitelist of fields; decimals as strings; enums as `->value`; dates ISO-8601. Never return a raw model.
5. **Envelope**: success responses are `{ "data": ..., "meta"?: ... }` (Resource default or `App\Support\ApiResponse::ok()`). Errors are `{ "message", "code", "errors"? }`, rendered centrally in `bootstrap/app.php`. For domain refusals, throw a domain exception that renders with a stable `code` (add a renderer to `bootstrap/app.php`) instead of building ad-hoc JSON in controllers.
6. **Pagination**: `->paginate()` / `->cursorPaginate()` through `Resource::collection()`. Never unbounded lists.

## OpenAPI (required on every /api/v1 endpoint)

Use PHP attributes (`use OpenApi\Attributes as OA;`) on the controller method, matching the base declared in `App\Http\Controllers\Controller`:

```php
#[OA\Post(
    path: '/buy-requests',
    operationId: 'createBuyRequest',
    tags: ['Buy Requests'],
    security: [['customerBearer' => []]], // dashboard endpoints: [['dashboardBearer' => []]]; no generic `sanctum` scheme
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateBuyRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/BuyRequest')),
        new OA\Response(response: 422, description: 'Validation failed'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
    ],
)]
```

Put `#[OA\Schema]` on the FormRequest/Resource classes. Then run `composer swagger:generate` and fix any warnings.

## Done when

- `php artisan route:list --path=api/v1` shows the route with the right middleware.
- Feature test covers the happy path and at least one refusal (`laravel-pest-testing`).
- Swagger generation passes.
