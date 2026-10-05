<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Invoices\ListCreditNotesAction;
use App\Actions\Invoices\ViewTaxDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Invoices\ListCreditNotesRequest;
use App\Http\Resources\Staff\CreditNoteResource;
use App\Models\CreditNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/** The Credit notes list and each note's PDF on the Invoices page (spec 016 FR-014, FR-024). invoice.view. */
class CreditNoteController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/credit-notes',
        operationId: 'dashboardCreditNotes',
        summary: 'Every credit note',
        description: 'Spec 016 FR-014. Newest first, keyset pages. Filters: from/to (Cairo dates, default the last 30 days, at most 366 days), q (credit note or invoice number, reason, customer reference or name). Each: the invoice it reverses, why, the amounts, who issued it. invoice.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Invoices'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffCreditNote')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListCreditNotesRequest $request, ListCreditNotesAction $list): JsonResponse
    {
        [$from, $to] = $request->period();
        $perPage = $request->perPage();
        $page = $list->handle($from, $to, $request->validated('q'), $request->cursor(), $perPage);

        return response()->json([
            'data' => CreditNoteResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/credit-notes/{creditNote}/pdf',
        operationId: 'dashboardCreditNotePdf',
        summary: "Open a credit note's PDF",
        description: 'Spec 016 FR-024. The bilingual PDF; audited as credit_note.document_viewed. 409 document_not_ready while it is being prepared. invoice.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Invoices'],
        parameters: [new OA\Parameter(name: 'creditNote', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'document_not_ready', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function pdf(Request $request, string $creditNote, ViewTaxDocumentAction $view): Response
    {
        return InvoiceController::pdfResponse($view->handle(CreditNote::query()->whereKey($creditNote)->firstOrFail(), $request->user('staff')));
    }
}
