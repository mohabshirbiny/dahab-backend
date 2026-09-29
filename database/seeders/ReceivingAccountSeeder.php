<?php

namespace Database\Seeders;

use App\Models\ReceivingAccount;
use App\Support\SystemActor;
use Illuminate\Database\Seeder;

/**
 * One receiving account per top-up method for local development (spec 009),
 * with obviously fake details. Real accounts are entered from the Dashboard
 * (Controls → Receiving accounts) and are never committed, so this refuses
 * to run outside local/testing. Idempotent.
 */
class ReceivingAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('ReceivingAccountSeeder skipped: only runs in local/testing.');

            return;
        }

        // migrate:fresh --seed caches the system actor of the database it dropped
        // (the console's own system elevation runs first), so look it up again.
        SystemActor::forget();

        $accounts = [
            ['method' => 'bank_transfer', 'label' => 'Test Bank', 'bank_name' => 'Test Bank', 'account_holder' => 'Dahab Test',
                'account_number' => '0000 0000 0000', 'customer_note' => 'Dahab takes nothing on top-ups. Your bank may charge for the transfer.', 'sort_order' => 0],
            ['method' => 'instapay', 'label' => 'InstaPay test', 'instapay_address' => 'dahab.test@instapay', 'daily_limit' => '70000.00',
                'provider_fee_percent' => '0.500', 'customer_note' => 'The fee is charged by InstaPay, not by Dahab. Dahab takes nothing on top-ups.', 'sort_order' => 0],
            ['method' => 'vodafone_cash', 'label' => 'Vodafone Cash test', 'wallet_number' => '01000000000', 'daily_limit' => '60000.00',
                'provider_fee_percent' => '1.000', 'customer_note' => 'The fee is charged by Vodafone, not by Dahab. Dahab takes nothing on top-ups.', 'sort_order' => 0],
        ];

        foreach ($accounts as $account) {
            ReceivingAccount::query()->firstOrCreate(
                ['method' => $account['method'], 'label' => $account['label']],
                $account + ['updated_by' => SystemActor::id()],
            );
        }
    }
}
