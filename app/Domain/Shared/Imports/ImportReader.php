<?php

namespace App\Domain\Shared\Imports;

use Generator;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Reads headers and rows out of an uploaded file.
 *
 * CSV is streamed a line at a time: it is the format people actually import,
 * and a spreadsheet library that loads the whole sheet into memory is how a
 * "large file queues" feature still runs out of memory. XLSX has no streaming
 * equivalent here, so it is read whole and capped.
 */
class ImportReader
{
    /**
     * How many rows an XLSX may carry. Beyond this the file is refused rather
     * than silently truncated, with a message saying to use CSV.
     */
    public const SPREADSHEET_ROW_LIMIT = 20000;

    /**
     * The extensions an upload may have.
     *
     * @return array<int, string>
     */
    public static function allowedExtensions(): array
    {
        return ['csv', 'txt', 'xlsx', 'xls'];
    }

    /**
     * The first row of the file, which is taken to be the headings.
     *
     * @return array<int, string>
     */
    public function headers(string $path): array
    {
        foreach ($this->rows($path) as $row) {
            return array_map(fn (mixed $cell) => trim((string) $cell), $row);
        }

        return [];
    }

    /**
     * Every row including the heading row, so callers can decide what to skip.
     *
     * @return Generator<int, array<int, string>>
     */
    public function rows(string $path): Generator
    {
        $full = $this->absolutePath($path);

        yield from $this->isSpreadsheet($path)
            ? $this->spreadsheetRows($full)
            : $this->csvRows($full);
    }

    /**
     * Rows after the heading row, numbered as a person reading the file in a
     * spreadsheet would number them — so an error report says "row 7" and
     * row 7 is the one to look at.
     *
     * @return Generator<int, array<int, string>>
     */
    public function dataRows(string $path): Generator
    {
        // Counted from one so the headings are row 1 and the first record is
        // row 2 — the numbering a person sees with the file open.
        $number = 0;

        foreach ($this->rows($path) as $row) {
            $number++;

            if ($number === 1 || $this->isBlank($row)) {
                continue;
            }

            yield $number => $row;
        }
    }

    public function countDataRows(string $path): int
    {
        $count = 0;

        foreach ($this->dataRows($path) as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Generator<int, array<int, string>>
     */
    private function csvRows(string $path): Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('That file could not be opened.');
        }

        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                // fgetcsv gives [null] for a blank line.
                yield array_map(fn (mixed $cell) => (string) $cell, $row === [null] ? [] : $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array<int, string>>
     */
    private function spreadsheetRows(string $path): Generator
    {
        $sheets = Excel::toArray(new class {}, $path);
        $rows = $sheets[0] ?? [];

        if (count($rows) > self::SPREADSHEET_ROW_LIMIT) {
            throw new RuntimeException(
                'That spreadsheet has more than '.number_format(self::SPREADSHEET_ROW_LIMIT)
                .' rows. Save it as CSV and import that instead.'
            );
        }

        foreach ($rows as $row) {
            yield array_map(fn (mixed $cell) => (string) $cell, array_values((array) $row));
        }
    }

    private function isSpreadsheet(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true);
    }

    private function absolutePath(string $path): string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new RuntimeException('That file is no longer there. Upload it again.');
        }

        return $disk->path($path);
    }
}
