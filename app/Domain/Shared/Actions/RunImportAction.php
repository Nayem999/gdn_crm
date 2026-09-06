<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Imports\ImportReader;
use App\Domain\Shared\Imports\ImportRowError;
use App\Domain\Shared\Imports\ImportSource;
use App\Domain\Shared\Imports\ImportStatus;
use App\Domain\Shared\Models\ImportRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Reads a file, validates every row against the module's rules and creates the
 * ones that pass.
 *
 * Each row is its own transaction, not the file: an import of two thousand rows
 * where row 1,700 is malformed should land the other 1,999, not nothing. That
 * is the opposite of the choice lead conversion makes, and for the opposite
 * reason — there the three records are one thing, here every row is separate.
 *
 * Records are created through the module's own action, so an imported record
 * obeys every rule a typed-in one does.
 */
class RunImportAction
{
    public function __construct(private readonly ImportReader $reader) {}

    public function __invoke(ImportRun $run): ImportRun
    {
        $source = $run->source();
        $user = $run->user;

        if ($source === null || $user === null) {
            return $this->fail($run, 'That import is for a module that is no longer available.');
        }

        $run->forceFill(['status' => ImportStatus::Running->value, 'started_at' => now()])->save();

        try {
            [$imported, $errors, $total] = $this->process($run, $source, $user);
        } catch (Throwable $exception) {
            return $this->fail($run, $exception->getMessage());
        }

        $run->forceFill([
            'status' => ImportStatus::Completed->value,
            'total_rows' => $total,
            'imported_rows' => $imported,
            'failed_rows' => count($errors),
            'errors' => array_map(
                fn (ImportRowError $error) => $error->toArray(),
                array_slice($errors, 0, ImportRun::MAX_REPORTED_ERRORS)
            ),
            'finished_at' => now(),
        ])->save();

        return $run->refresh();
    }

    /**
     * Validate a file without writing anything, for the preview.
     *
     * @param  array<int, string>  $mapping  Column index => field key.
     * @return array{total: int, valid: int, errors: array<int, ImportRowError>}
     */
    public function preview(ImportSource $source, string $path, array $mapping, int $sample = 50): array
    {
        $total = 0;
        $valid = 0;
        $errors = [];

        foreach ($this->reader->dataRows($path) as $number => $row) {
            $total++;
            $error = $this->validateRow($source, $mapping, $row, $number);

            if ($error === null) {
                $valid++;

                continue;
            }

            if (count($errors) < $sample) {
                $errors[] = $error;
            }
        }

        return ['total' => $total, 'valid' => $valid, 'errors' => $errors];
    }

    /**
     * @return array{0: int, 1: array<int, ImportRowError>, 2: int}
     */
    private function process(ImportRun $run, ImportSource $source, User $user): array
    {
        $mapping = $run->mappedFields();
        $imported = 0;
        $total = 0;
        $errors = [];

        foreach ($this->reader->dataRows($run->path) as $number => $row) {
            $total++;

            $error = $this->validateRow($source, $mapping, $row, $number);

            if ($error !== null) {
                $errors[] = $error;

                continue;
            }

            try {
                // One row, one transaction. A module's create action can write
                // several rows — a contact and its account's primary flag — and
                // half of that is worse than none of it.
                DB::transaction(fn () => $source->create($this->mapRow($mapping, $row), $user));
                $imported++;
            } catch (Throwable $exception) {
                $errors[] = new ImportRowError(
                    $number,
                    [$exception->getMessage()],
                    $this->summarise($mapping, $row),
                );
            }
        }

        return [$imported, $errors, $total];
    }

    /**
     * @param  array<int, string>  $mapping
     * @param  array<int, string>  $row
     */
    private function validateRow(ImportSource $source, array $mapping, array $row, int $number): ?ImportRowError
    {
        $values = $this->mapRow($mapping, $row);
        $rules = $source->rulesFor(array_values($mapping));

        $validator = Validator::make($values, $rules, [], $this->attributeNames($source));

        if ($validator->passes()) {
            return null;
        }

        return new ImportRowError(
            $number,
            array_values($validator->errors()->all()),
            $this->summarise($mapping, $row),
        );
    }

    /**
     * @param  array<int, string>  $mapping
     * @param  array<int, string>  $row
     * @return array<string, string|null>
     */
    private function mapRow(array $mapping, array $row): array
    {
        $values = [];

        foreach ($mapping as $column => $field) {
            $value = trim((string) ($row[$column] ?? ''));
            $values[$field] = $value === '' ? null : $value;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(ImportSource $source): array
    {
        $names = [];

        foreach ($source->fields() as $key => $field) {
            $names[$key] = strtolower($field->label);
        }

        return $names;
    }

    /**
     * Enough of the row to recognise it in the error report.
     *
     * @param  array<int, string>  $mapping
     * @param  array<int, string>  $row
     */
    private function summarise(array $mapping, array $row): string
    {
        $parts = [];

        foreach (array_keys($mapping) as $column) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value !== '') {
                $parts[] = $value;
            }

            if (count($parts) === 3) {
                break;
            }
        }

        return implode(', ', $parts);
    }

    private function fail(ImportRun $run, string $reason): ImportRun
    {
        $run->forceFill([
            'status' => ImportStatus::Failed->value,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ])->save();

        return $run->refresh();
    }
}
