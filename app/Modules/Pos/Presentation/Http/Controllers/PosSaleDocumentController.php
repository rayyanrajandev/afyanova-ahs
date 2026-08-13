<?php

namespace App\Modules\Pos\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Application\UseCases\GetPosSaleUseCase;
use App\Modules\Pos\Presentation\Http\Transformers\PosSaleResponseTransformer;
use App\Support\Branding\SystemBrandingManager;
use App\Support\Documents\BrandedPdfDocumentManager;
use App\Support\Documents\DocumentContextLookup;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class PosSaleDocumentController extends Controller
{
    public function show(
        string $id,
        GetPosSaleUseCase $saleUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('pos/Print', $this->buildPayload(
            id: $id,
            saleUseCase: $saleUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadPdf(
        string $id,
        GetPosSaleUseCase $saleUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
    ): HttpResponse {
        $payload = $this->buildPayload(
            id: $id,
            saleUseCase: $saleUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($payload['sale']['receiptNumber'] ?? $payload['sale']['saleNumber'] ?? $id),
            'pos_receipt',
        );

        return $pdfDocumentManager->downloadView(
            view: 'documents.pos-sale',
            data: $payload,
            baseName: 'pos_receipt_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'pos-sale',
                'X-Document-Source-Id' => (string) ($payload['sale']['id'] ?? $id),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $id,
        GetPosSaleUseCase $saleUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): array {
        $sale = $saleUseCase->execute($id);
        abort_if($sale === null, 404, 'POS sale not found.');

        return [
            'sale' => PosSaleResponseTransformer::transform($sale),
            'patient' => $documentContextLookup->patientSummary($sale['patient_id'] ?? null),
            'completedBy' => $documentContextLookup->userSummary($sale['completed_by_user_id'] ?? null),
            'documentBranding' => $brandingManager->documentBranding(),
            'generatedAt' => now()->toISOString(),
        ];
    }
}
