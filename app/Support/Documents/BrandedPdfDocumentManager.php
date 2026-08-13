<?php

namespace App\Support\Documents;

use App\Support\Branding\SystemBrandingManager;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BrandedPdfDocumentManager
{
    public const DOCUMENT_SCHEMA_VERSION = 'document-pdf.v1';

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
            $normalizedBaseName = 'document';
        }

        return sprintf('%s_%s.pdf', $this->brandSlug(), $normalizedBaseName);
    }

    public function downloadView(
        string $view,
        array $data,
        string $baseName,
        array $extraHeaders = [],
        string $paper = 'a4',
        string $orientation = 'portrait',
    ): Response {
        $generatedAt = now()->toIso8601String();
        $filename = $this->makeBrandedFilename($baseName);

        $content = Pdf::setOption([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'dpi' => 96,
        ])
            ->loadView($view, $data)
            ->setPaper($paper, $orientation)
            ->output();

        return response($content, 200, array_merge([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Document-Format' => 'pdf',
            'X-Document-Schema-Version' => self::DOCUMENT_SCHEMA_VERSION,
            'X-Document-Generated-At' => $generatedAt,
            'X-Document-System-Name' => $this->systemNameHeaderValue(),
            'X-Document-System-Slug' => $this->brandSlug(),
        ], $extraHeaders));
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
}
