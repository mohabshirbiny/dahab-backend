<?php

namespace Database\Factories;

use App\Enums\TopUpMethod;
use App\Models\ReceivingAccount;
use App\Support\SystemActor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dahab receiving accounts with obviously fake details (spec 009). Defaults
 * to an active InstaPay account last edited by the system actor.
 *
 * @extends Factory<ReceivingAccount>
 */
class ReceivingAccountFactory extends Factory
{
    protected $model = ReceivingAccount::class;

    public function definition(): array
    {
        return [
            'method' => TopUpMethod::INSTAPAY->value,
            'label' => 'InstaPay test',
            'instapay_address' => 'dahab.test@instapay',
            'daily_limit' => '70000.00',
            'provider_fee_percent' => '0.500',
            'customer_note' => 'The fee is charged by InstaPay, not by Dahab.',
            'sort_order' => 0,
            'is_active' => true,
            'updated_by' => fn () => SystemActor::id(),
        ];
    }

    public function bankTransfer(): static
    {
        return $this->state(fn () => [
            'method' => TopUpMethod::BANK_TRANSFER->value,
            'label' => 'Test Bank',
            'bank_name' => 'Test Bank',
            'account_holder' => 'Dahab Test',
            'account_number' => '0000 0000 0000',
            'instapay_address' => null,
            'daily_limit' => null,
            'provider_fee_percent' => null,
            'customer_note' => 'Dahab takes nothing on top-ups. Your bank may charge for the transfer.',
        ]);
    }

    public function instapay(): static
    {
        return $this->state(fn () => ['method' => TopUpMethod::INSTAPAY->value, 'instapay_address' => 'dahab.test@instapay']);
    }

    public function vodafoneCash(): static
    {
        return $this->state(fn () => [
            'method' => TopUpMethod::VODAFONE_CASH->value,
            'label' => 'Vodafone Cash test',
            'instapay_address' => null,
            'wallet_number' => '01000000000',
            'daily_limit' => '60000.00',
            'provider_fee_percent' => '1.000',
            'customer_note' => 'The fee is charged by Vodafone, not by Dahab.',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
