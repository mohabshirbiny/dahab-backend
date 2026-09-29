<?php

namespace Database\Factories;

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Models\Account;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Transfer notices and hand credits (spec 009). A credited top-up posts its
 * real ledger entry through the money service (bank −X, customer available
 * +X), so balances always agree with the records.
 *
 * @extends Factory<TopUp>
 */
class TopUpFactory extends Factory
{
    protected $model = TopUp::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory()->verified(),
            'origin' => 'notice',
            'method' => 'instapay',
            'reference' => fn (array $a) => 'DAHAB-'.Customer::query()->whereKey($a['customer_id'])->value('display_ref'),
            'claimed_amount' => '20000.00',
            'notice_account_id' => fn (array $a) => ReceivingAccount::factory()->state(self::accountFor($a['method']))->create()->receiving_account_id,
            'status' => 'pending',
        ];
    }

    /**
     * A notice snapshots its account's fee, as SubmitTopUpNoticeAction does
     * (unless a state sets it). `topup_no` comes from the database identity;
     * load it so tests can quote TOP-{n}.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (TopUp $topUp) {
            if (! array_key_exists('notice_fee_percent', $topUp->getAttributes()) && $topUp->notice_account_id !== null) {
                $topUp->notice_fee_percent = ReceivingAccount::query()->whereKey($topUp->notice_account_id)->value('provider_fee_percent');
            }
        })->afterCreating(fn (TopUp $topUp) => $topUp->refresh());
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function onHold(?string $staffId = null): static
    {
        return $this->state(fn () => [
            'status' => 'on_hold',
            'hold_note' => 'Checking the bank statement.',
            'held_by' => $staffId ?? Staff::factory(),
            'held_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'money_not_received', ?string $staffId = null): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'reject_reason' => $reason,
            'reject_note' => 'Nothing arrived after three days.',
            'rejected_by' => $staffId ?? Staff::factory(),
            'rejected_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled', 'cancelled_at' => now()]);
    }

    public function withReceipt(string $ref = 'topup-receipts/test/receipt.enc', string $mime = 'image/png'): static
    {
        return $this->state(fn () => ['receipt_ref' => $ref, 'receipt_mime' => $mime]);
    }

    /** Credited with the claimed amount (or `$amount`), posting the ledger entry. */
    public function credited(?string $amount = null, ?string $staffId = null): static
    {
        return $this->state(function (array $a) use ($amount, $staffId) {
            $credited = $amount ?? $a['claimed_amount'] ?? '20000.00';

            return [
                'status' => 'credited',
                'credited_amount' => $credited,
                'credit_note' => ($a['origin'] ?? 'notice') === 'by_hand' || bccomp((string) $credited, (string) ($a['claimed_amount'] ?? $credited), 2) !== 0
                    ? 'Provider fee taken.'
                    : null,
                'receiving_account_id' => $a['notice_account_id'] ?? ReceivingAccount::factory()->state(self::accountFor($a['method'] ?? 'instapay'))->create()->receiving_account_id,
                'credited_by' => $staffId ?? Staff::factory()->create()->staff_id,
                'credited_at' => now(),
            ];
        })->afterMaking(function (TopUp $topUp) {
            $amount = bcadd((string) $topUp->credited_amount, '0', 4);
            $topUp->ledger_txn_id = DB::transaction(fn () => app(PostLedgerEntryAction::class)->handle(new LedgerEntry(
                LedgerEventKind::TOPUP,
                [
                    new LedgerLine(Account::internal(AccountKind::BANK), '-'.$amount),
                    new LedgerLine(Account::forCustomerKind($topUp->customer_id, AccountKind::CUST_AVAILABLE), $amount),
                ],
                actorStaffId: $topUp->credited_by,
                memo: 'Top-up (test fixture)',
            ))->ledger_txn_id);
        });
    }

    /** A hand credit: no notice, no claim, a required note. */
    public function byHand(string $amount = '15000.00', ?string $staffId = null): static
    {
        return $this->state(fn () => [
            'origin' => 'by_hand',
            'claimed_amount' => null,
            'notice_account_id' => null,
        ])->credited($amount, $staffId);
    }

    /** @return array<string, string|null> */
    private static function accountFor(string $method): array
    {
        return match ($method) {
            'bank_transfer' => ['method' => 'bank_transfer', 'label' => 'Test Bank', 'bank_name' => 'Test Bank', 'account_holder' => 'Dahab Test', 'account_number' => '0000 0000 0000', 'instapay_address' => null, 'daily_limit' => null, 'provider_fee_percent' => null],
            'vodafone_cash' => ['method' => 'vodafone_cash', 'label' => 'Vodafone Cash test', 'wallet_number' => '01000000000', 'instapay_address' => null],
            default => ['method' => 'instapay', 'instapay_address' => 'dahab.test@instapay'],
        };
    }
}
