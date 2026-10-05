<?php

namespace App\Jobs;

use App\Support\Invoices\Documents\TaxDocumentWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Generate one tax invoice's or credit note's PDF after its transaction
 * committed (spec 016 FR-023a, research R10). Runs as the system actor
 * (DatabaseActorEvents). A document waiting for Dahab's details is left to
 * `invoices:render-pending`, which also retries anything a job gave up on.
 */
class RenderTaxDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

    public int $tries;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $kind,
        public readonly string $id,
    ) {
        $this->tries = (int) config('dahab-invoices.render_tries', 5);
    }

    public function handle(TaxDocumentWriter $writer): void
    {
        $this->kind === self::CREDIT_NOTE ? $writer->creditNote($this->id) : $writer->invoice($this->id);
    }
}
