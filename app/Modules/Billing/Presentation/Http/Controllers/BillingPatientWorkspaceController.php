<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\UseCases\GetBillingPatientWorkspaceUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPatientAuditLogsUseCase;
use App\Modules\Billing\Application\UseCases\ListBillingPatientPaymentsUseCase;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoiceAuditLogResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingInvoicePaymentResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\BillingPatientWorkspaceResponseTransformer;
use Illuminate\Http\JsonResponse;

class BillingPatientWorkspaceController extends Controller
{
    public function __invoke(
        string $patientId,
        GetBillingPatientWorkspaceUseCase $useCase,
    ): JsonResponse {
        $workspace = $useCase->execute($patientId);

        abort_if($workspace === null, 404, 'Patient not found.');

        return response()->json([
            'data' => BillingPatientWorkspaceResponseTransformer::transform($workspace),
        ]);
    }

    public function payments(string $patientId, ListBillingPatientPaymentsUseCase $useCase): JsonResponse
    {
        $payments = $useCase->execute($patientId);

        abort_if($payments === null, 404, 'Patient not found.');

        return response()->json([
            'data' => array_map(static function (array $payment): array {
                return BillingInvoicePaymentResponseTransformer::transform($payment) + [
                    'invoiceNumber' => $payment['invoice_number'] ?? null,
                    'invoiceStatus' => $payment['invoice_status'] ?? null,
                    'currencyCode' => $payment['currency_code'] ?? null,
                ];
            }, $payments),
        ]);
    }

    public function auditLogs(string $patientId, ListBillingPatientAuditLogsUseCase $useCase): JsonResponse
    {
        $logs = $useCase->execute($patientId);

        abort_if($logs === null, 404, 'Patient not found.');

        return response()->json([
            'data' => array_map(static function (array $log): array {
                return BillingInvoiceAuditLogResponseTransformer::transform($log) + [
                    'invoiceNumber' => $log['invoice_number'] ?? null,
                ];
            }, $logs),
        ]);
    }
}
