<?php

namespace App\Support\Exports;

use App\Support\Branding\SystemBrandingManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandedCsvExportManager
{
    public const AUDIT_SCHEMA_VERSION = 'audit-log-csv.v1';

    public const AUDIT_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function __construct(
        private readonly SystemBrandingManager $branding,
    ) {}

    public function sanitizeIdentifier(string $value, string $fallback): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $value);

        return is_string($safe) && $safe !== '' ? $safe : $fallback;
    }

    public function makeBrandedFilename(string $baseName): string
    {
        $normalizedBaseName = trim($baseName, " \t\n\r\0\x0B._");

        if ($normalizedBaseName === '') {
            $normalizedBaseName = 'export';
        }

        return sprintf('%s_%s.csv', $this->brandSlug(), $normalizedBaseName);
    }

    public function streamCsv(
        string $baseName,
        array $columns,
        callable $writeRows,
        string $schemaHeaderName,
        string $schemaVersion,
        array $extraHeaders = [],
    ): StreamedResponse {
        $generatedAt = now()->toIso8601String();

        return response()->streamDownload(function () use ($columns, $writeRows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            try {
                fwrite($output, self::UTF8_BOM);
                fputcsv($output, $columns);
                $writeRows($output);
            } finally {
                fclose($output);
            }
        }, $this->makeBrandedFilename($baseName), $this->headers(
            schemaHeaderName: $schemaHeaderName,
            schemaVersion: $schemaVersion,
            generatedAt: $generatedAt,
            extraHeaders: $extraHeaders,
        ));
    }

    public function streamPaginatedAuditLogs(
        string $baseName,
        array $firstPage,
        callable $fetchPage,
        bool $throwOnMissingPage = false,
    ): StreamedResponse {
        return $this->streamCsv(
            baseName: $baseName,
            columns: self::AUDIT_COLUMNS,
            writeRows: function ($output) use ($firstPage, $fetchPage, $throwOnMissingPage): void {
                $this->writePaginatedAuditRows(
                    output: $output,
                    firstPage: $firstPage,
                    fetchPage: $fetchPage,
                    throwOnMissingPage: $throwOnMissingPage,
                );
            },
            schemaHeaderName: 'X-Audit-CSV-Schema-Version',
            schemaVersion: self::AUDIT_SCHEMA_VERSION,
        );
    }

    public function writePaginatedAuditFile(
        string $filePath,
        array $firstPage,
        callable $fetchPage,
        bool $throwOnMissingPage = true,
    ): int {
        $absolutePath = storage_path('app'.DIRECTORY_SEPARATOR.$filePath);
        File::ensureDirectoryExists(dirname($absolutePath));

        $stream = fopen($absolutePath, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open export file stream.');
        }

        try {
            fwrite($stream, self::UTF8_BOM);
            fputcsv($stream, self::AUDIT_COLUMNS);

            return $this->writePaginatedAuditRows(
                output: $stream,
                firstPage: $firstPage,
                fetchPage: $fetchPage,
                throwOnMissingPage: $throwOnMissingPage,
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<int, string>  $columns
     * @param  iterable<array<int, string>>  $rows  pre-built rows, in column order
     */
    public function writeFlatCsvFile(string $filePath, array $columns, iterable $rows): int
    {
        $absolutePath = Storage::disk('local')->path($filePath);
        File::ensureDirectoryExists(dirname($absolutePath));

        $stream = fopen($absolutePath, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open export file stream.');
        }

        try {
            fwrite($stream, self::UTF8_BOM);
            fputcsv($stream, $columns);

            $rowCount = 0;
            foreach ($rows as $row) {
                fputcsv($stream, $row);
                $rowCount++;
            }

            return $rowCount;
        } finally {
            fclose($stream);
        }
    }

    public function downloadStoredCsv(
        string $filePath,
        string $downloadName,
        string $schemaHeaderName,
        string $schemaVersion,
        array $extraHeaders = [],
    ): StreamedResponse {
        return Storage::disk('local')->download(
            $filePath,
            $downloadName,
            $this->headers(
                schemaHeaderName: $schemaHeaderName,
                schemaVersion: $schemaVersion,
                generatedAt: now()->toIso8601String(),
                extraHeaders: $extraHeaders,
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    public function auditRow(array $log): array
    {
        $actorId = $log['actor_id'] ?? null;

        return [
            (string) ($log['created_at'] ?? ''),
            (string) ($log['action'] ?? ''),
            $actorId === null ? 'system' : 'user',
            $actorId === null ? '' : (string) $actorId,
            $this->toJsonForCsv($log['changes'] ?? []),
            $this->toJsonForCsv($log['metadata'] ?? []),
        ];
    }

    private function writePaginatedAuditRows(
        mixed $output,
        array $firstPage,
        callable $fetchPage,
        bool $throwOnMissingPage,
    ): int {
        $rowCount = 0;
        $writeRows = function (array $rows) use ($output, &$rowCount): void {
            foreach ($rows as $log) {
                fputcsv($output, $this->auditRow($log));
                $rowCount++;
            }
        };

        $writeRows($firstPage['data'] ?? []);
        $lastPage = max((int) ($firstPage['meta']['lastPage'] ?? 1), 1);

        for ($page = 2; $page <= $lastPage; $page++) {
            $pageResult = $fetchPage($page);

            if ($pageResult === null) {
                if ($throwOnMissingPage) {
                    throw new RuntimeException('Unable to continue export: target resource no longer available.');
                }

                break;
            }

            $writeRows($pageResult['data'] ?? []);
        }

        return $rowCount;
    }

    /**
     * @return array<string, string>
     */
    private function headers(
        string $schemaHeaderName,
        string $schemaVersion,
        string $generatedAt,
        array $extraHeaders = [],
    ): array {
        return array_merge([
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            $schemaHeaderName => $schemaVersion,
            'X-Export-Format' => 'csv',
            'X-Export-Generated-At' => $generatedAt,
            'X-Export-System-Name' => $this->systemNameHeaderValue(),
            'X-Export-System-Slug' => $this->brandSlug(),
        ], $extraHeaders);
    }

    private function systemNameHeaderValue(): string
    {
        $systemName = preg_replace('/[\r\n]+/', ' ', $this->branding->systemName());
        $systemName = is_string($systemName) ? trim($systemName) : '';

        return $systemName !== '' ? $systemName : SystemBrandingManager::DEFAULT_SYSTEM_NAME;
    }

    private function brandSlug(): string
    {
        return Str::slug($this->branding->systemName(), '_') ?: 'system';
    }

    private function toJsonForCsv(mixed $value): string
    {
        $encoded = json_encode($value ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }
}
