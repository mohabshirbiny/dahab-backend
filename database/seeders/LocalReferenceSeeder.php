<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchClosure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sample branches, weekly hours and national holidays for local development
 * (spec 004), as in the Dashboard design reference. Real branches are
 * operating data entered from the Dashboard, so this refuses to run outside
 * local/testing. Idempotent.
 */
class LocalReferenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalReferenceSeeder skipped: only runs in local/testing.');

            return;
        }

        $this->branch(
            ['name_en' => 'IGI Nasr City', 'name_ar' => 'IGI مدينة نصر', 'address_en' => 'Nasr City, Cairo', 'address_ar' => 'مدينة نصر، القاهرة'],
            [0, 1, 2, 3, 4],
            '10:00',
            '18:00',
        );
        $this->branch(
            ['name_en' => 'IGI Mohandessin', 'name_ar' => 'IGI المهندسين', 'address_en' => 'Mohandessin, Giza', 'address_ar' => 'المهندسين، الجيزة'],
            [6, 0, 1, 2, 3, 4],
            '11:00',
            '19:00',
        );

        foreach ([['10-06', 'Armed Forces Day', 'عيد القوات المسلحة'], ['01-07', 'Coptic Christmas', 'عيد الميلاد المجيد'], ['04-25', 'Sinai Liberation Day', 'عيد تحرير سيناء']] as [$md, $en, $ar]) {
            $date = Carbon::parse(now()->year.'-'.$md);
            if ($date->isPast()) {
                $date->addYear();
            }
            BranchClosure::query()->firstOrCreate(
                ['branch_id' => null, 'closure_date' => $date->toDateString()],
                ['reason_en' => $en, 'reason_ar' => $ar],
            );
        }
    }

    /** @param  list<int>  $days */
    private function branch(array $attributes, array $days, string $opens, string $closes): void
    {
        $branch = Branch::query()->firstOrCreate(['name_en' => $attributes['name_en']], $attributes + ['timezone' => 'Africa/Cairo', 'is_enabled' => true]);

        if (DB::table('branch_hours')->where('branch_id', $branch->branch_id)->exists()) {
            return;
        }

        foreach ($days as $dow) {
            DB::table('branch_hours')->insert(['branch_id' => $branch->branch_id, 'dow' => $dow, 'opens_at' => $opens, 'closes_at' => $closes]);
        }
    }
}
