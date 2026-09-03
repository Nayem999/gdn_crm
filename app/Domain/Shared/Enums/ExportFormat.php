<?php

namespace App\Domain\Shared\Enums;

use Maatwebsite\Excel\Excel;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Excel = 'xlsx';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Excel => 'Excel',
            self::Pdf => 'PDF',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Csv => 'file-text',
            self::Excel => 'file-spreadsheet',
            self::Pdf => 'file-type-2',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    /**
     * The writer maatwebsite/excel should use for this format.
     */
    public function writerType(): string
    {
        return match ($this) {
            self::Csv => Excel::CSV,
            self::Excel => Excel::XLSX,
            self::Pdf => Excel::DOMPDF,
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Excel => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }
}
