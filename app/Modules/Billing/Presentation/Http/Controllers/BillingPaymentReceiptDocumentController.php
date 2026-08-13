<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\UseCases\GetBillingInvoicePaymentUseCase;
use App\Modules\Billing\Application\UseCases\GetBillingInvoiceUseCase;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceAuditLogRepositoryInterface;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoicePaymentResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoiceResponseTransformer;
use App\Support\Branding\SystemBrandingManager;
use App\Support\Documents\BrandedPdfDocumentManager;
use App\Support\Documents\DocumentAuditTrailManager;
use App\Support\Documents\DocumentContextLookup;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mirrors BillingInvoiceDocumentController's show/downloadPdf split exactly —
 * same DocumentContextLookup/SystemBrandingManager/BrandedPdfDocumentManager/
 * DocumentAuditTrailManager pieces, just keyed on a single payment ledger
 * entry instead of the whole invoice. Only 'payment' entries get a receipt —
 * a reversal isn't a receipt-worthy event (there's no analogous "money back"
 * document in this system yet).
 */
class BillingPaymentReceiptDocumentController extends Controller
{
    public function show(
        string $invoiceId,
        string $paymentId,
        Request $request,
        GetBillingInvoiceUseCase $invoiceUseCase,
        GetBillingInvoicePaymentUseCase $paymentUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('billing/invoices/Receipt', $this->buildPayload(
            invoiceId: $invoiceId,
            paymentId: $paymentId,
            invoiceUseCase: $invoiceUseCase,
            paymentUseCase: $paymentUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadPdf(
        string $invoiceId,
        string $paymentId,
        Request $request,
        GetBillingInvoiceUseCase $invoiceUseCase,
        GetBillingInvoicePaymentUseCase $paymentUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
        DocumentAuditTrailManager $documentAuditTrailManager,
        BillingInvoiceAuditLogRepositoryInterface $auditLogRepository,
    ): HttpResponse {
        $payload = $this->buildPayload(
            invoiceId: $invoiceId,
            paymentId: $paymentId,
            invoiceUseCase: $invoiceUseCase,
            paymentUseCase: $paymentUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($payload['invoice']['invoiceNumber'] ?? $invoiceId),
            'receipt',
        );
        $filename = $pdfDocumentManager->makeBrandedFilename('payment_receipt_'.$safeIdentifier);

        $response = $pdfDocumentManager->downloadView(
            view: 'documents.billing-payment-receipt',
            data: $payload,
            baseName: 'payment_receipt_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'billing-payment-receipt',
                'X-Document-Source-Id' => (string) ($payload['payment']['id'] ?? $paymentId),
            ],
        );

        $documentAuditTrailManager->recordPdfDownload(
            request: $request,
            action: 'billing-invoice.payment.receipt.pdf.downloaded',
            source: 'billing-payment-receipt',
            sourceId: (string) ($payload['payment']['id'] ?? $paymentId),
            filename: $filename,
            writer: static function (string $action, ?int $actorId, array $changes, array $metadata) use ($auditLogRepository, $invoiceId): void {
                $auditLogRepository->write(
                    billingInvoiceId: $invoiceId,
                    action: $action,
                    actorId: $actorId,
                    changes: $changes,
                    metadata: $metadata,
                );
            },
            extraMetadata: [
                'document_number' => $payload['invoice']['invoiceNumber'] ?? null,
                'patient_id' => $payload['patient']['id'] ?? null,
                'payment_id' => $payload['payment']['id'] ?? null,
            ],
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $invoiceId,
        string $paymentId,
        GetBillingInvoiceUseCase $invoiceUseCase,
        GetBillingInvoicePaymentUseCase $paymentUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): array {
        $invoice = $invoiceUseCase->execute($invoiceId);
        abort_if($invoice === null, 404, 'Billing invoice not found.');

        $payment = $paymentUseCase->execute($invoiceId, $paymentId);
        abort_if($payment === null, 404, 'Payment not found.');
        abort_if(($payment['entry_type'] ?? 'payment') !== 'payment', 404, 'Reversal entries do not have a receipt.');

        return [
            'invoice' => BillingInvoiceResponseTransformer::transform($invoice),
            'payment' => BillingInvoicePaymentResponseTransformer::transform($payment),
            'patient' => $documentContextLookup->patientSummary($invoice['patient_id'] ?? null),
            'recordedBy' => $documentContextLookup->userSummary($payment['recorded_by_user_id'] ?? null),
            'documentBranding' => $brandingManager->documentBranding(),
            'generatedAt' => now()->toISOString(),
        ];
    }
}
