<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\FirstSale\ManageFirstSaleEnablementAction;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FirstSaleAdvance;
use App\Support\FirstSale\FirstSaleEligibility;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * The first-sale advance, staff side (spec 023 Story 1.5; permission `first_sale.manage`):
 * enable or revoke it for one seller, and the exposure and the advances behind it.
 */
class FirstSaleController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Post(
        path: '/dashboard/customers/{customer}/first-sale-advance',
        operationId: 'dashboardEnableFirstSale',
        summary: 'Enable the first-sale advance for a seller',
        description: 'Spec 023 R15. The seller\'s next eligible gold sale is paid ahead (the actor is this staff member). One open enablement per seller; refused for a seller who already had an advance. A reason of 10 to 1000 characters. Audited (first_sale.enabled). first_sale.manage. Idempotent.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000)])),
        tags: ['Dashboard Market makers'],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Enabled'),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'first_sale_not_eligible | first_sale_enablement_open', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function enable(Request $request, string $customer, ManageFirstSaleEnablementAction $manage): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $row = $manage->enable($request->user('staff'), $customer, $v['reason'], $request->attributes->get('context'));

        return response()->json(['data' => [
            'enablement_id' => $row->enablement_id,
            'seller' => $this->seller($customer),
            'created_at' => $row->created_at->toIso8601String(),
        ]], 201);
    }

    #[OA\Delete(
        path: '/dashboard/customers/{customer}/first-sale-advance',
        operationId: 'dashboardRevokeFirstSale',
        summary: 'Revoke the enablement before it is used',
        description: 'Spec 023 R15. Audited (first_sale.revoked). first_sale.manage. Idempotent.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Revoked'),
            new OA\Response(response: 404, description: 'not_found | first_sale_enablement_not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function revoke(Request $request, string $customer, ManageFirstSaleEnablementAction $manage): JsonResponse
    {
        $v = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $row = $manage->revoke($request->user('staff'), $customer, $v['reason'] ?? null, $request->attributes->get('context'));

        return response()->json(['data' => [
            'enablement_id' => $row->enablement_id,
            'revoked_at' => $row->revoked_at?->toIso8601String(),
        ]]);
    }

    #[OA\Get(
        path: '/dashboard/first-sale-advances',
        operationId: 'dashboardFirstSaleAdvances',
        summary: 'Advances and the exposure',
        description: 'Spec 023 R13. Newest first, keyset pages; `status` outstanding | recovered | written_off. meta carries the switch, the cap, the ceiling, the exact outstanding sum, the headroom and the written-off and shortfall totals. first_sale.manage.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Market makers'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['outstanding', 'recovered', 'written_off'])),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'The advances')],
    )]
    public function index(Request $request, Settings $settings, FirstSaleEligibility $eligibility): JsonResponse
    {
        $v = $request->validate([
            'status' => ['sometimes', 'in:outstanding,recovered,written_off'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $per = (int) ($v['per_page'] ?? 25);

        $query = FirstSaleAdvance::query()->orderByDesc('created_at')->orderByDesc('advance_id');
        if (isset($v['status'])) {
            $query->where('status', $v['status']);
        }
        if (isset($v['cursor'])) {
            [$at, $id] = array_pad(explode('|', (string) base64_decode($v['cursor'], true), 2), 2, '');
            $query->where(fn ($q) => $q->where('created_at', '<', $at)->orWhere(fn ($e) => $e->where('created_at', $at)->where('advance_id', '<', $id)));
        }

        $rows = $query->limit($per + 1)->get();
        $next = null;
        if ($rows->count() > $per) {
            $rows = $rows->take($per);
            $last = $rows->last();
            $next = base64_encode($last->getRawOriginal('created_at').'|'.$last->advance_id);
        }

        $sellers = Customer::query()->whereIn('customer_id', $rows->pluck('seller_id'))->get(['customer_id', 'display_ref', 'full_name'])->keyBy('customer_id');
        $orders = DB::table('order')->whereIn('order_id', $rows->pluck('order_id'))->pluck('order_ref', 'order_id');

        $data = $rows->map(fn (FirstSaleAdvance $a) => [
            'advance_id' => $a->advance_id,
            'order_ref' => $orders[$a->order_id] ?? null,
            'seller' => ['display_ref' => $sellers[$a->seller_id]->display_ref ?? null, 'full_name' => $sellers[$a->seller_id]->full_name ?? null],
            'amount_egp' => bcadd((string) $a->amount_egp, '0', 4),
            'basis' => $a->basis->value,
            'promo_code' => $a->promo_code,
            'status' => $a->status->value,
            'advanced_at' => $a->created_at->toIso8601String(),
            'recovered_egp' => $a->recovered_egp === null ? null : bcadd((string) $a->recovered_egp, '0', 4),
            'shortfall_absorbed_egp' => $a->shortfall_absorbed_egp === null ? null : bcadd((string) $a->shortfall_absorbed_egp, '0', 4),
            'written_off_reason' => $a->written_off_reason?->value,
        ])->values();

        $exposure = $eligibility->exposure();
        $ceiling = Money::fixed4($settings->numeric(SettingKey::PAYOUT_FIRST_SALE_EXPOSURE_CEILING_EGP));
        $headroom = Money::cmp($ceiling, $exposure) > 0 ? Money::fixed4(Money::sub($ceiling, $exposure)) : '0.0000';

        return response()->json(['data' => $data, 'meta' => [
            'enabled' => $settings->bool(SettingKey::PAYOUT_FIRST_SALE_ENABLED),
            'cap_egp' => Money::fixed4($settings->numeric(SettingKey::PAYOUT_FIRST_SALE_CAP_EGP)),
            'ceiling_egp' => $ceiling,
            'outstanding_egp' => $exposure,
            'outstanding_count' => FirstSaleAdvance::query()->where('status', 'outstanding')->count(),
            'headroom_egp' => $headroom,
            'written_off_total_egp' => Money::fixed4((string) (FirstSaleAdvance::query()->where('status', 'written_off')->sum('written_off_amount_egp') ?: '0')),
            'shortfall_total_egp' => Money::fixed4((string) (FirstSaleAdvance::query()->where('status', 'recovered')->sum('shortfall_absorbed_egp') ?: '0')),
            'next_cursor' => $next,
        ]]);
    }

    /** @return array{display_ref: string|null, full_name: string|null} */
    private function seller(string $customerId): array
    {
        $c = Customer::query()->find($customerId);

        return ['display_ref' => $c?->display_ref, 'full_name' => $c?->full_name];
    }
}
