<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Customers\ListCustomerActivityAction;
use App\Actions\Customers\ListCustomerSessionsAction;
use App\Actions\Customers\ReinstateCustomerAction;
use App\Actions\Customers\SuspendCustomerAction;
use App\Actions\Dashboard\ListCustomersForVerificationAction;
use App\Actions\Dashboard\ShowCustomerVerificationDetailsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Customers\CustomerActivityRequest;
use App\Http\Requests\Dashboard\Customers\ReinstateCustomerRequest;
use App\Http\Requests\Dashboard\Customers\SuspendCustomerRequest;
use App\Http\Requests\Dashboard\ListCustomersRequest;
use App\Http\Resources\Staff\CustomerFileResource;
use App\Http\Resources\Staff\CustomerSessionResource;
use App\Http\Resources\Staff\CustomerVerificationResource;
use App\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Dashboard "Users and Verification" page and the Customer file (docs
 * Part 2 §10, spec 007). The list tabs map 1:1 to the CustomerStatus enum
 * values, or `q` finds one customer in any state; the file loads every
 * identity document and the suspension details and audits the view.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly ListCustomersForVerificationAction $list,
        private readonly ShowCustomerVerificationDetailsAction $show,
        private readonly SuspendCustomerAction $suspend,
        private readonly ReinstateCustomerAction $reinstate,
    ) {}

    #[OA\Get(
        path: '/dashboard/customers',
        operationId: 'dashboardListCustomers',
        summary: 'List customers by verification status, or find one',
        description: 'Tabs: pending_verification (Waiting) / active (Verified) / rejected / suspended. With `q`, an exact match on the display reference or the E.164 phone (spaces ignored) across every state; `status` is then ignored. Requires customer.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending_verification', 'active', 'rejected', 'suspended'], default: 'pending_verification')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Display reference or E.164 phone; exact match (spec 007)', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 32)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated customers', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffCustomerVerification')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListCustomersRequest $request): AnonymousResourceCollection
    {
        return CustomerVerificationResource::collection(
            $this->list->handle($request->status(), $request->perPage(), $request->search())
        );
    }

    #[OA\Get(
        path: '/dashboard/customers/{customer}',
        operationId: 'dashboardShowCustomerVerification',
        summary: 'Customer file (audited)',
        description: 'The customer file (spec 007): profile, every identity document ever attached (newest first, with reviewer) and the suspension details. Requires customer.view. Each call writes one audit entry (auth.customer.verification_details_viewed).',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Customer file', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/StaffCustomerFile'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, string $customer): CustomerFileResource
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');

        return CustomerFileResource::make(
            $this->show->handle($request->user('staff'), $customer, $ctx)
        );
    }

    #[OA\Post(
        path: '/dashboard/customers/{customer}/suspend',
        operationId: 'dashboardSuspendCustomer',
        summary: 'Suspend a customer (audited, idempotent)',
        description: 'Spec 007 US2, Part 2 §543. Any state may be suspended; the state it interrupts is kept and restored on reinstate. The customer can still sign in and read their data; trade-gated actions answer 403 account_suspended. Requires customer.suspend and an Idempotency-Key. Writes one auth.customer.suspended audit entry (reason = the note).',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'A client-generated UUID; a replay returns the stored response with Idempotent-Replayed: true', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardSuspendCustomer')),
        responses: [
            new OA\Response(response: 200, description: 'The customer file after the change', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/StaffCustomerFile'),
            ])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'customer_already_suspended · idempotency_in_progress', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed · idempotency_key_mismatch', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function suspend(SuspendCustomerRequest $request, string $customer): CustomerFileResource
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');

        return CustomerFileResource::make(
            $this->suspend->handle($request->user('staff'), $customer, $request->reason(), $request->note(), $ctx)
        );
    }

    #[OA\Post(
        path: '/dashboard/customers/{customer}/reinstate',
        operationId: 'dashboardReinstateCustomer',
        summary: 'Reinstate a suspended customer (audited, idempotent)',
        description: 'Spec 007 US2. Returns the customer to exactly the state the suspension interrupted and clears the suspension details (the audit log keeps them). Requires customer.suspend and an Idempotency-Key. Writes one auth.customer.unsuspended audit entry (reason = the note).',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'A client-generated UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DashboardReinstateCustomer')),
        responses: [
            new OA\Response(response: 200, description: 'The customer file after the change', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/StaffCustomerFile'),
            ])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'customer_not_suspended · idempotency_in_progress', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed · idempotency_key_mismatch', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function reinstate(ReinstateCustomerRequest $request, string $customer): CustomerFileResource
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');

        return CustomerFileResource::make(
            $this->reinstate->handle($request->user('staff'), $customer, $request->note(), $ctx)
        );
    }

    #[OA\Get(
        path: '/dashboard/customers/{customer}/activity',
        operationId: 'dashboardCustomerActivity',
        summary: 'A customer file\'s History',
        description: 'Spec 007 US3. Audit entries where the customer acted, or where they or one of their identity documents is the subject; newest first, keyset-paginated; token refreshes (auth.token.rotated) left out. Same entry shape and labels as the Audit log. Requires customer.view and audit.view_all or audit.view_own; with view_own only the viewer\'s own actions are listed. Not audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, description: 'meta.next_cursor of the previous page', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of History', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardAuditEntry')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed (e.g. a malformed cursor)', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function activity(CustomerActivityRequest $request, string $customer, ListCustomerActivityAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($request->user('staff'), $customer, $request->cursor(), $perPage);

        return response()->json([
            'data' => $page['entries'],
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/customers/{customer}/sessions',
        operationId: 'dashboardCustomerSessions',
        summary: 'A customer file\'s Sign-ins and devices',
        description: 'Spec 007 US3. Every trusted device (newest last seen first) and the sessions still open (one per token family, newest activity first, paginated; meta applies to sessions). Sign-out deletes a session, so ended sessions appear in History instead. Never returns token values, abilities or full fingerprints. Requires customer.view. Not audited.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Devices and open sessions', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'devices', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffCustomerDevice')),
                    new OA\Property(property: 'sessions', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffCustomerSession')),
                ], type: 'object'),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'current_page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'last_page', type: 'integer'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function sessions(CustomerActivityRequest $request, string $customer, ListCustomerSessionsAction $list): JsonResponse
    {
        $result = $list->handle($customer, $request->perPage());
        $sessions = $result['sessions'];

        return response()->json([
            'data' => [
                'devices' => $result['devices']->values(),
                'sessions' => CustomerSessionResource::collection($sessions->getCollection())->resolve($request),
            ],
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }
}
