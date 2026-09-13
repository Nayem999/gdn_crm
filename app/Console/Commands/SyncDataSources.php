<?php

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\SyncDataSourceAction;
use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\Models\DataSource;
use Illuminate\Console\Command;

/**
 * Fetches from every pull source whose turn it is.
 *
 * Run every fifteen minutes, which is the finest schedule a source can be set
 * to. Each source decides for itself whether it is due, measured from when it
 * last ran rather than against a fixed clock slot — a sync that overran should
 * not be immediately followed by another.
 *
 * One source failing does not stop the rest: their outage is not our outage,
 * and the run's own summary records what happened.
 */
class SyncDataSources extends Command
{
    protected $signature = 'ingest:sync {--source= : One source uuid, rather than everything due}';

    protected $description = 'Fetch from the inbound sources that pull';

    public function handle(SyncDataSourceAction $sync): int
    {
        $uuid = $this->option('source');

        $sources = DataSource::query()
            ->where('type', DataSourceType::Pull->value)
            ->when(is_string($uuid) && $uuid !== '', fn ($query) => $query->where('uuid', $uuid))
            ->orderBy('id')
            ->get()
            // Asked per source rather than in SQL: "is it due" is four
            // conditions including one about elapsed time, and a query that
            // tried to express it would be a second statement of the rule.
            ->filter(fn (DataSource $source) => is_string($uuid) && $uuid !== '' ? true : $source->isDueForSync());

        foreach ($sources as $source) {
            $summary = $sync($source);

            $this->line(sprintf(
                '%s: %s',
                $source->name,
                $summary['ok'] ?? false
                    ? ($summary['records'] ?? 0).' records over '.($summary['pages'] ?? 0).' pages'
                    : 'failed — '.($summary['error'] ?? 'no reason given')
            ));
        }

        $this->info($sources->count().' '.str('source')->plural($sources->count()).' synced.');

        return self::SUCCESS;
    }
}
