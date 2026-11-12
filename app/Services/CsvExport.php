<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Builds CSV text that opens safely in Excel: UTF-8 BOM, CRLF lines, and cells that
 * start with = + - @ (or tab / CR) prefixed with an apostrophe so they are never
 * evaluated as formulas (CSV injection).
 */
final class CsvExport
{
    /**
     * @param list<string> $headers
     * @param iterable<list<scalar|null>> $rows
     */
    public static function build(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'cell'], $row), ',', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Neutralise a value that a spreadsheet would treat as a formula.
     */
    public static function cell(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;

        return ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $value : $value;
    }
}
