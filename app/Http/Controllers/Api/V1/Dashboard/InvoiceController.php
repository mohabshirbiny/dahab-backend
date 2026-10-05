<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Invoices\ExportInvoicesAction;
use App\Actions\Invoices\IssueCreditNoteAction;
use App\Actions\Invoices\ListInvoicesAction;
use App\Actions\Invoices\ViewTaxDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Invoices\IssueCreditNoteRequest;
use App\Http\Requests\Dashboard\Invoices\ListInvoicesRequest;
use App\Http\Resources\Staff\CreditNoteResource;
use App\Http\Resources\Staff\InvoiceResource;
use App\Models\Staff;
use App\Models\TaxInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * The Invoices page (spec 016 US2, US4; Part 1 §4.2): every tax invoice with
 * the month's and the period's figures, the CSV export, one invoice with its
 * credit notes, the PDF (audited) — all `invoice.view` — and issuing a credit
 * note on a seller invoice (`invoice.correct`, idempotent, audited). Invoices
 * are issued automatically at pay-balance; nothing is filed with the Tax
 * Authority, and no Tax Authority status exists.
 */
#[OA\Schema(
    schema: 'InvoiceFigures',
    required: ['issued_count', 'net_invoiced', 'vat_collected', 'credit_notes_count', 'credit_notes_amount'],
    properties: [
        new OA\Property(property: 'issued_count', type: 'integer', description: 'Invoices issued, both parties'),
        new OA\Property(property: 'net_invoiced', type: 'string', description: "Seller invoices: Dahab's commission"),
        new OA\Property(property: 'vat_collected', type: 'string', description: 'Seller-invoice VAT less credit-note VAT'),
        new OA\Property(property: 'credit_notes_count', type: 'integer'),
        new OA\Property(property: 'credit_notes_amount', type: 'string'),
    ],
)]
class InvoiceController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/dashboard/invoices',
        operationId: 'dashboardInvoices',
        summary: 'Every tax invoice',
        description: 'Spec 016 FR-010/FR-011. Newest first, keyset pages. Filters: from/to (Cairo dates, default the last 30 days, at most 366 days), party, status (derived from credit notes), q (invoice number — which starts with the order reference — or the customer\'s reference or name). meta.figures.month and meta.figures.period. invoice.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Invoices'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'party', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['seller', 'buyer'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['issued', 'partly_credited', 'credited'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffInvoice')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                    new OA\Property(property: 'figures', properties: [
                        new OA\Property(property: 'month', ref: '#/components/schemas/InvoiceFigures'),
                        new OA\Property(property: 'period', ref: '#/components/schemas/InvoiceFigures'),
                    ], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListInvoicesRequest $request, ListInvoicesAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($request->listQuery(), $request->cursor(), $perPage);

        return response()->json([
            'data' => InvoiceResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor'], 'figures' => $page['figures']],
        ]);
    }

    #[OA\Get(
        path: '/dashboard/invoices/export',
        operationId: 'dashboardInvoicesExport',
        summary: 'The filtered invoices list as CSV',
        description: 'Spec 016 FR-013. Same filters as the list; UTF-8 with BOM; capped (X-Export-Truncated); audited as invoices.exported. invoice.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Invoices'],
        responses: [
            new OA\Response(response: 200, description: 'CSV', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function export(ListInvoicesRequest $request, ExportInvoicesAction $export): Response
    {
        return CompensationController::csv($export->handle($this->staff($request), $request->listQuery()), 'invoices');
    }

    #[OA\Get(
        path: '/dashboard/invoices/{invoice}',
        operationId: 'dashboardInvoice',
        summary: 'One tax invoice',
        description: 'Spec 016 FR-012. The invoice with its lines, Dahab\'s details (null until configured), the party\'s copied details, whether it can still be credited, and its credit notes. invoice.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Invoices'],
        parameters: [new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The invoice', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffInvoice')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $invoice): JsonResponse
    {
        $row = TaxInvoice::query()->with(['customer', 'creditNotes.issuedBy'])->whereKey($invoice)->firstOrFail();

        return response()->json(['data' => (new InvoiceResource($row))->resolve($request)]);
    }

    #[OA\Get(
        path: '/dashboard/invoices/{invoice}/pdf',
        operationId: 'dashboardInvoicePdf',
        summary: 'Open a tax invoice\'s PDF',
        description: 'Spec 016 FR-012/FR-024. The bilingual PDF; audited as invoice.document_viewed. 409 document_not_ready while it is being prepared (not audited). invoice.view.',
        security: [['dashboardBearer' => []]],
        tags: ['Dashboard Invoices'],
        parameters: [new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'document_not_ready', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function pdf(Request $request, string $invoice, ViewTaxDocumentAction $view): Response
    {
        return self::pdfResponse($view->handle(TaxInvoice::query()->whereKey($invoice)->firstOrFail(), $this->staff($request)));
    }

    #[OA\Post(
        path: '/dashboard/invoices/{invoice}/credit-notes',
        operationId: 'dashboardIssueCreditNote',
        summary: 'Issue a credit note (correct a tax invoice)',
        description: 'Spec 016 FR-018–FR-020; Part 1 §4.2 "Issue or correct a tax invoice". A seller invoice only, in part or in full, never above what is left. One balanced credit_note entry: dahab_commission −net, vat_payable −vat, the seller\'s available +gross (VAT = gross × rate / (100 + rate), half-up to 4 dp). Numbered CN-YYYY-NNNNNN. The invoice never changes. The seller is told by SMS + email; the PDF is made after commit. invoice.correct. Idempotent, audited (credit_note.issued, with the reason).',
        security: [['dashboardBearer' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IssueCreditNoteRequest')),
        tags: ['Dashboard Invoices'],
        parameters: [
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Issued', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StaffCreditNote')])),
            new OA\Response(response: 403, description: 'permission_denied', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'invoice_not_creditable (a buyer invoice)', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 422, description: 'validation_failed | credit_exceeds_invoice (details.remaining)', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function storeCreditNote(IssueCreditNoteRequest $request, string $invoice, IssueCreditNoteAction $issue): JsonResponse
    {
        $note = $issue->handle($this->staff($request), $invoice, (string) $request->validated('amount'),
            (string) $request->validated('reason'), $request->attributes->get('context'));

        return response()->json(['data' => (new CreditNoteResource($note->load(['invoice', 'customer', 'issuedBy'])))->resolve($request)], 201);
    }

    /** @param  array{bytes: string, filename: string}  $doc */
    public static function pdfResponse(array $doc): Response
    {
        return response($doc['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$doc['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }

    private function staff(Request $request): Staff
    {
        return $request->user('staff');
    }
}
