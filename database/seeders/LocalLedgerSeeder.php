<?php

namespace Database\Seeders;

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Models\Staff;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo money for local testing only (spec 008): no endpoint moves money
 * yet, so this posts a few realistic entries through the real money
 * service — every entry is balanced and checked like any other. It runs
 * once (it looks for its own memo marker) and never outside local/testing.
 *
 * Uses the LocalCustomerSeeder people: Hoda (900006) sells a gold ring to
 * Karim (900007), with the Part 3 §3.3 figures; Nadia (900009, suspended)
 * gets a top-up and a compensation. Dahab also pays capital into the bank.
 */
class LocalLedgerSeeder extends Seeder
{
    public const MARKER = 'Demo data (LocalLedgerSeeder)';

    public function run(PostLedgerEntryAction $post): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalLedgerSeeder skipped: only runs in local/testing.');

            return;
        }

        if (LedgerTransaction::query()->where('memo', 'like', self::MARKER.'%')->exists()) {
            $this->command?->info('LocalLedgerSeeder: demo entries already there.');

            return;
        }

        $hoda = Customer::query()->where('display_ref', '900006')->first();
        $karim = Customer::query()->where('display_ref', '900007')->first();
        $nadia = Customer::query()->where('display_ref', '900009')->first();
        $finance = Staff::query()->where('email', SeedRole::FINANCE->value.'@dahab.test')->first();
        $ceo = Staff::query()->where('email', SeedRole::CEO->value.'@dahab.test')->first();

        if (! $hoda || ! $karim || ! $nadia || ! $finance || ! $ceo) {
            $this->command?->warn('LocalLedgerSeeder skipped: run LocalStaffSeeder and LocalCustomerSeeder first.');

            return;
        }

        $bank = Account::internal(AccountKind::BANK);
        $equity = Account::internal(AccountKind::EXTERNAL_EQUITY);
        $escrow = Account::internal(AccountKind::ESCROW);
        $available = fn (Customer $c) => Account::forCustomerKind($c->customer_id, AccountKind::CUST_AVAILABLE);
        $held = fn (Customer $c) => Account::forCustomerKind($c->customer_id, AccountKind::CUST_HELD);
        $memo = fn (string $what) => self::MARKER.': '.$what;

        $entries = [
            // Capital into the bank: headroom above what customers are owed.
            new LedgerEntry(LedgerEventKind::EXTERNAL_BANK_MOVEMENT, [
                new LedgerLine($bank, '-250000'), new LedgerLine($equity, '250000'),
            ], actorStaffId: $ceo->staff_id, memo: $memo('capital paid in')),

            // Top-ups matched by Finance (money in = bank −X, research R15).
            new LedgerEntry(LedgerEventKind::TOPUP, [
                new LedgerLine($bank, '-80000'), new LedgerLine($available($karim), '80000'),
            ], actorStaffId: $finance->staff_id, memo: $memo('bank transfer matched by reference')),
            new LedgerEntry(LedgerEventKind::TOPUP, [
                new LedgerLine($bank, '-20000'), new LedgerLine($available($hoda), '20000'),
            ], actorStaffId: $finance->staff_id, memo: $memo('InstaPay transfer matched by phone')),
            new LedgerEntry(LedgerEventKind::TOPUP, [
                new LedgerLine($bank, '-8000'), new LedgerLine($available($nadia), '8000'),
            ], actorStaffId: $finance->staff_id, memo: $memo('bank transfer matched by reference')),

            // Karim asks to buy Hoda's 21K ring: 20% deposit held.
            new LedgerEntry(LedgerEventKind::DEPOSIT_HOLD, [
                new LedgerLine($available($karim), '-11126.25'), new LedgerLine($held($karim), '11126.25'),
            ], actorCustomerId: $karim->customer_id, memo: $memo('deposit on a gold ring, 21K')),

            // Karim also queued for another piece and was not chosen: deposit back.
            new LedgerEntry(LedgerEventKind::DEPOSIT_HOLD, [
                new LedgerLine($available($karim), '-6000'), new LedgerLine($held($karim), '6000'),
            ], actorCustomerId: $karim->customer_id, memo: $memo('deposit on a gold bracelet, 18K')),
            new LedgerEntry(LedgerEventKind::DEPOSIT_RELEASE, [
                new LedgerLine($held($karim), '-6000'), new LedgerLine($available($karim), '6000'),
            ], actorCustomerId: $karim->customer_id, memo: $memo('seller chose another buyer')),

            // Balance paid on the ring: settlement through escrow (Part 3 §3.3 figures).
            new LedgerEntry(LedgerEventKind::SETTLEMENT_SELLER, [
                new LedgerLine($available($karim), '-44505'),
                new LedgerLine($held($karim), '-11126.25'),
                new LedgerLine($escrow, '55631.25'),
                new LedgerLine($escrow, '-55631.25'),
                new LedgerLine($available($hoda), '54684.75'),
                new LedgerLine(Account::internal(AccountKind::DAHAB_COMMISSION), '600'),
                new LedgerLine(Account::internal(AccountKind::VAT_PAYABLE), '84'),
                new LedgerLine(Account::internal(AccountKind::DAHAB_SPREAD), '262.5'),
            ], actorCustomerId: $karim->customer_id, memo: $memo('gold ring, 21K, 10.000 g')),

            // A goodwill payment entered by hand (an amber row on the statement).
            new LedgerEntry(LedgerEventKind::COMPENSATION, [
                new LedgerLine($equity, '-800'), new LedgerLine($available($nadia), '800'),
            ], actorStaffId: $ceo->staff_id, memo: $memo('wasted trip to IGI')),
        ];

        DB::transaction(function () use ($post, $entries) {
            foreach ($entries as $entry) {
                $post->handle($entry);
            }
        });

        $this->command?->info('LocalLedgerSeeder: '.count($entries).' demo entries posted.');
    }
}
