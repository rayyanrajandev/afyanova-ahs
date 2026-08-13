<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Application\UseCases\VerifyPatientInsuranceRecordUseCase;
use App\Modules\Billing\Infrastructure\Integrations\BillingIntegrationService;
use App\Modules\Billing\Infrastructure\Models\BillingNhifVerificationModel;
use App\Modules\Billing\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingNhifVerificationController extends Controller
{
    public function __construct(
        private readonly BillingIntegrationService $integrationService,
        private readonly VerifyPatientInsuranceRecordUseCase $verifyUseCase,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function verifyMember(Request $request, string $memberId): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => 'nullable|string|uuid',
            'patient_insurance_record_id' => 'nullable|string|uuid',
        ]);

        $result = $this->integrationService->verifyNhifMember(
            memberId: $memberId,
            patientId: $validated['patient_id'] ?? null,
            insuranceRecordId: $validated['patient_insurance_record_id'] ?? null,
            userId: $request->user()?->id,
        );

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify NHIF member. Check member ID or try again later.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function verifyCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'card_number' => 'required|string|max:50',
            'patient_id' => 'nullable|string|uuid',
            'patient_insurance_record_id' => 'nullable|string|uuid',
        ]);

        $result = $this->integrationService->verifyNhifMember(
            memberId: $validated['card_number'],
            patientId: $validated['patient_id'] ?? null,
            insuranceRecordId: $validated['patient_insurance_record_id'] ?? null,
            userId: $request->user()?->id,
        );

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify NHIF card. Check card number or try again later.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function verificationHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => 'nullable|string',
            'patient_id' => 'nullable|string|uuid',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = BillingNhifVerificationModel::query()
            ->when($validated['member_id'] ?? null, fn ($q, $v) => $q->where('member_id', $v))
            ->when($validated['patient_id'] ?? null, fn ($q, $v) => $q->where('patient_id', $v))
            ->orderBy('created_at', 'desc');

        $perPage = $validated['per_page'] ?? 20;

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }
}
