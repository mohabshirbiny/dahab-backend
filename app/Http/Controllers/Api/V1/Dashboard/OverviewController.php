<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Dashboard\NavCountsAction;
use App\Actions\Overview\ShowOverviewAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** The Dashboard Overview's figures (spec 015 US5) and the navigation badges: each only for the codes that may see it. */
class OverviewController extends Controller
{
    #[OA\Get(
        path: '/dashboard/overview',
        operationId: 'dashboardOverview',
        summary: 'The Overview\'s figures',
        description: 'Spec 015 FR-016/FR-017. Any active staff member; a section is absent when the viewer lacks its code. earnings (wallet.view): this Cairo month\'s commission and spread. orders and this_month (order.view): open orders by state whatever their age, completed and cancelled (together) in the last 30 days — count, value (locked total) and held now (the buyers\' deposits on the ledger); new sellers, pieces listed, sold, sell-through, average days from acceptance to the seller\'s payment. needs_decision: oldest first (at most 10), each kind only with its acting code (listing.review, withdrawal.release, dispute.handle, identity.review, topup.match, payout_account.verify, order.extend_deadline). The safety figure and wallets: /dashboard/wallets/overview; the gold price: /dashboard/gold-prices/current.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Overview'],
        responses: [
            new OA\Response(response: 200, description: 'The figures', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'earnings', type: 'object', description: '{month, commission, spread, total}'),
                new OA\Property(property: 'orders', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'state', type: 'string', description: 'An open order state, completed, or cancelled'),
                    new OA\Property(property: 'count', type: 'integer'),
                    new OA\Property(property: 'value', type: 'string'),
                    new OA\Property(property: 'held_now', type: 'string'),
                ], type: 'object')),
                new OA\Property(property: 'needs_decision', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'kind', type: 'string', enum: ['listing_review', 'withdrawal', 'dispute', 'identity_document', 'topup_notice', 'payout_account', 'extension_request']),
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'subject', type: 'string', nullable: true, description: 'A display reference, a dispute/order reference or a top-up reference'),
                    new OA\Property(property: 'amount', type: 'string', nullable: true),
                    new OA\Property(property: 'waiting_since', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'waiting_of_kind', type: 'integer', description: 'How many of this kind are waiting'),
                    new OA\Property(property: 'acting_permission', type: 'string'),
                ], type: 'object')),
                new OA\Property(property: 'this_month', type: 'object', description: '{month, new_sellers, pieces_listed, sold, sell_through_pct, avg_days_to_pay_sellers}'),
            ], type: 'object')])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function show(Request $request, ShowOverviewAction $overview): JsonResponse
    {
        return response()->json(['data' => (object) $overview->handle($request->user('staff'))]);
    }

    #[OA\Get(
        path: '/dashboard/nav-counts',
        operationId: 'dashboardNavCounts',
        summary: 'The navigation badges in one read',
        description: 'Any active staff member; a count is absent (not 0) when the viewer lacks its code. Each count uses the same filter as the list it badges. customers_pending and customers_by_status (customer.view): customers by status, every status present; topups_open (topup.match): pending or on hold, whatever the date; listings_in_review (listing.review, listing.request_changes or listing.takedown); withdrawals_waiting (withdrawal.release): requested or under review, as figures.waiting_count; disputes_unresolved (dispute.handle): open or passed on; listing_reports_open (listing_report.handle). Not cached.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Overview'],
        responses: [
            new OA\Response(response: 200, description: 'The counts', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'customers_pending', type: 'integer', example: 12),
                    new OA\Property(property: 'customers_by_status', type: 'object', example: ['pending_verification' => 12, 'active' => 340, 'rejected' => 3, 'suspended' => 1, 'closed' => 0], additionalProperties: new OA\AdditionalProperties(type: 'integer')),
                    new OA\Property(property: 'topups_open', type: 'integer', example: 3),
                    new OA\Property(property: 'listings_in_review', type: 'integer', example: 5),
                    new OA\Property(property: 'withdrawals_waiting', type: 'integer', example: 2),
                    new OA\Property(property: 'disputes_unresolved', type: 'integer', example: 1),
                    new OA\Property(property: 'listing_reports_open', type: 'integer', example: 0),
                ], type: 'object'),
                new OA\Property(property: 'meta', properties: [new OA\Property(property: 'generated_at', type: 'string', format: 'date-time')], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function navCounts(Request $request, NavCountsAction $counts): JsonResponse
    {
        return response()->json([
            'data' => (object) $counts->handle($request->user('staff')),
            'meta' => ['generated_at' => now()->toIso8601String()],
        ]);
    }
}
