<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * What `/up` actually checks.
 *
 * Laravel's health endpoint answers 200 as long as the framework booted, which
 * a monitor reads as "everything is fine" while the database is unreachable and
 * every page is a 500. Throwing from here turns it into a 500, which is what a
 * monitor is for.
 *
 * Deliberately cheap: three round trips, no writes to business tables. A health
 * check is polled every few seconds and must not itself be load.
 */
class VerifyApplicationHealth
{
    public function handle(DiagnosingHealth $event): void
    {
        $this->check('database', fn () => DB::connection()->getPdo());

        // The cache store carries the settings and the permission matrix; with
        // it gone every request falls back to the database and the application
        // is alive but crawling.
        $this->check('cache', function () {
            $key = 'health:'.uniqid();

            Cache::put($key, true, 10);
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== true) {
                throw new RuntimeException('the cache did not return what was put in it');
            }
        });

        // The private disk holds documents and backups. Readable, not writable:
        // a health check that wrote a file every few seconds would fill it.
        $this->check('storage', fn () => Storage::disk('local')->exists('.'));
    }

    private function check(string $what, callable $probe): void
    {
        try {
            $probe();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Health check failed on the '.$what.': '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
