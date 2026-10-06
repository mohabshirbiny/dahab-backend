<?php

use App\Actions\Account\CloseAccountAction;
use App\Actions\Account\ConfirmPhoneChangeAction;
use App\Actions\Account\EmailChangeLinkAction;
use App\Actions\Account\RequestEmailChangeAction;
use App\Actions\Account\RequestPhoneChangeAction;
use App\Actions\BuyRequests\SendBuyRequestAction;
use App\Actions\ListingReports\ListingReportsAction;
use App\Actions\TopUp\CreditTopUpByHandAction;
use App\Actions\Withdrawals\Customer\SubmitWithdrawalAction;
use App\Enums\AccountKind;
use App\Enums\CloseReason;
use App\Enums\ReportReason;
use App\Enums\SeedRole;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\PayoutAccount;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Notifications\EmailChangeLinkNotification;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Account as AccountHelp;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 017 T018, T022, T035 (research R14): requests that race on one
// customer. Connection A holds its transaction; B must wait for A's lock;
// after A commits, B is refused. The spec 012/013 technique: committed
// fixtures, two connections, then every committed row is removed.

function acc017OnConnection(string $name, Closure $work): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);
    DatabaseActor::reapply();

    try {
        return $work();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function acc017BlockedWhileAHolds(Closure $attempt): bool
{
    return acc017OnConnection('pgsql_b', function () use ($attempt) {
        DB::statement("SET lock_timeout = '300ms'");
        try {
            $attempt();

            return false;
        } catch (QueryException $e) {
            return ($e->errorInfo[0] ?? null) === '55P03';
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::statement('SET lock_timeout = 0');
        }
    });
}

/** @return string 'ran' | 'refused:<code>' */
function acc017AfterACommitted(Closure $attempt): string
{
    return acc017OnConnection('pgsql_b', function () use ($attempt) {
        try {
            $attempt();

            return 'ran';
        } catch (DomainApiException $e) {
            return 'refused:'.$e->errorCode;
        } catch (QueryException $e) {
            return 'refused:'.($e->errorInfo[0] ?? '?');
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    });
}

function acc017As(string $scope, ?string $customerId, ?string $staffId, Closure $work): mixed
{
    DatabaseActor::push($scope, customerId: $customerId, staffId: $staffId);

    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

function acc017Committed(Closure $build, Closure $race): void
{
    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::rollBack();
    DatabaseActor::reapply();
    Notification::fake();

    try {
        $fixtures = DB::transaction(fn () => $build());
        Auth::forgetGuards();
        app()->forgetInstance(RequestContext::class);
        $race(...$fixtures);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DatabaseActor::reapply();
        DatabaseActor::elevate('maintenance', function () {
            $keep = ['migrations', 'karat', 'karat_price_adjustment', 'legal_document', 'listing_transition', 'buy_request_transition',
                'order_transition', 'withdrawal_transition', 'payout_account_transition', 'dispute_transition', 'extension_request_transition', 'piece_type', 'setting', 'staff', 'branch'];
            $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->pluck('tablename')
                ->reject(fn ($t) => in_array($t, $keep, true))->map(fn ($t) => '"'.$t.'"')->implode(', ');
            DB::statement("TRUNCATE {$tables} CASCADE");
            DB::table('staff')->where('staff_id', '!=', SystemActor::id())->delete();
            DB::table('branch')->delete();
        });
        (require base_path('database/migrations/2026_10_01_000010_create_ledger.php'))->provision();
        Account::flushInternalCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::purge('pgsql_b');
        DB::beginTransaction();
    }
}

it('never lets a withdrawal slip in after a phone change outside the pause', function () {
    acc017Committed(function () {
        $customer = Withdrawals::funded('5000');
        $account = Withdrawals::verifiedAccount($this, $customer);
        $confirmation = Withdrawals::confirmedFor($this, $customer, '4000', $account->payout_account_id);
        $challenge = DatabaseActor::elevate('maintenance', fn () => app(RequestPhoneChangeAction::class)->handle($customer, '+201088887777'))['challenge_id'];

        return [$customer, $account, $confirmation, $challenge, AccountHelp::phoneCode('+201088887777')];
    }, function (Customer $customer, PayoutAccount $account, string $confirmation, string $challenge, string $code) {
        $confirm = fn () => acc017As('customer', $customer->customer_id, null, fn () => app(ConfirmPhoneChangeAction::class)->handle($customer, $challenge, $code, null, null));
        $submit = fn () => acc017As('customer', $customer->customer_id, null, fn () => app(SubmitWithdrawalAction::class)->handle($customer, $confirmation, '4000', $account->payout_account_id));

        DB::beginTransaction();
        $confirm();
        $waited = acc017BlockedWhileAHolds(fn () => DB::transaction($submit));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(acc017AfterACommitted(fn () => DB::transaction($submit)))->toBe('refused:withdrawals_paused')
            ->and(acc017AfterACommitted(fn () => $confirm()))->toBe('refused:change_code_invalid');
    });
});

it('changes the email once when its link is used twice at once', function () {
    acc017Committed(function () {
        $customer = Withdrawals::funded('0', ['email' => 'old@example.com']);
        DatabaseActor::elevate('maintenance', fn () => app(RequestEmailChangeAction::class)->handle($customer, 'new@example.com'));
        $url = null;
        Notification::assertSentOnDemand(EmailChangeLinkNotification::class, function ($n, $c, AnonymousNotifiable $to) use (&$url) {
            $url = $n->toMail($to)->actionUrl;

            return true;
        });

        return [$customer, substr($url, strpos($url, 'token=') + 6)];
    }, function (Customer $customer, string $token) {
        $confirm = fn () => acc017As('bootstrap', null, null, fn () => app(EmailChangeLinkAction::class)->confirm($token));

        DB::beginTransaction();
        $confirm();
        $waited = acc017BlockedWhileAHolds(fn () => DB::transaction($confirm));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(acc017AfterACommitted(fn () => DB::transaction($confirm)))->toBe('refused:change_link_invalid')
            ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('withdrawal_pause')->where('customer_id', $customer->customer_id)->count()))->toBe(1);
    });
});

it('never lets money reach an account closed at the same moment', function () {
    acc017Committed(function () {
        $customer = Customer::factory()->verified()->create();
        $finance = Staff::factory()->role(SeedRole::FINANCE)->create();
        $receiving = ReceivingAccount::factory()->create();

        return [$customer, $finance, $receiving->receiving_account_id];
    }, function (Customer $customer, Staff $finance, int $receivingId) {
        $close = fn () => acc017As('customer', $customer->customer_id, null, fn () => app(CloseAccountAction::class)->close($customer, CloseReason::FINISHED, null));
        $credit = fn () => acc017As('staff', null, $finance->staff_id, fn () => app(CreditTopUpByHandAction::class)
            ->handle($finance, $customer->customer_id, '500', $receivingId, 'Arrived without a notice', 'FT9'));

        DB::beginTransaction();
        $close();
        $waited = acc017BlockedWhileAHolds(fn () => DB::transaction($credit));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(acc017AfterACommitted(fn () => DB::transaction($credit)))->toStartWith('refused:')
            ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_posting')
                ->where('account_id', Account::forCustomerKind($customer->customer_id, AccountKind::CUST_AVAILABLE))->count()))->toBe(0);
    });
});

