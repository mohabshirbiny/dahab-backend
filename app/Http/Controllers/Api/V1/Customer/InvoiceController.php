<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Invoices\Customer\ListOwnInvoicesAction;
use App\Actions\Invoices\ViewTaxDocumentAction;
use App\Http\Controllers\Api\V1\Dashboard\InvoiceController as DashboardInvoiceController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Invoices\ListOwnInvoicesRequest;
use App\Http\Resources\Customer\CustomerInvoiceResource;
use App\Models\CreditNote;
use App\Models\TaxInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * The customer's own tax invoices and credit notes (spec 016 US3, FR-015 –
 * FR-017): list, one invoice with its credit notes, and the bilingual PDFs.
 * Verified customers; a suspended customer may read. Another customer's
 * invoice is invisible (row-level security) and answers 404. Not audited.
 */
class InvoiceController extends Controller
{
    private const ERR = '#/components/schemas/ApiError';

    #[OA\Get(
        path: '/customer/me/invoices',
        operationId: 'customerInvoices',
        summary: 'My tax invoices',
        description: 'Spec 016 FR-015. Newest first, keyset pages; role = seller (pieces you sold) or buyer (pieces you bought). Each row: number, order, amounts, status (issued | partly_credited | credited — never a Tax Authority status), document_ready, piece. Verified; a suspended customer may read.',
        security: [['customerBearer' => []]],
        tags: ['Customer Invoices'],
        parameters: [
            new OA\Parameter(name: 'role', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['seller', 'buyer'])),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerInvoice')),
                new OA\Property(property: 'meta', properties: [
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
                ], type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'unauthenticated', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 403, description: 'verification_required', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function index(ListOwnInvoicesRequest $request, ListOwnInvoicesAction $list): JsonResponse
    {
        $perPage = $request->perPage();
        $page = $list->handle($request->user('customer')->customer_id, $request->role(), $request->cursor(), $perPage);

        return response()->json([
            'data' => CustomerInvoiceResource::collection($page['rows'])->resolve($request),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page['next_cursor']],
        ]);
    }

    #[OA\Get(
        path: '/customer/me/invoices/{invoice}',
        operationId: 'customerInvoice',
        summary: 'One of my tax invoices',
        description: "Spec 016 FR-015. The invoice with its lines (piece, weight, rate, gold value, making charge or asking price; seller: Dahab's commission, VAT and what reached the wallet), Dahab's details (null until configured) and its credit notes. Another customer's invoice: 404.",
        security: [['customerBearer' => []]],
        tags: ['Customer Invoices'],
        parameters: [new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The invoice', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CustomerInvoice')])),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function show(Request $request, string $invoice): JsonResponse
    {
        $row = $this->own($request, $invoice)->load('creditNotes');

        return response()->json(['data' => (new CustomerInvoiceResource($row))->resolve($request)]);
    }

    #[OA\Get(
        path: '/customer/me/invoices/{invoice}/pdf',
        operationId: 'customerInvoicePdf',
        summary: 'Download one of my tax invoices',
        description: 'Spec 016 FR-023. The bilingual (English and Arabic) PDF. 409 document_not_ready while it is being prepared. Not audited.',
        security: [['customerBearer' => []]],
        tags: ['Customer Invoices'],
        parameters: [new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'document_not_ready', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function pdf(Request $request, string $invoice, ViewTaxDocumentAction $view): Response
    {
        return DashboardInvoiceController::pdfResponse($view->handle($this->own($request, $invoice)));
    }

    #[OA\Get(
        path: '/customer/me/credit-notes/{creditNote}/pdf',
        operationId: 'customerCreditNotePdf',
        summary: 'Download a credit note on one of my invoices',
        description: 'Spec 016 FR-023. The bilingual PDF. 409 document_not_ready while it is being prepared. Not audited.',
        security: [['customerBearer' => []]],
        tags: ['Customer Invoices'],
        parameters: [new OA\Parameter(name: 'creditNote', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'not_found', content: new OA\JsonContent(ref: self::ERR)),
            new OA\Response(response: 409, description: 'document_not_ready', content: new OA\JsonContent(ref: self::ERR)),
        ],
    )]
    public function creditNotePdf(Request $request, string $creditNote, ViewTaxDocumentAction $view): Response
    {
        $note = CreditNote::query()->whereKey($creditNote)
            ->where('customer_id', $request->user('customer')->customer_id)->firstOrFail();

        return DashboardInvoiceController::pdfResponse($view->handle($note));
    }

    private function own(Request $request, string $invoiceId): TaxInvoice
    {
        return TaxInvoice::query()->whereKey($invoiceId)
            ->where('customer_id', $request->user('customer')->customer_id)->firstOrFail();
    }
}
