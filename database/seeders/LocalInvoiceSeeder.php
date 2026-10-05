<?php

namespace Database\Seeders;

use App\Actions\Invoices\IssueCreditNoteAction;
use App\Enums\PartyRole;
use App\Enums\SeedRole;
use App\Models\CreditNote;
use App\Models\Staff;
use App\Models\TaxInvoice;
use App\Support\DatabaseActor;
use Illuminate\Database\Seeder;

/**
 * Tax invoices and a credit note to try by hand (spec 016, T050). The
 * invoices themselves come from LocalOrderSeeder's real balance payments
 * (each payment issues a seller `-S` and a buyer `-B` invoice); this adds one
 * partial credit note by Finance on the oldest seller invoice, through the
 * real Action. PDFs wait until config/dahab-invoices.php is filled, then
 * `php artisan invoices:render-pending` makes them.
 *
 * Refuses to run outside local/testing; does nothing when a credit note exists.
 */
class LocalInvoiceSeeder extends Seeder
{
    public function run(IssueCreditNoteAction $credit): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalInvoiceSeeder skipped: only runs in local/testing.');

            return;
        }

        $done = DatabaseActor::elevate('system', fn () => CreditNote::query()->exists());
        $invoice = DatabaseActor::elevate('system', fn () => TaxInvoice::query()->where('party_role', PartyRole::SELLER->value)->orderBy('issued_at')->first());
        $finance = Staff::query()->where('email', SeedRole::FINANCE->value.'@dahab.test')->first();

        if ($done) {
            $this->command?->info('LocalInvoiceSeeder: a credit note is already there.');

            return;
        }
        if ($invoice === null || $finance === null) {
            $this->command?->warn('LocalInvoiceSeeder skipped: run LocalStaffSeeder and LocalOrderSeeder first (no paid order).');

            return;
        }

        DatabaseActor::push('staff', staffId: $finance->staff_id);
        try {
            $credit->handle($finance, $invoice->invoice_id, '100', 'Part of the commission was overstated; corrected for the local demo.');
        } finally {
            DatabaseActor::pop();
        }
    }
}
