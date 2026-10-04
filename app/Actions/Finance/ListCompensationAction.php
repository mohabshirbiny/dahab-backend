<?php

namespace App\Actions\Finance;

use App\Models\Compensation;
use App\Models\Staff;
use App\Support\Disputes\CompensationCaps;
use App\Support\Finance\CompensationListQuery;
use App\Support\Listings\ListingCursor;
use App\Support\Pricing\Money;
use App\Support\Withdrawals\KeysetPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Compensation page (spec 015 FR-001, FR-002): every payment newest first,
 * keyset pages, the period's and this Cairo month's totals, and the caps as
 * they apply to the viewer (what is left today, or uncapped).
 */
final class ListCompensationAction
{
    public const RELATIONS = ['customer', 'payer', 'dispute', 'order'];

    public function __construct(private readonly CompensationCaps $caps) {}

    /** @return array{rows: Collection<int, Compensation>, next_cursor: string|null, totals: array{period: string, this_month: string}, caps: array<string, mixed>} */
    public function handle(Staff $viewer, CompensationListQuery $filters, ?ListingCursor $cursor, int $perPage): array
    {
        $page = KeysetPage::byTime($filters->apply(Compensation::query()->with(self::RELATIONS)), 'compensation', 'paid_at', 'compensation_id', true, $cursor, $perPage);

        $period = (string) $filters->apply(Compensation::query())->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4)::text AS s')->value('s');
        $month = (string) DB::table('compensation')->where('paid_at', '>=', CarbonImmutable::now('Africa/Cairo')->startOfMonth())
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4)::text AS s')->value('s');

        $uncapped = $this->caps->uncapped($viewer);

        return $page + [
            'totals' => ['period' => $period, 'this_month' => $month],
            'caps' => [
                'per_payment' => $this->caps->perPayment(),
                'per_day' => $this->caps->perDay(),
                'uncapped' => $uncapped,
                'left_today' => $uncapped ? null : Money::fixed4($this->caps->leftToday($viewer->staff_id)),
            ],
        ];
    }
}
