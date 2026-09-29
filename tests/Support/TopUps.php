<?php

namespace Tests\Support;

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 009 top-up tests: real customer tokens,
 * seeded staff roles, idempotency keys and money read-backs.
 */
final class TopUps
{
    public static function customerToken(Customer $customer): string
    {
        return app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;
    }

    /** Seed the roles (once per test) and act as a staff member of `$role`. */
    public static function actAsStaff(TestCase $test, SeedRole $role, bool $founder = false): Staff
    {
        if (! DB::table('roles')->exists()) {
            $test->seed(DashboardRolesAndPermissionsSeeder::class);
        }

        $factory = Staff::factory()->role($role);
        $staff = ($founder ? $factory->founder() : $factory)->create();

        app('auth')->forgetGuards();
        Sanctum::actingAs($staff, ['staff:access'], 'staff');

        return $staff;
    }

    /** @return array<string, string> */
    public static function key(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    public static function available(Customer $customer): string
    {
        return (string) (DB::table('ledger_posting')
            ->where('account_id', Account::forCustomerKind($customer->customer_id, AccountKind::CUST_AVAILABLE))
            ->sum('amount') ?: '0');
    }

    /** The bank's cash is −SUM(bank lines) (spec 008 R15). */
    public static function bankCash(): string
    {
        return bcmul((string) (DB::table('ledger_posting')->where('account_id', Account::internal(AccountKind::BANK))->sum('amount') ?: '0'), '-1', 4);
    }

    public static function globalSum(): string
    {
        return (string) (DB::table('ledger_posting')->sum('amount') ?: '0');
    }

    public static function receiptPng(): UploadedFile
    {
        return UploadedFile::fake()->image('receipt.png', 300, 600);
    }

    public static function receiptPdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    /** Upload a receipt as `$customer` and return the upload token. */
    public static function uploadReceipt(TestCase $test, Customer $customer, ?UploadedFile $file = null): TestResponse
    {
        app('auth')->forgetGuards();

        return $test->withToken(self::customerToken($customer))
            ->post('/api/v1/customer/me/uploads', ['purpose' => 'topup_receipt', 'file' => $file ?? self::receiptPng()], ['Accept' => 'application/json']);
    }
}
