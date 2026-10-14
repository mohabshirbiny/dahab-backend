<?php

namespace App\Actions\Wallet;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Customer;
use App\Support\Invoices\IssuerDetails;
use App\Support\RequestContext;
use App\Support\Wallet\StatementPdfRenderer;

/**
 * The customer's own wallet statement as a PDF (wallet statement PDF, item 10): the figures,
 * the bilingual document, and one audit row per download (who, which period, how many rows).
 * Dahab's legal details head the page when they are configured (config/dahab-invoices.php);
 * otherwise the plain name.
 */
final class DownloadCustomerStatementAction
{
    public function __construct(
        private readonly BuildCustomerStatementAction $build,
        private readonly StatementPdfRenderer $renderer,
        private readonly IssuerDetails $issuer,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{pdf: string, filename: string} */
    public function handle(Customer $customer, string $from, string $to, ?RequestContext $ctx): array
    {
        $statement = $this->build->handle($customer, $from, $to);
        $pdf = $this->renderer->render($statement, $this->issuer->snapshot());

        $this->audit->execute(
            AuditEvent::WALLET_STATEMENT_DOWNLOADED,
            'success',
            ['from' => $from, 'to' => $to, 'rows' => count($statement['rows']), 'truncated' => $statement['truncated']],
            'customer',
            $customer->customer_id,
            $ctx,
            actorCustomerId: $customer->customer_id,
        );

        return ['pdf' => $pdf, 'filename' => "dahab-statement-{$from}-to-{$to}.pdf"];
    }
}
