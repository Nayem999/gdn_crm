<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * A nightly dump of the database, kept for a fortnight.
 *
 * Written here rather than pulled in as a package: a backup is one mysqldump
 * and a retention rule, and the stack this application is allowed to use is
 * fixed. What matters is not the tool but that it exists, runs unattended, and
 * fails loudly.
 *
 * It writes to the **private** disk. A backup on a disk the web server serves
 * is the whole database available to anyone who guesses a filename, which is a
 * worse hole than having no backup at all.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--keep=14 : How many days of backups to keep}';

    protected $description = 'Dump the database to the private disk and prune old dumps';

    /**
     * Where the dumps live, relative to the private disk's root.
     */
    public const DIRECTORY = 'backups';

    public function handle(): int
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === '') {
            $this->error('No database is configured.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        $name = $database.'-'.now()->format('Y-m-d-His').'.sql';
        $path = $disk->path(self::DIRECTORY.'/'.$name);

        $process = new Process($this->command($connection, $database, $path));

        // A large database takes longer than the default sixty seconds, and a
        // backup killed half-written is worse than one that did not start.
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            // The message, not the command: a dump command carries the database
            // password, and this goes to the log.
            $this->error('The dump failed: '.trim($process->getErrorOutput()));

            File::delete($path);

            return self::FAILURE;
        }

        $size = File::exists($path) ? File::size($path) : 0;

        if ($size === 0) {
            $this->error('The dump produced an empty file.');

            File::delete($path);

            return self::FAILURE;
        }

        $this->info('Wrote '.$name.' ('.round($size / 1024 / 1024, 2).'MB).');

        $this->prune((int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * The dump command, as an argument list.
     *
     * A list rather than a string, so nothing here goes through a shell and a
     * database name with a character in it cannot become part of the command.
     *
     * @return array<int, string>
     */
    private function command(string $connection, string $database, string $path): array
    {
        $config = (array) config("database.connections.{$connection}");

        return array_values(array_filter([
            'mysqldump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? 'root'),
            ($config['password'] ?? '') === '' ? null : '--password='.$config['password'],
            // Consistent without locking the whole database: a backup that
            // blocked writes for its duration is one somebody switches off.
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--no-tablespaces',
            '--result-file='.$path,
            $database,
        ], fn (?string $argument) => $argument !== null));
    }

    /**
     * Remove dumps older than the retention window.
     *
     * Pruned after a successful write, never before: a failed backup must not
     * also take away the last good one.
     */
    private function prune(int $days): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $removed = 0;

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->info('Pruned '.$removed.' old '.str('backup')->plural($removed).'.');
        }
    }
}
