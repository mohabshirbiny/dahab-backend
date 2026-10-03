<?php

namespace Database\Seeders;

use App\Actions\Ledger\PostLedgerEntryAction;
use App\Actions\Payouts\Customer\AddPayoutAccountAction;
use App\Actions\Payouts\Customer\UsePayoutAccountAction;
use App\Actions\Payouts\Staff\RefusePayoutAccountAction;
use App\Actions\Payouts\Staff\VerifyPayoutAccountAction;
use App\Actions\Withdrawals\AnnounceEndedPausesAction;
use App\Actions\Withdrawals\Customer\CancelWithdrawalAction;
use App\Actions\Withdrawals\Customer\RequestWithdrawalConfirmationAction;
use App\Actions\Withdrawals\Customer\SubmitWithdrawalAction;
use App\Actions\Withdrawals\Public\ConfirmWithdrawalAction;
use App\Actions\Withdrawals\Staff\HoldWithdrawalAction;
use App\Actions\Withdrawals\Staff\RejectWithdrawalAction;
use App\Actions\Withdrawals\Staff\ReleaseWithdrawalAction;
use App\Actions\Withdrawals\Staff\TakeForReviewAction;
use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Enums\PayoutRefusalReason;
use App\Enums\SeedRole;
use App\Enums\WithdrawalHoldReason;
use App\Enums\WithdrawalRejectReason;
use App\Models\Account;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\PayoutAccount;
use App\Models\Staff;
use App\Models\Withdrawal;
use App\Models\WithdrawalConfirmation;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Withdrawals\ConfirmationTokens;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Payout accounts and withdrawals in each state to try by hand (spec 013,
 * research R16), made through the real Actions — never written directly.
 *
 *  - Hoda (900006): CIB in use (verified by Verification), an NBE account
 *    waiting for the name check; withdrawals released (with the bank record),
 *    rejected, cancelled by her, requested, and under review on hold.
 *  - Karim (900007): an old pause already over and announced (an account
 *    switch three days ago, which cancelled a withdrawal), a refused account,
 *    and a switch just now — so his withdrawals are paused for 48 hours.
 *
 * The email second-check is done as a customer would: the confirmation is
 * requested (the mail goes to the log), its link opened (ConfirmWithdrawalAction
 * with a token the seeder knows), then the withdrawal submitted. Funds come
 * from a demo top-up through the money service. Refuses to run outside
 * local/testing; does nothing when withdrawals already exist.
 */
class LocalWithdrawalSeeder extends Seeder
{
    public const MARKER = 'Demo data (LocalWithdrawalSeeder)';

    public function run(PostLedgerEntryAction $post): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalWithdrawalSeeder skipped: only runs in local/testing.');

