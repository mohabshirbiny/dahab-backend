<?php

use App\Models\OrderStateChange;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-004, SC-002, research R2: every sweep pass selects by state, so
// a frozen order is never cancelled, forfeited, expired or reminded — the
// deadline columns keep their values for the give-back on resume.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('leaves a frozen order alone past every deadline', function (string $from) {
    $order = match ($from) {
        'weight_adjust_pending' => Disputes::deciding($this),
        'awaiting_balance' => Disputes::awaitingBalance($this),
        'ready_to_collect' => Disputes::readyToCollect($this),
    };
    Disputes::opened($this, $order);
    $order->refresh();
    $columns = [$order->decision_due_deadline, $order->balance_due_deadline, $order->collect_deadline,
        $order->balance_reminder_sent_at];
    $listingState = DatabaseActor::elevate('maintenance', fn () => DB::table('listing')->where('listing_id', $order->listing_id)->value('state'));
    $changes = DatabaseActor::elevate('maintenance', fn () => OrderStateChange::query()->where('order_id', $order->order_id)->count());

    foreach ([1, 12, 60] as $days) {
        $this->travel($days)->days();
        Orders::sweep();
    }

    $order->refresh();
    expect($order->state->value)->toBe('disputed')
        ->and([$order->decision_due_deadline, $order->balance_due_deadline, $order->collect_deadline, $order->balance_reminder_sent_at])->toEqual($columns)
        ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('listing')->where('listing_id', $order->listing_id)->value('state')))->toBe($listingState)
        ->and(DatabaseActor::elevate('maintenance', fn () => OrderStateChange::query()->where('order_id', $order->order_id)->count()))->toBe($changes)
        ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction')->where('order_id', $order->order_id)
            ->whereIn('event_kind', ['deposit_forfeit', 'deposit_release'])->count()))->toBe(0);
})->with(['weight_adjust_pending', 'awaiting_balance', 'ready_to_collect']);
