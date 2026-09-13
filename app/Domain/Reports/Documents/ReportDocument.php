<?php

namespace App\Domain\Reports\Documents;

use App\Domain\Company\Models\Company;
use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRunner;
use App\Domain\Shared\Enums\ExportFormat;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A report as a file somebody can be sent.
 *
 * The report is run **as the person the schedule belongs to**, never as
 * "everybody": a file that leaves the application is the hardest kind of leak
 * to take back, and an unscoped aggregate in an attachment is one nobody would
 * notice until it was quoted in a meeting.
 *
 * The PDF carries the chart as well as the table, which is why the charts in
 * this application are server-side SVG rather than a JavaScript library —
 * dompdf cannot run JavaScript, and a scheduled PDF with a blank space where
 * the chart should be is the usual outcome of the other choice.
 */
class ReportDocument
{
    public function __construct(private readonly ReportRunner $runner) {}

    /**
     * The file's bytes.
     */
    public function render(Report $report, User $viewer, ExportFormat $format): string
    {
        $result = $this->runner->run($report->definition(), $viewer);

        return match ($format) {
            ExportFormat::Pdf => $this->pdf($report, $result),
            ExportFormat::Csv => $this->csv($result),
            ExportFormat::Excel => $this->spreadsheet($result),
        };
    }

    /**
     * What to call the file: the report's name and the date, so a folder of
     * them sorts and reads sensibly.
     */
    public function filename(Report $report, ExportFormat $format, ?Carbon $at = null): string
    {
        $slug = Str::slug($report->name);

        if ($slug === '') {
            $slug = 'report';
        }

        return $slug.'-'.($at ?? now())->format('Y-m-d').'.'.$format->extension();
    }

    private function pdf(Report $report, ReportResult $result): string
    {
        return Pdf::loadView('pdf.report', [
            'report' => $report,
            'result' => $result,
            'company' => Company::current(),
            'chart' => ChartType::tryFrom($report->chart_type) ?? ChartType::Table,
            'generatedAt' => now(),
        ])
            // Landscape: a report is wider than it is tall, and a portrait page
            // squeezes a six-column table into something nobody can read.
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * CSV built here rather than through maatwebsite/excel.
     *
     * The export kit writes *records* through a source; a report is already
     * shaped rows with a header and a totals line, and routing it through a
     * second abstraction to get the same bytes would be indirection for its
     * own sake.
     */
    private function csv(ReportResult $result): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_values($result->columns()));

        foreach ($result->rows as $row) {
            fputcsv($handle, $this->line($result, $row->toArray()));
        }

        if ($result->measures !== []) {
            fputcsv($handle, $this->totalsLine($result));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * A spreadsheet, as SpreadsheetML that Excel opens natively.
     *
     * Written directly for the same reason the CSV is: the rows are already
     * shaped. It is real XML rather than a CSV with a misleading extension —
     * Excel warns about those, and a warning on an automated report is a
     * support call every month.
     */
    private function spreadsheet(ReportResult $result): string
    {
        $rows = [array_values($result->columns())];

        foreach ($result->rows as $row) {
            $rows[] = $this->line($result, $row->toArray());
        }

        if ($result->measures !== []) {
            $rows[] = $this->totalsLine($result);
        }

        $xml = '<?xml version="1.0"?>'."\n"
            .'<?mso-application progid="Excel.Sheet"?>'."\n"
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            .'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n"
            .'<Worksheet ss:Name="Report"><Table>'."\n";

        foreach ($rows as $row) {
            $xml .= '<Row>';

            foreach ($row as $cell) {
                // A blank is text, not a zero: is_numeric('') is already
                // false, so the emptiness check the eye wants is redundant.
                $type = is_numeric($cell) ? 'Number' : 'String';
                $xml .= '<Cell><Data ss:Type="'.$type.'">'.htmlspecialchars((string) $cell, ENT_XML1).'</Data></Cell>';
            }

            $xml .= '</Row>'."\n";
        }

        return $xml.'</Table></Worksheet></Workbook>';
    }

    /**
     * One row, in the column order the header used.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    private function line(ReportResult $result, array $values): array
    {
        $line = [];

        foreach (array_keys($result->columns()) as $key) {
            $value = $values[$key] ?? null;
            // An empty cell rather than the word "null": a spreadsheet reader
            // treats a blank as missing and the word as text.
            $line[] = $value === null ? '' : (string) $value;
        }

        return $line;
    }

    /**
     * @return array<int, string>
     */
    private function totalsLine(ReportResult $result): array
    {
        $line = [];

        foreach ($result->dimensions as $index => $dimension) {
            $line[] = $index === array_key_first($result->dimensions) ? 'Everything' : '';
        }

        foreach (array_keys($result->measures) as $key) {
            $total = $result->totals[$key] ?? null;
            $line[] = $total === null ? '' : (string) $total;
        }

        return $line;
    }
}
