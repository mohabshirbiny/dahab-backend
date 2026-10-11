<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Legal\AcceptLegalVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AcceptLegalVersionRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/** The customer accepts the live terms or privacy they owe (spec 019 FR-025, D2). */
class LegalAcceptanceController extends Controller
{
    #[OA\Post(
        path: '/customer/me/legal-acceptances',
        operationId: 'customerAcceptLegalVersion',
        summary: 'Accept the live terms or privacy',
        description: 'Spec 019 FR-025. `legal_doc_id` is the id the app was shown in `legal_acceptance_required`. Recorded with the document, version, time, address and device, in context `first_acceptance` or `material_reaccept`. Allowed in every account state. 422 `legal_document_not_current` when the id is not the live version of terms or privacy; 409 `legal_acceptance_not_required` when nothing is owed for it. Idempotency-Key required.',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AcceptLegalVersionRequest')),
        tags: ['Customer Legal'],
        responses: [
            new OA\Response(response: 201, description: 'Accepted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'version', type: 'integer'),
                    new OA\Property(property: 'accepted_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'legal_acceptance_required', type: 'array', items: new OA\Items(ref: '#/components/schemas/LegalAcceptanceRequired')),
                ], type: 'object'),
            ])),
            new OA\Response(response: 409, description: 'legal_acceptance_not_required', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | legal_document_not_current', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(AcceptLegalVersionRequest $request, AcceptLegalVersionAction $accept): JsonResponse
    {
        $result = $accept->handle($request->user('customer'), (int) $request->validated('legal_doc_id'), $request->attributes->get('context'));

        return response()->json(['data' => [
            'code' => $result['code'],
            'version' => $result['version'],
            'accepted_at' => $result['accepted_at'],
            'legal_acceptance_required' => $result['remaining'],
        ]], 201);
    }
}
