<?php

use App\Actions\Orders\Customer\CancelOrderBySellerAction;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Sms\SmsDeliveryException;
use App\Services\Sms\SmsSender;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 T076, FR-025, research R20: each order event reaches the right
// parties in their own language, only after the change commits (nothing on a
// rollback); a customer is not told about their own action, except the
// collection code; and an SMS that fails never undoes the change.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
});

/** @return array<string, list<OrderEvent>> events dispatched per customer id */
function toldWhom(): array
{
    $told = [];
    Bus::dispatched(NotifyCustomerJob::class)->each(function (NotifyCustomerJob $job) use (&$told) {
        $told[$job->customerId][] = $job->notification->event;
    });

    return $told;
}

it('tells both parties when the piece arrives', function () {
    Bus::fake([NotifyCustomerJob::class]);
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $this->order)->assertOk();

    expect(toldWhom())->toBe([
        $this->buyer->customer_id => [OrderEvent::RECEIVED],
        $this->seller->customer_id => [OrderEvent::RECEIVED],
    ]);
});

it('tells the buyer, not the seller, when the seller cancels', function () {
    Bus::fake([NotifyCustomerJob::class]);
    Orders::cancel($this, $this->seller, $this->order)->assertOk();

    expect(toldWhom())->toBe([$this->buyer->customer_id => [OrderEvent::SELLER_CANCELLED]]);
});

it('tells the seller they were paid and gives the buyer their code: the one message about your own action', function () {
    Orders::inspected($this, $this->order, '10.000');
    Bus::fake([NotifyCustomerJob::class]);
    Orders::pay($this, $this->buyer, $this->order)->assertOk();

    expect(toldWhom())->toBe([
        $this->seller->customer_id => [OrderEvent::PAID],
        $this->buyer->customer_id => [OrderEvent::COLLECTION_CODE],
    ]);
});

it('writes each message in its reader\'s language and never names the other party', function () {
    DatabaseActor::elevate('maintenance', fn () => DB::table('customer')->where('customer_id', $this->buyer->customer_id)->update(['preferred_lang' => 'en']));
    DatabaseActor::elevate('maintenance', fn () => DB::table('customer')->where('customer_id', $this->seller->customer_id)->update(['preferred_lang' => 'ar']));
    Bus::fake([NotifyCustomerJob::class]);
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $this->order)->assertOk();

    $jobs = Bus::dispatched(NotifyCustomerJob::class)->keyBy('customerId');
    $read = fn (Customer $c) => $jobs[$c->customer_id]->notification->toSms(Customer::query()->find($c->customer_id))->content;

    expect($read($this->buyer))->toContain('reached the branch')->toContain($this->order->order_ref)
        ->and($read($this->seller))->toContain('وصلت الفرع')->toContain($this->order->order_ref);
    foreach ([[$this->buyer, $this->seller], [$this->seller, $this->buyer]] as [$reader, $other]) {
        foreach (array_filter([$other->full_name, $other->phone, $other->display_ref]) as $secret) {
            expect($read($reader))->not->toContain((string) $secret);
        }
    }
});

it('sends nothing when the change rolls back', function () {
    Bus::fake([NotifyCustomerJob::class]);

    try {
        DB::transaction(function () {
            DatabaseActor::push('customer', customerId: $this->seller->customer_id);
            try {
                app(CancelOrderBySellerAction::class)->bySeller($this->seller, $this->order->order_id);
            } finally {
                DatabaseActor::pop();
            }
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    Bus::assertNotDispatched(NotifyCustomerJob::class);
    expect(DatabaseActor::elevate('maintenance', fn () => Order::query()->find($this->order->order_id)->state))->toBe(OrderState::AWAITING_DELIVERY);
});

it('keeps the change when an SMS fails', function () {
    Notification::swap(app(ChannelManager::class));
    app()->instance(SmsSender::class, new class implements SmsSender
    {
        public function send(string $to, string $message): void
        {
            throw new SmsDeliveryException('provider down');
        }
    });

    Orders::staff($this, SeedRole::OPERATIONS);
    $this->withoutExceptionHandling();
    try {
        Orders::receive($this, $this->order);
    } catch (Throwable) {
        // A sync queue surfaces the failed job here; the order has already moved.
    }

    expect(DatabaseActor::elevate('maintenance', fn () => Order::query()->find($this->order->order_id)->state))->toBe(OrderState::AT_INSPECTION);
});