            return;
        }
        if (Withdrawal::query()->exists()) {
            $this->command?->info('LocalWithdrawalSeeder: withdrawals already there.');

            return;
        }

        $hoda = Customer::query()->where('display_ref', '900006')->first();
        $karim = Customer::query()->where('display_ref', '900007')->first();
        $finance = Staff::query()->where('email', SeedRole::FINANCE->value.'@dahab.test')->first();
        $verification = Staff::query()->where('email', SeedRole::VERIFICATION->value.'@dahab.test')->first();
        if ($hoda === null || $karim === null || $finance === null || $verification === null
            || LegalDocument::current(LegalDocument::PAYOUT_ACCOUNT_DECLARATION) === null) {
            $this->command?->warn('LocalWithdrawalSeeder skipped: run LocalCustomerSeeder and LocalStaffSeeder first.');

            return;
        }

        $this->fund($post, $hoda, $finance, '300000');
        $this->fund($post, $karim, $finance, '100000');

        // Hoda: one account in use, one waiting for the name check.
        $cib = $this->addAccount($hoda, 'CIB', 'Hoda Mahmoud Ali', 'EG380019000500000000263180002');
        $this->asStaff($verification, fn () => app(VerifyPayoutAccountAction::class)->handle($verification, $cib->payout_account_id));
        $this->addAccount($hoda, 'NBE', 'Hoda Mahmoud Ali', '1002003004005');

        $released = $this->withdraw($hoda, $cib, '42000');
        $this->staffDo($finance, fn () => app(TakeForReviewAction::class)->handle($finance, $released->withdrawal_id));
        $this->staffDo($finance, fn () => app(ReleaseWithdrawalAction::class)->handle($finance, $released->withdrawal_id, 'FT26100312345', $released->number(), now()->toDateString()));

        $rejected = $this->withdraw($hoda, $cib, '15000');
        $this->staffDo($finance, fn () => app(RejectWithdrawalAction::class)->handle($finance, $rejected->withdrawal_id,
            WithdrawalRejectReason::MONEY_IN_STRAIGHT_OUT, self::MARKER.': topped up and asked out the same day'));

        $cancelled = $this->withdraw($hoda, $cib, '5000');
        $this->asCustomer($hoda, fn () => app(CancelWithdrawalAction::class)->handle($hoda, $cancelled->withdrawal_id));

        $held = $this->withdraw($hoda, $cib, '25000');
        $this->staffDo($finance, fn () => app(TakeForReviewAction::class)->handle($finance, $held->withdrawal_id));
        $this->staffDo($finance, fn () => app(HoldWithdrawalAction::class)->hold($finance, $held->withdrawal_id,
            WithdrawalHoldReason::NAME_MISMATCH, 'We need to check the name on your bank account. We will call you within one working day.',
            self::MARKER.': bank name shortened on the statement'));

        $this->withdraw($hoda, $cib, '10000'); // waiting for review

        // Karim: an account switch three days ago (cancelling a withdrawal), its pause over and announced.
        Carbon::setTestNow(CarbonImmutable::now()->subDays(3));
        try {
            $qnb = $this->addAccount($karim, 'QNB', 'Karim Adel Nour', '55667788990011');
            $this->asStaff($verification, fn () => app(VerifyPayoutAccountAction::class)->handle($verification, $qnb->payout_account_id));
            $this->withdraw($karim, $qnb, '20000');
            $aaib = $this->addAccount($karim, 'AAIB', 'Karim Adel Nour', '99887766554433');
            $this->asStaff($finance, fn () => app(VerifyPayoutAccountAction::class)->handle($finance, $aaib->payout_account_id));
            $this->asCustomer($karim, fn () => app(UsePayoutAccountAction::class)->handle($karim, $aaib->payout_account_id));
        } finally {
            Carbon::setTestNow();
        }
        DatabaseActor::elevate('system', function () {
            $announce = app(AnnounceEndedPausesAction::class);
            foreach ($announce->due() as $pauseId) {
                $announce->handle($pauseId);
            }
        });

        // A refused account, then a switch back just now: withdrawals paused for 48 hours.
        $short = $this->addAccount($karim, 'Banque Misr', 'Karim A. Nour', '11223344556677');
        $this->asStaff($finance, fn () => app(RefusePayoutAccountAction::class)->handle($finance, $short->payout_account_id,
            PayoutRefusalReason::NAME_SHORTENED, self::MARKER.': ID says Karim Adel Nour'));
        $this->asCustomer($karim, fn () => app(UsePayoutAccountAction::class)->handle($karim, $qnb->payout_account_id));

        $this->command?->info('LocalWithdrawalSeeder: Hoda (900006) has withdrawals in every state and an account to check; Karim (900007) is paused for 48 hours.');
    }

    private function fund(PostLedgerEntryAction $post, Customer $customer, Staff $finance, string $amount): void
    {
        DB::transaction(fn () => $post->handle(new LedgerEntry(LedgerEventKind::TOPUP, [
            new LedgerLine(Account::internal(AccountKind::BANK), '-'.$amount),
            new LedgerLine(Account::forCustomerKind($customer->customer_id, AccountKind::CUST_AVAILABLE), $amount),
        ], actorStaffId: $finance->staff_id, memo: self::MARKER.': bank transfer matched by reference')));
    }

    private function addAccount(Customer $customer, string $bank, string $holder, string $number): PayoutAccount
    {
        $declaration = (int) LegalDocument::current(LegalDocument::PAYOUT_ACCOUNT_DECLARATION)->legal_doc_id;

        return $this->asCustomer($customer, fn () => app(AddPayoutAccountAction::class)->handle($customer, $bank, $holder, $number, $declaration));
    }

    /** Request the email check, open the link (a token the seeder knows), submit. */
    private function withdraw(Customer $customer, PayoutAccount $account, string $amount): Withdrawal
    {
        $confirmation = $this->asCustomer($customer, fn () => app(RequestWithdrawalConfirmationAction::class)->handle($customer, $amount, $account->payout_account_id));

        $token = ConfirmationTokens::generate();
        DatabaseActor::elevate('system', fn () => WithdrawalConfirmation::query()->whereKey($confirmation->confirmation_id)
            ->update(['token_hash' => ConfirmationTokens::hash($token)]));
        DatabaseActor::elevate('bootstrap', fn () => app(ConfirmWithdrawalAction::class)->confirm($token));

        return $this->asCustomer($customer, fn () => app(SubmitWithdrawalAction::class)->handle($customer, $confirmation->confirmation_id, $amount, $account->payout_account_id));
    }

    /** @template T @param Closure(): T $work @return T */
    private function asCustomer(Customer $customer, Closure $work): mixed
    {
        DatabaseActor::push('customer', customerId: $customer->customer_id);
        try {
            return $work();
        } finally {
            DatabaseActor::pop();
        }
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

    /** @template T @param Closure(): T $work @return T */
    private function staffDo(Staff $staff, Closure $work): mixed
    {
        return $this->asStaff($staff, $work);
    }
}
