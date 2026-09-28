<?php

use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Database\Seeders\LocalCustomerSeeder;
use Database\Seeders\LocalLedgerSeeder;
use Database\Seeders\LocalStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// The local demo money (spec 008): posted once, balanced, through the money service.
it('posts the demo entries once and keeps the ledger at zero', function () {
    $this->seed([DashboardRolesAndPermissionsSeeder::class, LocalStaffSeeder::class, LocalCustomerSeeder::class]);

    $this->seed(LocalLedgerSeeder::class);
    $this->seed(LocalLedgerSeeder::class);

    $wallet = fn (string $ref) => DB::selectOne('SELECT available::numeric(18,4)::text AS a, held::numeric(18,4)::text AS h FROM customer_wallet w JOIN customer c USING (customer_id) WHERE c.display_ref = ?', [$ref]);
    $solvency = DB::selectOne('SELECT bank_balance::text AS bank, headroom::text AS headroom FROM solvency_check');

    expect(DB::table('ledger_transaction')->count())->toBe(9)
        ->and((array) $wallet('900006'))->toBe(['a' => '74684.7500', 'h' => '0.0000'])
        ->and((array) $wallet('900007'))->toBe(['a' => '24368.7500', 'h' => '0.0000'])
        ->and((array) $wallet('900009'))->toBe(['a' => '8800.0000', 'h' => '0.0000'])
        ->and([$solvency->bank, $solvency->headroom])->toBe(['358000.0000', '250146.5000'])
        ->and((string) DB::selectOne('SELECT must_be_zero::numeric(18,4)::text AS z FROM ledger_global_zero')->z)->toBe('0.0000');
});
