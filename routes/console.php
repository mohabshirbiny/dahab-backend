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
