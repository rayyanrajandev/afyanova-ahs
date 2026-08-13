<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\UseCases\GetBillingInvoiceUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingInvoicePaymentsUseCase;
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

class BillingInvoiceDocumentController extends Controller
{
    public function show(
        string $id,
        Request $request,
        GetBillingInvoiceUseCase $invoiceUseCase,
        ListBillingInvoicePaymentsUseCase $paymentsUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('billing/invoices/Print', $this->buildPayload(
            id: $id,
            request: $request,
            invoiceUseCase: $invoiceUseCase,
            paymentsUseCase: $paymentsUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadPdf(
        string $id,
        Request $request,
        GetBillingInvoiceUseCase $invoiceUseCase,
        ListBillingInvoicePaymentsUseCase $paymentsUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
        DocumentAuditTrailManager $documentAuditTrailManager,
        BillingInvoiceAuditLogRepositoryInterface $auditLogRepository,
    ): HttpResponse {
        $payload = $this->buildPayload(
            id: $id,
            request: $request,
            invoiceUseCase: $invoiceUseCase,
            paymentsUseCase: $paymentsUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($payload['invoice']['invoiceNumber'] ?? $id),
            'invoice',
        );
        $filename = $pdfDocumentManager->makeBrandedFilename('billing_invoice_'.$safeIdentifier);

        $response = $pdfDocumentManager->downloadView(
            view: 'documents.billing-invoice',
            data: $payload,
            baseName: 'billing_invoice_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'billing-invoice',
                'X-Document-Source-Id' => (string) ($payload['invoice']['id'] ?? $id),
            ],
        );

        $documentAuditTrailManager->recordPdfDownload(
            request: $request,
            action: 'billing-invoice.document.pdf.downloaded',
            source: 'billing-invoice',
            sourceId: (string) ($payload['invoice']['id'] ?? $id),
            filename: $filename,
            writer: static function (string $action, ?int $actorId, array $changes, array $metadata) use ($auditLogRepository, $payload, $id): void {
                $auditLogRepository->write(
                    billingInvoiceId: (string) ($payload['invoice']['id'] ?? $id),
                    action: $action,
                    actorId: $actorId,
                    changes: $changes,
                    metadata: $metadata,
                );
            },
            extraMetadata: [
                'document_number' => $payload['invoice']['invoiceNumber'] ?? null,
                'patient_id' => $payload['patient']['id'] ?? null,
                'appointment_id' => $payload['appointment']['id'] ?? null,
                'admission_id' => $payload['admission']['id'] ?? null,
            ],
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $id,
        Request $request,
        GetBillingInvoiceUseCase $invoiceUseCase,
        ListBillingInvoicePaymentsUseCase $paymentsUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): array {
        $invoice = $invoiceUseCase->execute($id);
        abort_if($invoice === null, 404, 'Billing invoice not found.');

        $canViewPaymentHistory = (bool) $request->user()?->can('billing.payments.view-history');
        $payments = [];

        if ($canViewPaymentHistory) {
            $paymentsResult = $paymentsUseCase->execute(
                billingInvoiceId: $id,
                filters: [
                    'page' => 1,
                    'perPage' => 100,
                ],
            );

            $payments = array_map(
                [BillingInvoicePaymentResponseTransformer::class, 'transform'],
                $paymentsResult['data'] ?? [],
            );
        }

        return [
            'invoice' => BillingInvoiceResponseTransformer::transform($invoice),
            'patient' => $documentContextLookup->patientSummary($invoice['patient_id'] ?? null),
            'appointment' => $documentContextLookup->appointmentSummary($invoice['appointment_id'] ?? null),
            'admission' => $documentContextLookup->admissionSummary($invoice['admission_id'] ?? null),
            'payer' => $documentContextLookup->billingPayerSummary($invoice['billing_payer_contract_id'] ?? null),
            'issuedBy' => $documentContextLookup->userSummary($invoice['issued_by_user_id'] ?? null),
            'payments' => $payments,
            'canViewPaymentHistory' => $canViewPaymentHistory,
            'documentBranding' => $brandingManager->documentBranding(),
            'generatedAt' => now()->toISOString(),
        ];
    }
}
