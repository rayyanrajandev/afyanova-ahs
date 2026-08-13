<?php

namespace App\Modules\Pos\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Application\UseCases\GetPosRegisterSessionReportUseCase;
use App\Modules\Pos\Presentation\Http\Transformers\PosRegisterSessionResponseTransformer;
use App\Modules\Pos\Presentation\Http\Transformers\PosSaleResponseTransformer;
use App\Support\Branding\SystemBrandingManager;
use App\Support\Documents\BrandedPdfDocumentManager;
use App\Support\Documents\DocumentContextLookup;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class PosRegisterSessionDocumentController extends Controller
{
    public function show(
        string $id,
        GetPosRegisterSessionReportUseCase $reportUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('pos/SessionReport', $this->buildPayload(
            id: $id,
            reportUseCase: $reportUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadPdf(
        string $id,
        GetPosRegisterSessionReportUseCase $reportUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
    ): HttpResponse {
        $payload = $this->buildPayload(
            id: $id,
            reportUseCase: $reportUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($payload['session']['sessionNumber'] ?? $id),
            'pos_session_report',
        );

        return $pdfDocumentManager->downloadView(
            view: 'documents.pos-session-report',
            data: $payload,
            baseName: 'pos_session_report_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'pos-session-report',
                'X-Document-Source-Id' => (string) ($payload['session']['id'] ?? $id),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $id,
        GetPosRegisterSessionReportUseCase $reportUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): array {
        $report = $reportUseCase->execute($id);
        abort_if($report === null, 404, 'POS register session not found.');

        $session = PosRegisterSessionResponseTransformer::transform($report['session']);

        return [
            'session' => $session,
            'openedBy' => $documentContextLookup->userSummary($report['session']['opened_by_user_id'] ?? null),
            'closedBy' => $documentContextLookup->userSummary($report['session']['closed_by_user_id'] ?? null),
            'sales' => array_map([PosSaleResponseTransformer::class, 'transform'], $report['sales'] ?? []),
            'adjustments' => array_map([PosSaleResponseTransformer::class, 'transformAdjustment'], $report['adjustments'] ?? []),
            'channelBreakdown' => array_map(
                static fn (array $row): array => [
                    'saleChannel' => $row['sale_channel'] ?? null,
                    'saleCount' => $row['sale_count'] ?? 0,
                    'subtotalAmount' => $row['subtotal_amount'] ?? 0,
                    'discountAmount' => $row['discount_amount'] ?? 0,
                    'taxAmount' => $row['tax_amount'] ?? 0,
                    'totalAmount' => $row['total_amount'] ?? 0,
                    'paidAmount' => $row['paid_amount'] ?? 0,
                    'changeAmount' => $row['change_amount'] ?? 0,
                ],
                $report['channel_breakdown'] ?? [],
            ),
            'paymentBreakdown' => array_map(
                static fn (array $row): array => [
                    'paymentMethod' => $row['payment_method'] ?? null,
                    'paymentCount' => $row['payment_count'] ?? 0,
                    'amountReceived' => $row['amount_received'] ?? 0,
                    'amountApplied' => $row['amount_applied'] ?? 0,
                    'changeGiven' => $row['change_given'] ?? 0,
                ],
                $report['payment_breakdown'] ?? [],
            ),
            'documentBranding' => $brandingManager->documentBranding(),
            'generatedAt' => now()->toISOString(),
        ];
    }
}
