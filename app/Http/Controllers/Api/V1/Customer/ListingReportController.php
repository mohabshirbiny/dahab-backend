<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\ListingReports\ListingReportsAction;
use App\Enums\ReportReason;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Report this listing (spec 017 US8, FR-052). Verified and not suspended (the
 * trade gate); another seller's piece on the market; one open report per piece;
 * 10 a day. The seller is never told who reported.
 */
class ListingReportController extends Controller
{
    #[OA\Post(
        path: '/customer/me/listing-reports',
        operationId: 'customerReportListing',
        summary: 'Report a piece on the market',
        security: [['customerBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['listing_id', 'reason'], properties: [
            new OA\Property(property: 'listing_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'reason', type: 'string', enum: ['photos_not_genuine', 'price_or_weight_wrong', 'description_mismatch', 'not_theirs_to_sell', 'off_platform_dealing', 'other']),
            new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
        ])),
        tags: ['Customer Account'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Reported', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'reference', type: 'string', example: 'RPT-2291'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            ], type: 'object')])),
            new OA\Response(response: 403, description: 'verification_required | account_suspended', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 409, description: 'report_already_open', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'validation_failed | listing_not_reportable', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 429, description: 'too_many_requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function store(Request $request, ListingReportsAction $reports): JsonResponse
    {
        $data = $request->validate([
            'listing_id' => ['required', 'uuid'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $report = $reports->report($request->user('customer'), $data['listing_id'], ReportReason::from($data['reason']),
            $data['note'] ?? null, $request->attributes->get('context'));

        return ApiResponse::ok(['reference' => $report->reference(), 'created_at' => $report->created_at->toIso8601String()], 201);
    }
}