it('never lets a buy request land on a piece whose seller closes at the same moment', function () {
    acc017Committed(function () {
        Orders::workedPrices();
        $listing = Orders::ring();
        $buyer = BuyRequests::funded('60000');

        return [Customer::query()->findOrFail($listing->seller_id), $listing, $buyer, BuyRequests::price($this, $listing), BuyRequests::termsId()];
    }, function (Customer $seller, Listing $listing, Customer $buyer, string $price, int $terms) {
        $close = fn () => acc017As('customer', $seller->customer_id, null, fn () => app(CloseAccountAction::class)->close($seller, CloseReason::FINISHED, null));
        $send = fn () => acc017As('customer', $buyer->customer_id, null, fn () => app(SendBuyRequestAction::class)->handle($buyer, $listing->listing_id, $price, $terms));

        DB::beginTransaction();
        $close();
        $waited = acc017BlockedWhileAHolds(fn () => DB::transaction($send));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(acc017AfterACommitted(fn () => DB::transaction($send)))->toStartWith('refused:')
            ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('buy_request')->count()))->toBe(0);
    });
});

it('keeps one open report per reporter and piece under racing requests', function () {
    acc017Committed(function () {
        Orders::workedPrices();

        return [Orders::ring(), Customer::factory()->verified()->create()];
    }, function (Listing $listing, Customer $reporter) {
        $report = fn () => acc017As('customer', $reporter->customer_id, null, fn () => app(ListingReportsAction::class)
            ->report($reporter, $listing->listing_id, ReportReason::OTHER, null));

        DB::beginTransaction();
        $report();
        $waited = acc017BlockedWhileAHolds(fn () => DB::transaction($report));
        DB::commit();
        DatabaseActor::reapply();

        expect($waited)->toBeTrue()
            ->and(acc017AfterACommitted(fn () => DB::transaction($report)))->toBe('refused:23505')
            ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('listing_report')->count()))->toBe(1);
    });
});
