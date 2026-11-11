<?php

namespace App\Services\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Eksporter CSV generik untuk seluruh laporan administratif.
 *
 * Format disesuaikan agar langsung terbuka benar di Excel Indonesia:
 * delimiter `;`, header Bahasa Indonesia, dan BOM UTF-8.
 *
 * Dipakai slice ReportStock dan ReportLoans (satu file milik bersama,
 * dibuat slice ReportStock — JANGAN buat file kedua).
 */
class ReportCsvExporter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     */
    public static function export(string $filename, array $headers, iterable $rows, int $limit = 5000): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $limit): void {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 agar Excel menampilkan karakter Indonesia dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, ';');

            $count = 0;

            foreach ($rows as $row) {
                if ($count++ >= $limit) {
                    break;
                }

                $values = is_array($row) ? array_values($row) : [$row];

                fputcsv($handle, array_map(
                    static fn (mixed $value): string => $value === null ? '' : (string) $value,
                    $values
                ), ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
