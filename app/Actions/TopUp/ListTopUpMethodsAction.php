<?php

namespace App\Actions\TopUp;

use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Support\TopUpReference;
use Illuminate\Support\Collection;

/**
 * What a customer needs to send money (spec 009 US1, FR-004, FR-005): their
 * reference and Dahab's active receiving accounts, grouped by method in
 * display order. A method with no active account is left out. The route is
 * trade-gated, so suspended and unverified customers never reach this.
 */
final class ListTopUpMethodsAction
{
    /** @return array{reference: string, methods: list<array{method: string, accounts: Collection<int, ReceivingAccount>}>} */
    public function handle(Customer $customer): array
    {
        $accounts = ReceivingAccount::query()->active()->displayOrder()->get();

        return [
            'reference' => TopUpReference::for($customer),
            'methods' => $accounts->groupBy(fn (ReceivingAccount $a) => $a->method->value)
                ->map(fn (Collection $group, string $method) => ['method' => $method, 'accounts' => $group->values()])
                ->values()
                ->all(),
        ];
    }
}
