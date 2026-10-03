<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Payouts\Customer\AddPayoutAccountAction;
use App\Actions\Payouts\Customer\KeepPayoutAccountAction;
use App\Actions\Payouts\Customer\ListOwnPayoutAccountsAction;
use App\Actions\Payouts\Customer\RemovePayoutAccountAction;
use App\Actions\Payouts\Customer\UsePayoutAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Payout\AddPayoutAccountRequest;
use App\Http\Resources\Customer\PayoutAccountResource;
use App\Models\Customer;
use App\Models\PayoutAccountChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Bank accounts, customer side (spec 013 US1, US3; Part 2 §8). Reads need a
 * verified customer (a suspended one may read); every change needs the trade
 * gate and an Idempotency-Key. Each change answers with the whole list.
 */
#[OA\Schema(
    schema: 'CustomerPayoutAccounts',
    required: ['accounts', 'pause', 'recent_changes'],
    properties: [
        new OA\Property(property: 'accounts', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerPayoutAccount'), description: 'Not removed; the one in use first'),
        new OA\Property(property: 'pause', nullable: true, properties: [new OA\Property(property: 'until', type: 'string', format: 'date-time')], type: 'object', description: 'New withdrawals are refused until then'),
        new OA\Property(property: 'recent_changes', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'kind', type: 'string', enum: ['added', 'verified', 'refused', 'in_use', 'removal_scheduled', 'kept', 'removed', 'request_cancelled']),
            new OA\Property(property: 'account', properties: [new OA\Property(property: 'bank_name', type: 'string'), new OA\Property(property: 'number_masked', type: 'string')], type: 'object'),
            new OA\Property(property: 'at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'by', type: 'string', enum: ['you', 'dahab']),
        ], type: 'object'), description: 'The last 20, newest first'),
    ],
)]
class PayoutAccountController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/payout-accounts',
        operationId: 'customerPayoutAccounts',
        summary: 'The customer\'s payout accounts, the pause and the recent changes',
        description: 'Spec 013 FR-002. Verified customers; a suspended customer may read.',
        security: [['customerBearer' => []]],
        tags: ['Customer Withdrawals'],
        responses: [
            new OA\Response(response: 200, description: 'Accounts', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerPayoutAccounts')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(Request $request, ListOwnPayoutAccountsAction $list): JsonResponse
    {
        return $this->respond($request, $list);
    }

    #[OA\Post(
        path: '/customer/me/payout-accounts',
        operationId: 'customerAddPayoutAccount',
        summary: 'Send a payout account for review',
        description: 'Spec 013 FR-001 (Part 2 §8 POST /me/payout-accounts). Stored pending_review with the acceptance of the payout-account declaration; the customer is told on their phone and email. Nothing is paused until an account becomes the one in use. Trade gate. Idempotent. Throttled (customer.payout_accounts).',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AddPayoutAccountRequest')),
        tags: ['Customer Withdrawals'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'The list, with the new account', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerPayoutAccounts')])),
            new OA\Response(response: 400, description: 'idempotency_key_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | declaration_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(AddPayoutAccountRequest $request, AddPayoutAccountAction $add, ListOwnPayoutAccountsAction $list): JsonResponse
    {
        $add->handle(
            $this->customer($request),
            (string) $request->validated('bank_name'),
            (string) $request->validated('account_name'),
            (string) $request->validated('account_number_or_iban'),
            (int) $request->validated('declaration_id'),
            $request->attributes->get('context'),
        );

        return $this->respond($request, $list, 201);
    }

    #[OA\Post(
        path: '/customer/me/payout-accounts/{account}/use',
        operationId: 'customerUsePayoutAccount',
        summary: 'Make a verified account the one withdrawals go to',
        description: 'Spec 013 FR-005. Unless it is the customer\'s first account ever in use, every withdrawal not yet released is cancelled with its money returned, and new withdrawals pause for withdrawal.account_change_pause_hours. meta.cancelled_withdrawals lists the cancelled numbers. Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The list; pause set when one opened', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/CustomerPayoutAccounts'),
                new OA\Property(property: 'meta', properties: [new OA\Property(property: 'cancelled_withdrawals', type: 'array', items: new OA\Items(type: 'string'))], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_payout_account_transition', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function use(Request $request, string $account, UsePayoutAccountAction $use, ListOwnPayoutAccountsAction $list): JsonResponse
    {
        $result = $use->handle($this->customer($request), $account, $request->attributes->get('context'));

        return $this->respond($request, $list, 200, ['cancelled_withdrawals' => $result['cancelled']]);
    }

    #[OA\Post(
        path: '/customer/me/payout-accounts/{account}/remove',
        operationId: 'customerRemovePayoutAccount',
        summary: 'Remove an account, or cancel a request under review',
        description: 'Spec 013 FR-004. pending_review → removed; active → removed, or removing while a withdrawal not yet released goes to it (it keeps its in-use flag, refuses new withdrawals, and is removed when that withdrawal ends). Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerPayoutAccounts')])),
            new OA\Response(response: 403, description: 'account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_payout_account_transition', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function remove(Request $request, string $account, RemovePayoutAccountAction $remove, ListOwnPayoutAccountsAction $list): JsonResponse
    {
        $remove->handle($this->customer($request), $account, $request->attributes->get('context'));

        return $this->respond($request, $list);
    }

    #[OA\Post(
        path: '/customer/me/payout-accounts/{account}/keep',
        operationId: 'customerKeepPayoutAccount',
        summary: 'Keep an account being removed',
        description: 'Spec 013 US3. removing → active, no pause. Trade gate. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerPayoutAccounts')])),
            new OA\Response(response: 403, description: 'account_suspended', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_payout_account_transition', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function keep(Request $request, string $account, KeepPayoutAccountAction $keep, ListOwnPayoutAccountsAction $list): JsonResponse
    {
        $keep->handle($this->customer($request), $account, $request->attributes->get('context'));

        return $this->respond($request, $list);
    }

    /** @param  array<string, mixed>  $meta */
    private function respond(Request $request, ListOwnPayoutAccountsAction $list, int $status = 200, array $meta = []): JsonResponse
    {
        $view = $list->handle($this->customer($request)->customer_id);
        $body = ['data' => [
            'accounts' => PayoutAccountResource::collection($view['accounts'])->resolve($request),
            'pause' => $view['pause'] === null ? null : ['until' => $view['pause']->pause_until->toIso8601String()],
            'recent_changes' => $view['changes']->map(fn (PayoutAccountChange $c) => [
                'kind' => $c->kind->value,
                'account' => ['bank_name' => $c->account?->bank_name, 'number_masked' => $c->account?->masked()],
                'at' => $c->created_at->toIso8601String(),
                'by' => $c->actor_customer_id !== null ? 'you' : 'dahab',
            ])->values()->all(),
        ]];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
