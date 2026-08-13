<?php

namespace App\Http\Controllers;

use App\Support\Exports\BrandedCsvExportManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class Controller
{
    protected function csvExports(): BrandedCsvExportManager
    {
        return app(BrandedCsvExportManager::class);
    }

    protected function safeExportIdentifier(string $value, string $fallback): string
    {
        return $this->csvExports()->sanitizeIdentifier($value, $fallback);
    }

    protected function brandedCsvFilename(string $baseName): string
    {
        return $this->csvExports()->makeBrandedFilename($baseName);
    }

    protected function streamCsvExport(
        string $baseName,
        array $columns,
        callable $writeRows,
        string $schemaHeaderName,
        string $schemaVersion,
        array $extraHeaders = [],
    ): StreamedResponse {
        return $this->csvExports()->streamCsv(
            baseName: $baseName,
            columns: $columns,
            writeRows: $writeRows,
            schemaHeaderName: $schemaHeaderName,
            schemaVersion: $schemaVersion,
            extraHeaders: $extraHeaders,
        );
    }

    protected function streamAuditLogCsvExport(
        string $baseName,
        array $firstPage,
        callable $fetchPage,
        bool $throwOnMissingPage = false,
    ): StreamedResponse {
        return $this->csvExports()->streamPaginatedAuditLogs(
            baseName: $baseName,
            firstPage: $firstPage,
            fetchPage: $fetchPage,
            throwOnMissingPage: $throwOnMissingPage,
        );
    }

    protected function downloadStoredCsvExport(
        string $filePath,
        string $downloadName,
        string $schemaHeaderName,
        string $schemaVersion,
        array $extraHeaders = [],
    ): StreamedResponse {
        return $this->csvExports()->downloadStoredCsv(
            filePath: $filePath,
            downloadName: $downloadName,
            schemaHeaderName: $schemaHeaderName,
            schemaVersion: $schemaVersion,
            extraHeaders: $extraHeaders,
        );
    }
}
