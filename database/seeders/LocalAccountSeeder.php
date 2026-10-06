<?php

namespace Database\Seeders;

use App\Actions\ListingReports\ListingReportsAction;
use App\Actions\Saved\SavedPiecesAction;
use App\Enums\ReportReason;
use App\Enums\ReportState;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Support\DatabaseActor;
use Illuminate\Database\Seeder;

/**
 * Saved pieces and an open listing report to try by hand (spec 017, T044),
 * through the real Actions: the first active customer saves two pieces of
 * other sellers and reports one. The inbox fills itself from the notices the
 * other seeders send (run a queue worker). Only local/testing; does nothing
 * when a report exists.
 */
class LocalAccountSeeder extends Seeder
{
    public function run(SavedPiecesAction $saved, ListingReportsAction $reports): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalAccountSeeder skipped: only runs in local/testing.');

            return;
        }
        if (DatabaseActor::elevate('system', fn () => ListingReport::query()->where('state', ReportState::OPEN->value)->exists())) {
            $this->command?->info('LocalAccountSeeder: an open report is already there.');

            return;
        }

        $pieces = DatabaseActor::elevate('system', fn () => Listing::query()->publiclyVisible()->orderBy('listed_at')->limit(2)->get());
        $others = fn (array $statuses) => DatabaseActor::elevate('system', fn () => Customer::query()->whereIn('status', $statuses)
            ->whereNotIn('customer_id', $pieces->pluck('seller_id')->all())->orderBy('display_ref')->first());
        // Anyone signed in may save; only a verified customer may report.
        $saver = $others(['active', 'pending_verification']);
        $reporter = $others(['active']);
        if ($pieces->isEmpty() || $saver === null) {
            $this->command?->warn('LocalAccountSeeder: no piece on the market, or no other customer.');

            return;
        }

        $as = function (Customer $c, \Closure $work) {
            DatabaseActor::push('customer', customerId: $c->customer_id);
            try {
                return $work();
            } finally {
                DatabaseActor::pop();
            }
        };
        $as($saver, fn () => $pieces->each(fn (Listing $piece) => $saved->save($saver, $piece->listing_id)));
        $report = $reporter === null ? null : $as($reporter, fn () => $reports->report(
            $reporter, $pieces->first()->listing_id, ReportReason::PRICE_OR_WEIGHT_WRONG, 'The weight looks too low for the photos.'));

        $this->command?->info("LocalAccountSeeder: {$pieces->count()} saved piece(s)".($report === null ? '; no verified reporter.' : " and report {$report->reference()}."));
    }
}
