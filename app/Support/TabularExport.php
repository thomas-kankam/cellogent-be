<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Writes rows to a temp CSV/XLSX file (constant memory) and returns it as a download.
 */
final class TabularExport
{
    public const FORMATS = ['csv', 'xlsx'];

    protected const XLSX_MAX_ROWS = 1_048_575;

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int|string, mixed>>  $rows
     */
    public static function download(string $name, array $headers, iterable $rows, string $format = 'csv'): BinaryFileResponse
    {
        $format = in_array($format, self::FORMATS, true) ? $format : 'csv';
        $directory = storage_path('app/tmp');

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.uniqid('export_', true).'.'.$format;
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));

        $written = 0;

        foreach ($rows as $row) {
            if ($format === 'xlsx' && $written >= self::XLSX_MAX_ROWS) {
                break;
            }

            $writer->addRow(Row::fromValues(array_map(fn ($v) => self::cell($v), array_values($row))));
            $written++;
        }

        $writer->close();

        $filename = $name.'-'.now()->format('Ymd-His').'.'.$format;

        return response()->download($path, $filename, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv',
        ])->deleteFileAfterSend();
    }

    protected static function cell(mixed $value): mixed
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => json_encode($value),
            is_int($value), is_float($value) => $value,
            is_numeric($value) && ! str_starts_with((string) $value, '0') && strlen((string) $value) < 15 => $value + 0,
            default => (string) $value,
        };
    }
}
