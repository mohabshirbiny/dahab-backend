<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The gold price feed (spec 005, Technical Spec Part 4 §1). Needs
// `php artisan schedule:run` every minute in every environment.
Schedule::command('pricing:pull-feed')->everyMinute()->withoutOverlapping();

// The seller-reply sweep (spec 011 FR-018, Part 2 §11): queued buy requests past
// their reply deadline are released and refunded, one per transaction.
Schedule::command('buy-requests:expire')->everyMinute()->withoutOverlapping();

// The order deadlines (spec 012 research R14, Part 2 §11): missed delivery, unpaid
// balance, the seller-return window, reminders and the cancellation threshold.
Schedule::command('orders:sweep')->everyMinute()->withoutOverlapping();

// Expired Idempotency-Key records (spec 007 research R2; kept 24 h).
Schedule::command('idempotency:prune')->hourly()->withoutOverlapping();
