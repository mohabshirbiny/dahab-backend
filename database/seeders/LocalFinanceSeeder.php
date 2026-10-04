<?php

namespace Database\Seeders;

use App\Actions\Finance\AdjustWalletAction;
use App\Actions\Finance\CloseDayAction;
use App\Actions\Finance\CreateStaffUploadAction;
use App\Actions\Finance\PayDirectCompensationAction;
use App\Actions\Finance\RecordBankMovementAction;
use App\Enums\AdjustmentDirection;
use App\Enums\BankMovementKind;
use App\Enums\CompensationReason;
use App\Enums\SeedRole;
use App\Enums\UploadPurpose;
use App\Models\BankMovement;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Finance\CloseFigures;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Finance operations to try by hand (spec 015, T061), made through the real
 * Actions — never written directly. Hoda (900006) and Karim (900007):
 *
 *  - a compensation outside a dispute (Finance, wasted trip) to Karim;
 *  - a CEO credit to Karim and a CEO debit to Hoda;
 *  - capital paid in (with a proof PDF), a bank charge and an own-account transfer;
 *  - the day before yesterday closed and locked; yesterday saved with a −250 difference.
 *
 * Refuses to run outside local/testing; does nothing when a bank movement exists.
 */
class LocalFinanceSeeder extends Seeder
{
    public function run(
        PayDirectCompensationAction $compensate,
        AdjustWalletAction $adjust,
        CreateStaffUploadAction $uploads,
        RecordBankMovementAction $record,
        CloseDayAction $close,
    ): void {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalFinanceSeeder skipped: only runs in local/testing.');

            return;
        }
        if (DatabaseActor::elevate('system', fn () => BankMovement::query()->exists())) {
            $this->command?->info('LocalFinanceSeeder: bank movements already there.');

            return;
        }

        $hoda = Customer::query()->where('display_ref', '900006')->first();
        $karim = Customer::query()->where('display_ref', '900007')->first();
        $staff = fn (SeedRole $role) => Staff::query()->where('email', $role->value.'@dahab.test')->first();
        [$ceo, $finance] = [$staff(SeedRole::CEO), $staff(SeedRole::FINANCE)];

        if ($hoda === null || $karim === null || $ceo === null || $finance === null) {
            $this->command?->warn('LocalFinanceSeeder skipped: run LocalStaffSeeder and LocalCustomerSeeder first.');

            return;
        }

        $today = CarbonImmutable::now('Africa/Cairo');

        $this->asStaff($finance, fn () => $compensate->handle($finance, $karim->customer_id, '800', CompensationReason::WASTED_TRIP,
            'Came to the Nasr City branch while it was closed.'));
        $this->asStaff($ceo, fn () => $adjust->handle($ceo, $karim->customer_id, AdjustmentDirection::CREDIT, '150',
            'A top-up matched 150 short; the bank shows the full amount.'));
        $this->asStaff($ceo, fn () => $adjust->handle($ceo, $hoda->customer_id, AdjustmentDirection::DEBIT, '50',
            'Reversing a goodwill credit entered twice.'));

        $proof = $this->asStaff($finance, fn () => $uploads->handle($finance, UploadPurpose::BANK_MOVEMENT_PROOF,
            UploadedFile::fake()->createWithContent('capital-advice.pdf', "%PDF-1.4\n% Dahab local seed\n%%EOF\n"))['token']);
        $this->asStaff($finance, fn () => $record->handle($finance, BankMovementKind::CAPITAL_IN, 'in', '500000',
            $today->subDays(2)->toDateString(), 'From the founders, second round.', $proof));
        $this->asStaff($finance, fn () => $record->handle($finance, BankMovementKind::BANK_CHARGE, 'out', '250',
            $today->subDay()->toDateString(), 'Monthly account fee.'));
        $this->asStaff($finance, fn () => $record->handle($finance, BankMovementKind::OWN_TRANSFER, 'out', '100000',
            $today->toDateString(), 'CIB to NBE, to pay suppliers from NBE.'));

        $twoAgo = $today->subDays(2)->toDateString();
        $yesterday = $today->subDay()->toDateString();
        $this->asStaff($finance, fn () => $close->handle($finance, $twoAgo, CloseFigures::at($twoAgo)['bank'], null));
        $this->asStaff($finance, fn () => $close->handle($finance, $yesterday, bcsub(CloseFigures::at($yesterday)['bank'], '250', 4), null));

        $this->command?->info('LocalFinanceSeeder: a compensation, two adjustments, three bank movements, one locked and one saved day.');
    }

    /** @template T @param Closure(): T $work @return T */
    private function asStaff(Staff $staff, Closure $work): mixed
    {
        DatabaseActor::push('staff', staffId: $staff->staff_id);
        try {
            return $work();
        } finally {
            DatabaseActor::pop();
        }
    }
}
