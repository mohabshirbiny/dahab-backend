<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\TaxInvoice;
use App\Support\DatabaseActor;
use App\Support\Invoices\Documents\TaxDocumentWriter;
use App\Support\Invoices\IssuerDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generate every tax invoice and credit note PDF still missing (spec 016
 * FR-023a, research R10): those issued while Dahab's details were incomplete
 * and those whose job gave up. Scheduled every five minutes in
 * routes/console.php; runs as the system actor, one document at a time, so
 * one failure never blocks the rest.
 */
class RenderPendingTaxDocuments extends Command
{
    protected $signature = 'invoices:render-pending';

    protected $description = 'Generate the tax invoice and credit note PDFs that are still missing';

    public function handle(TaxDocumentWriter $writer, IssuerDetails $issuer): int
    {
        if (! $issuer->complete()) {
            $this->components->warn("Dahab's details in config/dahab-invoices.php are incomplete: documents wait.");

            return self::SUCCESS;
        }

        return DatabaseActor::elevate('system', function () use ($writer) {
            $made = 0;
            $failed = 0;

            $pending = [
                'invoice' => TaxInvoice::query()->whereNull('storage_ref')->orderBy('issued_at')->pluck('invoice_id'),
                'credit_note' => CreditNote::query()->whereNull('storage_ref')->orderBy('issued_at')->pluck('credit_note_id'),
            ];
            foreach ($pending as $kind => $ids) {
                foreach ($ids as $id) {
                    try {
                        $made += ($kind === 'invoice' ? $writer->invoice($id) : $writer->creditNote($id)) ? 1 : 0;
                    } catch (Throwable $e) {
                        $failed++;
                        Log::error('invoices.render.failed', ['kind' => $kind, 'id' => $id, 'error' => $e->getMessage()]);
                    }
                }
            }

            $this->components->info("Tax documents: {$made} generated, {$failed} failed.");

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }
}
