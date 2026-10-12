<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Referral\ReferralScreenAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Invite a friend and commission credits (spec 023 Story 4; contract referral-api). Any signed-in
 * customer. Behind `referral.enabled` (default off): while it is off the screen answers
 * `enabled: false` and nothing is created.
 */
class ReferralController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/referral',
        operationId: 'customerReferral',
        summary: 'The Invite a friend screen',
        description: 'Spec 023 Story 4. The invitation code (created on the first read while the programme is on), invites sent (Share/Copy taps), friends joined, friends who sold, what was earned, used and is available as commission credit, and the live terms. `enabled: false` with a null code while `referral.enabled` is off.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        responses: [new OA\Response(response: 200, description: 'The screen')],
    )]
    public function show(Request $request, ReferralScreenAction $screen): JsonResponse
    {
        return response()->json(['data' => $screen->show($request->user('customer'))]);
    }

    #[OA\Post(
        path: '/customer/me/referral/shares',
        operationId: 'customerReferralShare',
        summary: 'Count one Share or Copy tap',
        description: 'Spec 023 P23-5. Counted up to referral.max_invitations_per_account. 409 referral_not_open while the programme is off. Idempotent.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The counter'),
            new OA\Response(response: 409, description: 'referral_not_open', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function share(Request $request, ReferralScreenAction $screen): JsonResponse
    {
        return response()->json(['data' => ['invites_sent' => $screen->share($request->user('customer'))]]);
    }

    #[OA\Get(
        path: '/customer/me/commission-credits',
        operationId: 'customerCommissionCredits',
        summary: 'The customer\'s commission credits',
        description: 'Spec 023 R2. Active first, newest first, at most 100. A credit is not money: never in the wallet, never withdrawable; it only lowers the commission of a later sale.',
        security: [['customerBearer' => []]],
        tags: ['Customer Listings'],
        responses: [new OA\Response(response: 200, description: 'The credits')],
    )]
    public function credits(Request $request, ReferralScreenAction $screen): JsonResponse
    {
        return response()->json(['data' => $screen->credits($request->user('customer'))]);
    }
}
