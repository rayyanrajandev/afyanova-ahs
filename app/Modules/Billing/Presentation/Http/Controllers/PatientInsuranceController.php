<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\Exceptions\DuplicatePatientInsuranceMemberException;
use App\Modules\Billing\Application\UseCases\CreatePatientInsuranceRecordUseCase;
use App\Modules\Billing\Application\UseCases\DeletePatientInsuranceRecordUseCase;
use App\Modules\Billing\Application\UseCases\ListPatientInsuranceAuditEventsUseCase;
use App\Modules\Billing\Application\UseCases\ListPatientInsuranceOptionsUseCase;
use App\Modules\Billing\Application\UseCases\ListPatientInsuranceRecordsUseCase;
use App\Modules\Billing\Application\UseCases\UpdatePatientInsuranceRecordUseCase;
use App\Modules\Billing\Application\UseCases\VerifyPatientInsuranceRecordUseCase;
use App\Modules\Billing\Presentation\Http\Requests\StorePatientInsuranceRecordRequest;
use App\Modules\Billing\Presentation\Http\Requests\UpdatePatientInsuranceRecordRequest;
use App\Modules\Billing\Presentation\Http\Requests\VerifyPatientInsuranceRecordRequest;
use App\Modules\Billing\Presentation\Http\Transformers\PatientInsuranceAuditEventResponseTransformer;
use App\Modules\Billing\Presentation\Http\Transformers\PatientInsuranceRecordResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientInsuranceController extends Controller
{
    public function index(string $patientId, ListPatientInsuranceRecordsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                [PatientInsuranceRecordResponseTransformer::class, 'transform'],
                $useCase->execute($patientId),
            ),
        ]);
    }

    public function options(Request $request, ListPatientInsuranceOptionsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute($request->all()),
        ]);
    }

    public function store(
        string $patientId,
        StorePatientInsuranceRecordRequest $request,
        CreatePatientInsuranceRecordUseCase $useCase,
    ): JsonResponse {
        try {
            $record = $useCase->execute(
                patientId: $patientId,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (DuplicatePatientInsuranceMemberException $exception) {
            return $this->duplicateMemberIdResponse($exception);
        }

        return response()->json([
            'data' => PatientInsuranceRecordResponseTransformer::transform($record),
        ], 201);
    }

    public function update(
        string $patientId,
        string $recordId,
        UpdatePatientInsuranceRecordRequest $request,
        UpdatePatientInsuranceRecordUseCase $useCase,
    ): JsonResponse {
        try {
            $record = $useCase->execute(
                patientId: $patientId,
                recordId: $recordId,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (DuplicatePatientInsuranceMemberException $exception) {
            return $this->duplicateMemberIdResponse($exception);
        }

        abort_if($record === null, 404, 'Patient insurance record not found.');

        return response()->json([
            'data' => PatientInsuranceRecordResponseTransformer::transform($record),
        ]);
    }

    public function verify(
        string $patientId,
        string $recordId,
        VerifyPatientInsuranceRecordRequest $request,
        VerifyPatientInsuranceRecordUseCase $useCase,
    ): JsonResponse {
        try {
            $record = $useCase->execute(
                patientId: $patientId,
                recordId: $recordId,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        abort_if($record === null, 404, 'Patient insurance record not found.');

        return response()->json([
            'data' => PatientInsuranceRecordResponseTransformer::transform($record),
        ]);
    }

    public function destroy(
        string $patientId,
        string $recordId,
        Request $request,
        DeletePatientInsuranceRecordUseCase $useCase,
    ): JsonResponse {
        try {
            $deleted = $useCase->execute($patientId, $recordId, $request->user()?->id);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        abort_if(! $deleted, 404, 'Patient insurance record not found.');

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function auditEvents(
        string $patientId,
        Request $request,
        ListPatientInsuranceAuditEventsUseCase $useCase,
    ): JsonResponse {
        $result = $useCase->execute($patientId, $request->all());

        return response()->json([
            'data' => array_map([PatientInsuranceAuditEventResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function toPersistencePayload(array $validated): array
    {
        $map = [
            'billingPayerContractId' => 'billing_payer_contract_id',
            'insuranceType' => 'insurance_type',
            'insuranceProvider' => 'insurance_provider',
            'providerCode' => 'provider_code',
            'planName' => 'plan_name',
            'policyNumber' => 'policy_number',
            'memberId' => 'member_id',
            'principalMemberName' => 'principal_member_name',
            'relationshipToPrincipal' => 'relationship_to_principal',
            'cardNumber' => 'card_number',
            'effectiveDate' => 'effective_date',
            'expiryDate' => 'expiry_date',
            'coverageLevel' => 'coverage_level',
            'copayPercent' => 'copay_percent',
            'coverageLimitAmount' => 'coverage_limit_amount',
            'verificationStatus' => 'verification_status',
            'verificationDate' => 'verification_date',
            'verificationSource' => 'verification_source',
            'verificationReference' => 'verification_reference',
            'lastVerifiedAt' => 'last_verified_at',
        ];

        foreach ($map as $inputKey => $databaseKey) {
            if (! array_key_exists($inputKey, $validated)) {
                continue;
            }

            $validated[$databaseKey] = $validated[$inputKey];
            unset($validated[$inputKey]);
        }

        return $validated;
    }

    private function duplicateMemberIdResponse(DuplicatePatientInsuranceMemberException $exception): JsonResponse
    {
        return response()->json([
            'message' => 'Another active patient insurance record already uses this member ID.',
            'duplicates' => array_map(
                static fn (array $record): array => [
                    'id' => $record['id'] ?? null,
                    'patientId' => $record['patient_id'] ?? null,
                    'memberId' => $record['member_id'] ?? null,
                    'insuranceProvider' => $record['insurance_provider'] ?? null,
                    'providerCode' => $record['provider_code'] ?? null,
                    'status' => $record['status'] ?? null,
                ],
                $exception->getDuplicates(),
            ),
        ], 409);
    }
}
