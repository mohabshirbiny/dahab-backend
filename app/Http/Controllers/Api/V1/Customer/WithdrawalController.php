<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Withdrawals\Customer\CancelWithdrawalAction;
use App\Actions\Withdrawals\Customer\ListOwnWithdrawalsAction;
use App\Actions\Withdrawals\Customer\RequestWithdrawalConfirmationAction;
use App\Actions\Withdrawals\Customer\SubmitWithdrawalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Withdrawal\ListWithdrawalsRequest;
use App\Http\Requests\Customer\Withdrawal\RequestConfirmationRequest;
use App\Http\Requests\Customer\Withdrawal\SubmitWithdrawalRequest;
use App\Http\Resources\Customer\WithdrawalConfirmationResource;
use App\Http\Resources\Customer\WithdrawalResource;
use App\Models\Customer;
use App\Models\Withdrawal;
use App\Models\WithdrawalConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Withdrawals, customer side (spec 013 US4, US5; Part 2 §8). Verified gate
 * throughout: a suspended customer may still withdraw a remaining balance and
 * cancel (Part 1 §2.2). Every POST needs an Idempotency-Key.
 */
class WithdrawalController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/withdrawals',
        operationId: 'customerWithdrawals',
        summary: 'The customer\'s own withdrawals',
        description: 'Spec 013. Newest first, keyset-paginated. state=open (requested, under review) or closed.',
        security: [['customerBearer' => []]],
        tags: ['Customer Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'closed'])),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerWithdrawal')),
                new OA\Property(property: 'meta', properties: [new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'next_cursor', type: 'string', nullable: true)], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListWithdrawalsRequest $request, ListOwnWithdrawalsAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($this->customer($request)->customer_id, $request->group(), $request->cursor(), $perPage);

        return response()->json([
            'data' => WithdrawalResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/customer/me/withdrawals/{withdrawal}',
        operationId: 'customerWithdrawal',
        summary: 'One of the customer\'s withdrawals',
        security: [['customerBearer' => []]],
        tags: ['Customer Withdrawals'],
        parameters: [new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The withdrawal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerWithdrawal')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $withdrawal): JsonResponse
    {
        $w = Withdrawal::query()->with('account')->where('customer_id', $this->customer($request)->customer_id)->findOrFail($withdrawal);

        return WithdrawalResource::make($w)->response();
    }

    #[OA\Post(
        path: '/customer/me/withdrawals/confirmations',
        operationId: 'customerRequestWithdrawalConfirmation',
        summary: 'Email me the link to confirm this withdrawal',
        description: 'Spec 013 FR-007 (Part 1 §2.4). Checks the gates first (pause, account in use, available balance), replaces any earlier open confirmation, and emails a single-use link valid 30 minutes, tied to the amount and the account. Idempotent. Throttled (customer.withdrawals).',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RequestWithdrawalConfirmationRequest')),
        tags: ['Customer Withdrawals'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Sent', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WithdrawalConfirmation')])),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'withdrawals_paused (details.pause_until) | payout_account_not_active | insufficient_funds (details.available, details.shortfall)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function requestConfirmation(RequestConfirmationRequest $request, RequestWithdrawalConfirmationAction $confirm): JsonResponse
    {
        $customer = $this->customer($request);
        $confirmation = $confirm->handle($customer, $request->amount(), (string) $request->validated('payout_account_id'));

        $resource = WithdrawalConfirmationResource::make($confirmation);
        $resource->email = $customer->email;

        return $resource->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/customer/me/withdrawals/confirmations/{confirmation}',
        operationId: 'customerWithdrawalConfirmation',
        summary: 'Has the email link been confirmed?',
        description: 'Spec 013 R5: the Withdraw screen polls this to move from Waiting to Confirmed.',
        security: [['customerBearer' => []]],
        tags: ['Customer Withdrawals'],
        parameters: [new OA\Parameter(name: 'confirmation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The confirmation', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WithdrawalConfirmation')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function showConfirmation(Request $request, string $confirmation): JsonResponse
    {
        $c = WithdrawalConfirmation::query()->with('account')
            ->where('customer_id', $this->customer($request)->customer_id)->findOrFail($confirmation);

        return WithdrawalConfirmationResource::make($c)->response();
    }

    #[OA\Post(
        path: '/customer/me/withdrawals',
        operationId: 'customerSubmitWithdrawal',
        summary: 'Withdraw',
        description: 'Spec 013 FR-009 (Part 2 §8 POST /me/withdrawals). Needs a confirmed, unused, unexpired email confirmation of this customer for the same amount and account. Holds the amount (available → held) in one balanced withdrawal ledger entry; no money leaves the bank until a person releases it. Idempotent. Throttled (customer.withdrawals).',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubmitWithdrawalRequest')),
        tags: ['Customer Withdrawals'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Requested', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerWithdrawal')])),
            new OA\Response(response: 403, description: 'email_confirmation_required | verification_required', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'withdrawals_paused | payout_account_not_active | insufficient_funds', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function store(SubmitWithdrawalRequest $request, SubmitWithdrawalAction $submit): JsonResponse
    {
        $withdrawal = $submit->handle(
            $this->customer($request),
            (string) $request->validated('confirmation_id'),
            (string) $request->validated('amount'),
            (string) $request->validated('payout_account_id'),
            $request->attributes->get('context'),
        );

        return WithdrawalResource::make($withdrawal)->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/customer/me/withdrawals/{withdrawal}/cancel',
        operationId: 'customerCancelWithdrawal',
        summary: 'Cancel a withdrawal before it is released',
        description: 'Spec 013 FR-010 (Part 2 §8). requested / under_review → cancelled; the money returns to available. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Withdrawals'],
        parameters: [
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerWithdrawal')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_withdrawal_transition', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function cancel(Request $request, string $withdrawal, CancelWithdrawalAction $cancel): JsonResponse
    {
        return WithdrawalResource::make($cancel->handle($this->customer($request), $withdrawal, $request->attributes->get('context')))->response();
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
