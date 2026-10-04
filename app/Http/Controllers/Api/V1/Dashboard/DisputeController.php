<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Disputes\Staff\ListDisputeAssigneesAction;
use App\Actions\Disputes\Staff\ListDisputesAction;
use App\Actions\Disputes\Staff\PassOnDisputeAction;
use App\Actions\Disputes\Staff\ResolveDisputeAction;
use App\Actions\Disputes\Staff\ShowDisputeAction;
use App\Actions\Disputes\Staff\ViewDisputePhotoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Dispute\ListDisputesRequest;
use App\Http\Requests\Dashboard\Dispute\PassOnDisputeRequest;
use App\Http\Requests\Dashboard\Dispute\ResolveDisputeRequest;
use App\Http\Resources\Staff\StaffDisputeResource;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * The Disputes page (spec 014 US2; Part 2 §10): the queue, one dispute with
 * its order, its photos, pass on to a named colleague, resolve with a reply.
 * Every route needs `dispute.handle`; a resolution that moves money or
 * suspends needs `order.refund`, `compensation.pay` or `customer.suspend` too.
 * Staff scope; every POST idempotent and audited.
 */
class DisputeController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/disputes',
        operationId: 'dashboardDisputes',
        summary: 'The Disputes queue',
        description: 'Spec 014 FR-008. Oldest first, keyset-paginated. state: unresolved (default: open + passed_on) | open | passed_on | resolved; assigned=me; q: a DSP- or DH- reference. meta.counts feeds the navigation badge. Requires dispute.handle.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Disputes'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['unresolved', 'open', 'passed_on', 'resolved'])),
            new OA\Parameter(name: 'assigned', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['me'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of disputes', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffDispute')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'counts', properties: [
                        new OA\Property(property: 'open', type: 'integer'),
                        new OA\Property(property: 'passed_on', type: 'integer'),
                    ], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListDisputesRequest $request, ListDisputesAction $list): JsonResponse
    {
        $page = $list->handle($request->states(), $request->assignedToMe() ? $this->staff($request)->staff_id : null,
            $request->validated('q'), $request->cursor(), $request->perPage());

        return response()->json([
            'data' => StaffDisputeResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $request->perPage(), 'next_cursor' => $page['next_cursor'], 'counts' => $page['counts']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/disputes/{dispute}',
        operationId: 'dashboardDispute',
        summary: 'One dispute, with its order',
        description: 'Spec 014 FR-008. The customer\'s words, the photo ids, the history with staff notes, compensation paid, the order (StaffOrder detail: figures, ledger, timeline) and what you may do (can, with compensation_caps — null when uncapped). Requires dispute.handle.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Disputes'],
        parameters: [new OA\Parameter(name: 'dispute', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The dispute', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffDispute')])),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $dispute, ShowDisputeAction $show): JsonResponse
    {
        return $this->respond($request, $dispute, $show);
    }

    #[OA\Get(
        path: '/dashboard/disputes/{dispute}/photos/{photo}',
        operationId: 'dashboardDisputePhoto',
        summary: 'Open one photo of a dispute',
        description: 'Spec 014 FR-008. The decrypted image, never cached; each view is audited (dispute.photo_viewed). Requires dispute.handle.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Disputes'],
        parameters: [
            new OA\Parameter(name: 'dispute', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'photo', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The image', content: new OA\MediaType(mediaType: 'image/*')),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function photo(Request $request, string $dispute, string $photo, ViewDisputePhotoAction $view): Response
    {
        $image = $view->handle($this->staff($request), $dispute, $photo, $request->attributes->get('context'));

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'Content-Disposition' => 'inline; filename="dispute-photo"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    #[OA\Get(
        path: '/dashboard/dispute-assignees',
        operationId: 'dashboardDisputeAssignees',
        summary: 'Who a dispute can be passed to',
        description: 'Spec 014 FR-009. Active staff holding dispute.handle, except you. Requires dispute.handle.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Disputes'],
        responses: [
            new OA\Response(response: 200, description: 'Colleagues', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                ], type: 'object')),
            ])),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function assignees(Request $request, ListDisputeAssigneesAction $list): JsonResponse
    {
        return response()->json(['data' => $list->handle($this->staff($request))->map(fn (Staff $s) => [
            'id' => $s->staff_id,
            'name' => $s->full_name,
            'roles' => $s->roles->map(fn ($r) => $r->display_name ?? $r->name)->values()->all(),
        ])->values()->all()]);
    }

    #[OA\Post(
        path: '/dashboard/disputes/{dispute}/pass-on',
        operationId: 'dashboardPassOnDispute',
        summary: 'Pass a dispute to a named colleague',
        description: 'Spec 014 FR-009. The dispute stays open (passed_on) and becomes theirs; your note is for staff only and nothing is sent to the customer. Audited (dispute.passed_on). Idempotent. Requires dispute.handle.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PassOnDisputeRequest')),
        tags: ['Dashboard Disputes'],
        parameters: [
            new OA\Parameter(name: 'dispute', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The dispute', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffDispute')])),
            new OA\Response(response: 403, description: 'permission_denied (audited)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'illegal_dispute_transition | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'assignee_not_eligible | validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function passOn(PassOnDisputeRequest $request, string $dispute, PassOnDisputeAction $pass, ShowDisputeAction $show): JsonResponse
    {
        $pass->handle($this->staff($request), $dispute, (string) $request->validated('assignee_id'),
            (string) $request->validated('note'), $request->attributes->get('context'));

        return $this->respond($request, $dispute, $show);
    }

    #[OA\Post(
        path: '/dashboard/disputes/{dispute}/resolve',
        operationId: 'dashboardResolveDispute',
        summary: 'Resolve a dispute with a reply',
        description: 'Spec 014 FR-010–FR-016. Every dispute ends with a reply to the customer who raised it. resume: the order goes back to the state it was frozen from and every running deadline gets back exactly the time it was frozen (extension rows naming the dispute). against_sale (before payment only; needs order.refund): cancelled at inspection, the deposit refunded in full, the piece returned to the seller; a paid order answers dispute_outcome_not_allowed. Optional compensation to either party (needs compensation.pay; per payment and per Cairo day caps unless compensation.uncapped) and, with against_sale, suspend_seller (needs customer.suspend). One transaction; a refusal changes nothing. Audited (dispute.resolved, compensation.paid). Idempotent. Requires dispute.handle.',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ResolveDisputeRequest')),
        tags: ['Dashboard Disputes'],
        parameters: [
            new OA\Parameter(name: 'dispute', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The dispute, resolved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffDispute')])),
            new OA\Response(response: 403, description: 'permission_denied (audited) | compensation_cap_exceeded (details: per_payment, left_today)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'dispute_outcome_not_allowed | illegal_dispute_transition | illegal_order_transition | insufficient_funds | idempotency_in_progress', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | idempotency_key_mismatch', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function resolve(ResolveDisputeRequest $request, string $dispute, ResolveDisputeAction $resolve, ShowDisputeAction $show): JsonResponse
    {
        $resolve->handle($this->staff($request), $dispute, $request->outcome(), (string) $request->validated('reply'),
            $request->compensation(), $request->suspendSeller(), $request->attributes->get('context'));

        return $this->respond($request, $dispute, $show);
    }

    private function staff(Request $request): Staff
    {
        return $request->user('staff');
    }

    private function respond(Request $request, string $disputeId, ShowDisputeAction $show): JsonResponse
    {
        return response()->json(['data' => (new StaffDisputeResource($show->handle($disputeId)))->detail()->resolve($request)]);
    }
}
