<?php

namespace App\Support\Documents;

use Illuminate\Http\Request;

class DocumentAuditTrailManager
{
    /**
     * @param  callable(string, ?int, array, array): void  $writer
     * @param  array<string, mixed>  $extraMetadata
     */
    public function recordPdfDownload(
        Request $request,
        string $action,
        string $source,
        string $sourceId,
        string $filename,
        callable $writer,
        array $extraMetadata = [],
    ): void {
        $metadata = array_merge(
            $this->basePdfMetadata(
                request: $request,
                source: $source,
                sourceId: $sourceId,
                filename: $filename,
            ),
            $extraMetadata,
        );

        $writer(
            $action,
            $request->user()?->id,
            [],
            $this->filterMetadata($metadata),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function basePdfMetadata(
        Request $request,
        string $source,
        string $sourceId,
        string $filename,
    ): array {
        return [
            'document_format' => 'pdf',
            'document_delivery' => 'download',
            'document_schema_version' => BrandedPdfDocumentManager::DOCUMENT_SCHEMA_VERSION,
            'document_source' => $source,
            'document_source_id' => $sourceId,
            'document_filename' => $filename,
            'generated_at' => now()->toIso8601String(),
            'route_name' => $request->route()?->getName(),
            'request_method' => $request->method(),
            'request_path' => '/'.ltrim($request->path(), '/'),
            'request_ip' => $this->normalizeString($request->ip()),
            'user_agent' => $this->normalizeString($request->userAgent()),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function filterMetadata(array $metadata): array
    {
        return array_filter(
            $metadata,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }

    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', trim($value));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }
}
