<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Dashboard\ListCustomersForVerificationAction;
use App\Actions\Dashboard\ShowCustomerVerificationDetailsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ListCustomersRequest;
use App\Http\Resources\Staff\CustomerVerificationResource;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Dashboard "Users and Verification" page (docs Part 2 §10). The list tabs
 * map 1:1 to the CustomerStatus enum values; the detail view loads the
 * latest identity document and audits the view.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly ListCustomersForVerificationAction $list,
        private readonly ShowCustomerVerificationDetailsAction $show,
    ) {}

    #[OA\Get(
        path: '/dashboard/customers',
        operationId: 'dashboardListCustomers',
        summary: 'List customers by verification status',
        description: 'Tabs: pending_verification (Waiting) / active (Verified) / rejected / suspended. Requires customer.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending_verification', 'active', 'rejected', 'suspended'], default: 'pending_verification')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated customers', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffCustomerVerification')),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(ListCustomersRequest $request): AnonymousResourceCollection
    {
        return CustomerVerificationResource::collection(
            $this->list->handle($request->status(), $request->perPage())
        );
    }

    #[OA\Get(
        path: '/dashboard/customers/{customer}',
        operationId: 'dashboardShowCustomerVerification',
        summary: 'Customer verification details (audited)',
        description: 'Loads the customer plus every identity document ever attached to them (newest first). Requires customer.view. Access is audited (auth.customer.verification_details_viewed).',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Customers'],
        parameters: [new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Customer details', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/StaffCustomerVerification'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, string $customer): CustomerVerificationResource
    {
        /** @var RequestContext $ctx */
        $ctx = $request->attributes->get('context');

        return CustomerVerificationResource::make(
            $this->show->handle($request->user('staff'), $customer, $ctx)
        );
    }
}
