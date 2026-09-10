<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A minute is the finest lead time worth offering, and the sweep is one indexed
// query when there is nothing due. withoutOverlapping so a slow run cannot be
// joined by the next one and send the same reminder twice.
Schedule::command('activities:send-reminders')
    ->everyMinute()
    ->withoutOverlapping();

// Rolls the recurrence window forward. Overnight because nothing it produces is
// due within the horizon it just extended.
Schedule::command('activities:generate-recurrences')
    ->dailyAt('01:15')
    ->withoutOverlapping();
