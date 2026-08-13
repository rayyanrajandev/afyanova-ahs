<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\UseCases\GetBillingInvoiceUseCase;
use App\Modules\ClaimsInsurance\Application\UseCases\GetClaimsInsuranceCaseUseCase;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseAuditLogRepositoryInterface;
use App\Modules\ClaimsInsurance\Presentation\Http\Transformers\ClaimsInsuranceCaseResponseTransformer;
use App\Support\Branding\SystemBrandingManager;
use App\Support\Documents\BrandedPdfDocumentManager;
use App\Support\Documents\DocumentAuditTrailManager;
use App\Support\Documents\DocumentContextLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsInsuranceDocumentController extends Controller
{
    public function show(
        string $id,
        GetClaimsInsuranceCaseUseCase $claimUseCase,
        GetBillingInvoiceUseCase $invoiceUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): Response {
        return Inertia::render('claims-insurance/Print', $this->buildPayload(
            id: $id,
            claimUseCase: $claimUseCase,
            invoiceUseCase: $invoiceUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        ));
    }

    public function downloadPdf(
        string $id,
        Request $request,
        GetClaimsInsuranceCaseUseCase $claimUseCase,
        GetBillingInvoiceUseCase $invoiceUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
        BrandedPdfDocumentManager $pdfDocumentManager,
        DocumentAuditTrailManager $documentAuditTrailManager,
        ClaimsInsuranceCaseAuditLogRepositoryInterface $auditLogRepository,
    ): HttpResponse {
        $payload = $this->buildPayload(
            id: $id,
            claimUseCase: $claimUseCase,
            invoiceUseCase: $invoiceUseCase,
            documentContextLookup: $documentContextLookup,
            brandingManager: $brandingManager,
        );
        $payload['documentBranding'] = $brandingManager->documentPdfBranding();

        $safeIdentifier = $pdfDocumentManager->sanitizeIdentifier(
            (string) ($payload['claim']['claimNumber'] ?? $id),
            'claim',
        );
        $filename = $pdfDocumentManager->makeBrandedFilename('claim_dossier_'.$safeIdentifier);

        $response = $pdfDocumentManager->downloadView(
            view: 'documents.claims-insurance',
            data: $payload,
            baseName: 'claim_dossier_'.$safeIdentifier,
            extraHeaders: [
                'X-Document-Source' => 'claims-insurance',
                'X-Document-Source-Id' => (string) ($payload['claim']['id'] ?? $id),
            ],
        );

        $documentAuditTrailManager->recordPdfDownload(
            request: $request,
            action: 'claims-insurance-case.document.pdf.downloaded',
            source: 'claims-insurance',
            sourceId: (string) ($payload['claim']['id'] ?? $id),
            filename: $filename,
            writer: static function (string $action, ?int $actorId, array $changes, array $metadata) use ($auditLogRepository, $payload, $id): void {
                $auditLogRepository->write(
                    claimsInsuranceCaseId: (string) ($payload['claim']['id'] ?? $id),
                    action: $action,
                    actorId: $actorId,
                    changes: $changes,
                    metadata: $metadata,
                );
            },
            extraMetadata: [
                'document_number' => $payload['claim']['claimNumber'] ?? null,
                'patient_id' => $payload['patient']['id'] ?? null,
                'invoice_id' => $payload['invoice']['id'] ?? null,
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
        GetClaimsInsuranceCaseUseCase $claimUseCase,
        GetBillingInvoiceUseCase $invoiceUseCase,
        DocumentContextLookup $documentContextLookup,
        SystemBrandingManager $brandingManager,
    ): array {
        $claim = $claimUseCase->execute($id);
        abort_if($claim === null, 404, 'Claims insurance case not found.');

        $invoiceId = is_string($claim['invoice_id'] ?? null)
            ? trim((string) $claim['invoice_id'])
            : '';
        $invoice = $invoiceId !== '' ? $invoiceUseCase->execute($invoiceId) : null;

        return [
            'claim' => ClaimsInsuranceCaseResponseTransformer::transform($claim),
            'invoice' => $this->invoiceSummary($invoice),
            'patient' => $documentContextLookup->patientSummary($claim['patient_id'] ?? null),
            'appointment' => $documentContextLookup->appointmentSummary($claim['appointment_id'] ?? null),
            'admission' => $documentContextLookup->admissionSummary($claim['admission_id'] ?? null),
            'followUpOwner' => $documentContextLookup->userSummary($claim['reconciliation_follow_up_updated_by_user_id'] ?? null),
            'documentBranding' => $brandingManager->documentBranding(),
            'generatedAt' => now()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $invoice
     * @return array<string, mixed>|null
     */
    private function invoiceSummary(?array $invoice): ?array
    {
        if ($invoice === null) {
            return null;
        }

        return [
            'id' => $invoice['id'] ?? null,
            'invoiceNumber' => $invoice['invoice_number'] ?? null,
            'invoiceDate' => $invoice['invoice_date'] ?? null,
            'currencyCode' => $invoice['currency_code'] ?? null,
            'totalAmount' => $invoice['total_amount'] ?? null,
            'paidAmount' => $invoice['paid_amount'] ?? null,
            'balanceAmount' => $invoice['balance_amount'] ?? null,
            'paymentDueAt' => $invoice['payment_due_at'] ?? null,
            'lastPaymentAt' => $invoice['last_payment_at'] ?? null,
            'lastPaymentReference' => $invoice['last_payment_reference'] ?? null,
            'pricingMode' => $invoice['pricing_mode'] ?? null,
            'status' => $invoice['status'] ?? null,
            'statusReason' => $invoice['status_reason'] ?? null,
            'lineItemCount' => is_array($invoice['line_items'] ?? null)
                ? count($invoice['line_items'])
                : 0,
        ];
    }
}
