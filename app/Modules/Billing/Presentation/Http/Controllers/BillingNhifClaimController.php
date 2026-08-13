<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Infrastructure\Integrations\NHIF\NhifClaimSubmission;
use App\Modules\Billing\Infrastructure\Models\BillingNhifClaimSubmissionModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingNhifClaimController extends Controller
{
    public function __construct(
        private readonly NhifClaimSubmission $claimSubmission,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function submitClaim(Request $request, string $caseId): JsonResponse
    {
        $validated = $request->validate([
            'member_number' => 'required|string|max:50',
            'authorization_number' => 'required|string|max:50',
            'claim_reference' => 'nullable|string|max:100',
        ]);

        $case = ClaimsInsuranceCaseModel::query()
            ->where('id', $caseId)
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->first();

        if (!$case) {
            return response()->json(['message' => 'Claim case not found'], 404);
        }

        $invoice = BillingInvoiceModel::query()
            ->where('id', $case->invoice_id)
            ->first();

        if (!$invoice) {
            return response()->json(['message' => 'No invoice linked to this claim case'], 422);
        }

        $claimItems = [];
        foreach ($invoice->lineItems ?? [] as $line) {
            $claimItems[] = [
                'serviceCode' => $line['service_code'] ?? $line['code'] ?? '',
                'serviceName' => $line['service_name'] ?? $line['name'] ?? '',
                'quantity' => (int) ($line['quantity'] ?? 1),
                'unitPrice' => (float) ($line['unit_price'] ?? 0),
                'total' => (float) ($line['total'] ?? 0),
            ];
        }

        $totalAmount = array_sum(array_column($claimItems, 'total'));

        $existing = BillingNhifClaimSubmissionModel::query()
            ->where('claims_insurance_case_id', $case->id)
            ->whereIn('submission_status', ['draft', 'submitted'])
            ->latest()
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Claim already submitted',
                'data' => $existing,
            ], 409);
        }

        $result = $this->claimSubmission->submitClaim(
            memberNumber: $validated['member_number'],
            authorizationNumber: $validated['authorization_number'],
            claimItems: $claimItems,
            totalAmount: $totalAmount,
            claimReference: $validated['claim_reference'] ?? null,
        );

        $submission = BillingNhifClaimSubmissionModel::create([
            'tenant_id' => $this->scopeContext->tenantId(),
            'facility_id' => $this->scopeContext->facilityId(),
            'claims_insurance_case_id' => $case->id,
            'billing_invoice_id' => $invoice->id,
            'nhif_claim_reference' => $result->claimReference,
            'submission_status' => $result->submissionStatus ?? ($result->success ? 'submitted' : 'failed'),
            'submitted_amount' => $totalAmount,
            'claim_payload' => $result->rawPayload,
            'response_payload' => $result->rawResponse,
            'error_message' => $result->success ? null : $result->message,
            'submitted_at' => $result->success ? now() : null,
        ]);

        if ($result->success) {
            $case->update([
                'submitted_at' => now(),
            ]);
        }

        $statusCode = $result->success ? 200 : 422;

        return response()->json([
            'success' => $result->success,
            'message' => $result->message,
            'data' => $submission,
        ], $statusCode);
    }

    public function checkStatus(Request $request, string $submissionId): JsonResponse
    {
        $submission = BillingNhifClaimSubmissionModel::query()
            ->where('id', $submissionId)
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->first();

        if (!$submission) {
            return response()->json(['message' => 'Submission not found'], 404);
        }

        if (!$submission->nhif_claim_reference) {
            return response()->json(['message' => 'No NHIF claim reference available'], 422);
        }

        $result = $this->claimSubmission->checkClaimStatus($submission->nhif_claim_reference);

        if ($result->success && $result->submissionStatus) {
            $submission->update([
                'submission_status' => $result->submissionStatus,
                'acknowledged_at' => now(),
                'response_payload' => $result->rawResponse,
            ]);
        }

        return response()->json([
            'success' => $result->success,
            'message' => $result->message,
            'data' => [
                'submission' => $submission->fresh(),
                'remote_status' => $result->submissionStatus,
            ],
        ]);
    }

    public function submissionHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'submission_status' => 'nullable|string|in:draft,submitted,acknowledged,rejected,failed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = BillingNhifClaimSubmissionModel::query()
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId());

        if (!empty($validated['submission_status'])) {
            $query->where('submission_status', $validated['submission_status']);
        }

        $perPage = min((int) ($validated['per_page'] ?? 20), 100);

        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate($perPage),
        ]);
    }
}
