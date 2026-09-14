<?php

namespace Database\Factories;

use App\Domain\Shared\Models\ImportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRun>
 */
class ImportRunFactory extends Factory
{
    protected $model = ImportRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module' => 'leads',
            'user_id' => User::factory(),
            'original_filename' => 'leads.csv',
            'path' => 'imports/leads.csv',
            'mapping' => [],
            'status' => 'pending',
            'total_rows' => 0,
            'imported_rows' => 0,
            'failed_rows' => 0,
            'errors' => [],
            'failure_reason' => null,
            'started_at' => null,
        ];
    }

    /**
     * A finished run, with the counts and the stamp set together — a run
     * saying "finished" with no started_at is a state nothing produces.
     */
    public function finished(int $imported = 10, int $failed = 0): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'total_rows' => $imported + $failed,
            'imported_rows' => $imported,
            'failed_rows' => $failed,
            'started_at' => now()->subMinute(),
        ]);
    }

    public function failed(string $reason = 'The file could not be read.'): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'failure_reason' => $reason,
            'started_at' => now()->subMinute(),
        ]);
    }

    public function into(string $module): static
    {
        return $this->state(fn () => ['module' => $module]);
    }
}
