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

// Validity is a date, so a quote cannot lapse part-way through a day. Swept
// just after midnight on the office clock rather than every minute, which would
// be the same indexed query answering "nothing" fourteen hundred times a day.
Schedule::command('quotes:expire')
    ->dailyAt('00:10')
    ->withoutOverlapping();

// A mailbox is polled, because IMAP has nothing to push with. Five minutes is
// the compromise: a customer's reply appearing on the record within five
// minutes is fast enough for anybody, and a poll a minute against a mailbox
// that is usually empty is twelve times the load for no useful difference.
// withoutOverlapping because a large first sync can outlast the interval.
Schedule::command('mail:sync-inbound')
    ->everyFiveMinutes()
    ->withoutOverlapping();
