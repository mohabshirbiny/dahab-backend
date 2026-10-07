<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\ListingReports\ListingReportsAction;
use App\Enums\ReportReason;
use App\Enums\ReportState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Staff\ListingReportResource;
use App\Models\Staff;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The listing-reports half of Disputes and reports (spec 017 US8, FR-053).
 * listing_report.handle; taking a piece down also needs listing.takedown.
 * Every action is audited and tells each reporter, generically.
 */
class ListingReportController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/listing-reports',
        operationId: 'dashboardListingReports',
        summary: 'Listing reports, open first',
        description: 'Spec 017 FR-053. The newest 100 matching rows; meta.counts by state.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Disputes'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'dismissed', 'actioned', 'listing_gone'])),
            new OA\Parameter(name: 'reason', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['photos_not_genuine', 'price_or_weight_wrong', 'description_mismatch', 'not_theirs_to_sell', 'off_platform_dealing', 'other'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reports', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffListingReport')),
                new OA\Property(property: 'meta', properties: [new OA\Property(property: 'counts', properties: [
                    new OA\Property(property: 'open', type: 'integer'), new OA\Property(property: 'dismissed', type: 'integer'),
                    new OA\Property(property: 'actioned', type: 'integer'), new OA\Property(property: 'listing_gone', type: 'integer'),
                ], type: 'object')], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(Request $request, ListingReportsAction $reports): JsonResponse
    {
        $data = $request->validate([
            'state' => ['sometimes', Rule::enum(ReportState::class)],
            'reason' => ['sometimes', Rule::enum(ReportReason::class)],
        ]);
        $page = $reports->list(isset($data['state']) ? ReportState::from($data['state']) : null,
            isset($data['reason']) ? ReportReason::from($data['reason']) : null);

        return response()->json([
            'data' => ListingReportResource::collection($page['items']->load('listing.seller:customer_id,display_ref'))->resolve($request),
            'meta' => ['counts' => $page['counts']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/listing-reports/{report}',
        operationId: 'dashboardListingReport',
        summary: 'One listing report',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Disputes'],
        parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffListingReport')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $report, ListingReportsAction $reports): JsonResponse
    {
        return ApiResponse::ok((new ListingReportResource($reports->show($report)->load('listing.seller:customer_id,display_ref')))->resolve($request));
    }

    #[OA\Post(
        path: '/dashboard/listing-reports/{report}/dismiss',
        operationId: 'dashboardDismissListingReport',
        summary: 'Dismiss a report with a note',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['note'], properties: [new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 1000)])),
        tags: ['Dashboard Disputes'],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dismissed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffListingReport')])),
            new OA\Response(response: 409, description: 'report_not_open', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function dismiss(Request $request, string $report, ListingReportsAction $reports): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
        $reports->dismiss($this->staff($request), $report, $data['note'], $request->attributes->get('context'));

        return $this->show($request, $report, $reports);
    }

    #[OA\Post(
        path: '/dashboard/listing-reports/{report}/take-down',
        operationId: 'dashboardTakeDownReportedListing',
        summary: 'Take the reported piece down',
        description: 'Spec 017 FR-053. Also needs listing.takedown. Runs the spec 010 take-down (reason to the seller, a reserved piece releases its line) and actions every open report on the piece.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000, description: 'To the seller, as the spec 010 take-down')])),
        tags: ['Dashboard Disputes'],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Taken down', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffListingReport')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'report_not_open | illegal_listing_transition', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function takeDown(Request $request, string $report, ListingReportsAction $reports): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $reports->takeDown($this->staff($request), $report, $data['reason'], $request->attributes->get('context'));

        return $this->show($request, $report, $reports);
    }

    private function staff(Request $request): Staff
    {
        return $request->user('staff');
    }
}
