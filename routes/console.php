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

// Every fifteen minutes, which is the finest schedule a pull source can be set
// to. Each source decides whether it is actually due, so this costs one query
// when nothing is.
Schedule::command('ingest:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// The SLA clock, swept every minute. A minute is the finest resolution a
// promise is ever stated to, and the sweep is one indexed range scan when
// nothing is close. withoutOverlapping so a slow run cannot be joined by the
// next one — though the stamps on each ticket would refuse the duplicate
// anyway.
Schedule::command('support:sweep-sla')
    ->everyMinute()
    ->withoutOverlapping();

// Scheduled reports. Hourly rather than every minute, because a schedule is set
// to an hour: nobody needs a report at 09:17, and an hourly sweep is one
// indexed range scan against next_run_at when nothing is due.
Schedule::command('reports:send-scheduled')
    ->hourly()
    ->withoutOverlapping();

// Meta's advertising figures. Every fifteen minutes, which is as often as Meta
// has anything new to say — its own attribution windows move a day's spend for
// up to seventy-two hours, so a faster poll would re-read the same numbers and
// spend the rate limit doing it. The command only queues, one job per ad
// account, so a slow account cannot hold the tick; withoutOverlapping anyway,
// because dispatching the same accounts twice would have two jobs upserting the
// same days.
Schedule::command('meta:sync-ads')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Whether Meta still accepts the tokens we hold. Daily and early, because the
// failure is silent: a token revoked in Business Settings breaks nothing until
// somebody tries to send a message, and what comes back then names an app id
// and reads like a misconfiguration rather than a dead credential. Asking on a
// schedule means the connection screen already says so when they go looking.
Schedule::command('meta:check-tokens')
    ->dailyAt('06:10')
    ->withoutOverlapping();

// The database, nightly, before anybody arrives. Kept on the private disk for
// a fortnight; see BackupDatabase for why it is not on the public one.
Schedule::command('backup:database')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    // A backup that fails silently is no backup. This puts the failure in the
    // log where the monitoring picks it up.
    ->onFailure(fn () => logger()->error('The nightly database backup failed.'));

// Every hour is finer-grained than the setting's own coarsest option (a
// week), and coarser than its finest (4 hours) would ask for exactly the
// hourly cadence this gives — an escalation window measured in hours does
// not need to be checked every minute.
Schedule::command('leads:escalate-assignments')
    ->hourly()
    ->withoutOverlapping();
