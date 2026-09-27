<?php

namespace App\Actions\Reference;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * The weekly hours are always written as a whole week (spec 004 FR-002):
 * the Dashboard edits the week, never one row.
 */
trait ReplacesBranchWeek
{
    /**
     * @param  list<array{dow: int, opens_at: string, closes_at: string}>  $hours
     * @return list<array{dow: int, opens_at: string, closes_at: string}>
     */
    protected function replaceWeek(Branch $branch, array $hours): array
    {
        DB::table('branch_hours')->where('branch_id', $branch->branch_id)->delete();

        $week = self::normaliseWeek($hours);
        if ($week !== []) {
            DB::table('branch_hours')->insert(array_map(
                fn (array $h) => ['branch_id' => $branch->branch_id] + $h,
                $week,
            ));
        }

        return $week;
    }

    /** @return list<array{dow: int, opens_at: string, closes_at: string}> */
    protected static function currentWeek(Branch $branch): array
    {
        return self::normaliseWeek(
            DB::table('branch_hours')->where('branch_id', $branch->branch_id)->get(['dow', 'opens_at', 'closes_at'])
                ->map(fn ($h) => (array) $h)->all(),
        );
    }

    /**
     * @param  iterable<array{dow: int|string, opens_at: string, closes_at: string}>  $hours
     * @return list<array{dow: int, opens_at: string, closes_at: string}>
     */
    private static function normaliseWeek(iterable $hours): array
    {
        $week = [];
        foreach ($hours as $h) {
            $week[] = ['dow' => (int) $h['dow'], 'opens_at' => substr($h['opens_at'], 0, 5), 'closes_at' => substr($h['closes_at'], 0, 5)];
        }
        usort($week, fn ($a, $b) => [$a['dow'], $a['opens_at']] <=> [$b['dow'], $b['opens_at']]);

        return $week;
    }
}
