<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Notifications\OrderNotification;
use App\Notifications\ProxyNamedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-006, FR-010, FR-025, R16: who is told what, in which language,
// only after commit; the other party never hears what the dispute says.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('words every new event in English and Arabic without the other side\'s words', function (OrderEvent $event) {
    $n = new OrderNotification($event, 'DH-2026-000001', 'Gold ring, 21K', 'خاتم دهب ٢١', '500.0000',
        now()->addDay()->toIso8601String(), 'IGI Nasr City', 'IGI مدينة نصر', null, 'We weighed it again.');

    expect($n->body(false))->not->toBeEmpty()->and($n->body(true))->not->toBeEmpty()
        ->and($n->body(false))->not->toContain('{')->and($n->body(true))->not->toContain('{');
})->with([OrderEvent::DISPUTE_OPENED, OrderEvent::DISPUTE_RESOLVED, OrderEvent::DISPUTE_RESUMED, OrderEvent::DISPUTE_CANCELLED,
    OrderEvent::COMPENSATION_PAID, OrderEvent::EXTENSION_REFUSED, OrderEvent::PROXY_NAMED]);

it('sends nothing when the resolution rolls back', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    Bus::fake([NotifyCustomerJob::class]); // forget the opening's messages

    Orders::staff($this, SeedRole::FINANCE);
    Disputes::resolve($this, $dispute, ['compensation' => ['party' => 'buyer', 'amount' => '9999', 'reason' => 'goodwill', 'note' => 'Over the cap on purpose.']])
        ->assertStatus(403);

    Bus::assertNotDispatched(NotifyCustomerJob::class);
});

it('tells the other party the order is on hold without the dispute\'s words', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $order = Disputes::awaitingBalance($this);
    Disputes::opened($this, $order);

    Bus::assertDispatched(NotifyCustomerJob::class, function ($j) use ($order) {
        return $j->customerId === $order->seller_id && $j->notification->event === OrderEvent::DISPUTE_OPENED
            && ! str_contains($j->notification->body(false), 'IGI weighed it lower');
    });
});

it('writes the proxy SMS in the buyer\'s language and never with a code', function (bool $arabic) {
    $n = new ProxyNamedNotification('Mona', 'Gold ring, 21K', 'خاتم دهب ٢١', 'Nasr City', 'مدينة نصر', $arabic);

    expect($n->via(null))->toBe(['sms'])
        ->and($n->body())->toContain('Mona')
        ->and(preg_match('/\d{6}/', $n->body()))->toBe(0);
})->with([true, false]);
