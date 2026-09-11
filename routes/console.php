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

// The two workflow triggers no record event can raise: a date arriving and a
// schedule coming round. Every minute, because a cron expression is accurate to
// the minute and a date trigger's lead time is configured in them. Both sweeps
// are indexed queries that find nothing when there is nothing due.
// withoutOverlapping so a slow sweep cannot be joined by the next one — though
// the dedupe key would refuse the duplicate anyway.
Schedule::command('workflows:run-triggers')
    ->everyMinute()
    ->withoutOverlapping();
