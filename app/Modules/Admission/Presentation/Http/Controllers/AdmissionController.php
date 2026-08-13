<?php

namespace App\Modules\Admission\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admission\Application\Exceptions\AppointmentNotEligibleForAdmissionException;
use App\Modules\Admission\Application\Exceptions\InvalidAdmissionPlacementException;
use App\Modules\Admission\Application\Exceptions\PatientNotEligibleForAdmissionException;
use App\Modules\Admission\Application\UseCases\CreateAdmissionUseCase;
use App\Modules\Admission\Application\UseCases\GetAdmissionUseCase;
use App\Modules\Admission\Application\UseCases\ListAdmissionAuditLogsUseCase;
use App\Modules\Admission\Application\UseCases\ListAdmissionDischargeDestinationOptionsUseCase;
use App\Modules\Admission\Application\UseCases\ListAdmissionStatusCountsUseCase;
use App\Modules\Admission\Application\UseCases\ListAdmissionsUseCase;
use App\Modules\Admission\Application\UseCases\ListAvailableBedsUseCase;
use App\Modules\Admission\Application\UseCases\UpdateAdmissionStatusUseCase;
use App\Modules\Admission\Application\UseCases\UpdateAdmissionUseCase;
use App\Modules\Admission\Presentation\Http\Requests\StoreAdmissionRequest;
use App\Modules\Admission\Presentation\Http\Requests\UpdateAdmissionRequest;
use App\Modules\Admission\Presentation\Http\Requests\UpdateAdmissionStatusRequest;
use App\Modules\Admission\Presentation\Http\Transformers\AdmissionAuditLogResponseTransformer;
use App\Modules\Admission\Presentation\Http\Transformers\AdmissionResponseTransformer;
use App\Modules\Admission\Presentation\Http\Transformers\AvailableBedResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdmissionController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListAdmissionsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([AdmissionResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListAdmissionStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function dischargeDestinationOptions(ListAdmissionDischargeDestinationOptionsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute(),
        ]);
    }

    public function availableBeds(Request $request, ListAvailableBedsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([AvailableBedResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(StoreAdmissionRequest $request, CreateAdmissionUseCase $useCase): JsonResponse
    {
        try {
            $admission = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForAdmissionException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForAdmissionException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (InvalidAdmissionPlacementException $exception) {
            return $this->validationErrorResponse($exception->getMessage(), $exception->errors());
        }

        return response()->json([
            'data' => AdmissionResponseTransformer::transform($admission),
        ], 201);
    }

    public function show(string $id, GetAdmissionUseCase $useCase): JsonResponse
    {
        $admission = $useCase->execute($id);
        abort_if($admission === null, 404, 'Admission not found.');

        return response()->json([
            'data' => AdmissionResponseTransformer::transform($admission),
        ]);
    }

    public function update(string $id, UpdateAdmissionRequest $request, UpdateAdmissionUseCase $useCase): JsonResponse
    {
        try {
            $admission = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForAdmissionException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForAdmissionException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (InvalidAdmissionPlacementException $exception) {
            return $this->validationErrorResponse($exception->getMessage(), $exception->errors());
        }

        abort_if($admission === null, 404, 'Admission not found.');

        return response()->json([
            'data' => AdmissionResponseTransformer::transform($admission),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateAdmissionStatusRequest $request,
        UpdateAdmissionStatusUseCase $useCase
    ): JsonResponse {
        try {
            $admission = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                dischargeDestination: $request->input('dischargeDestination'),
                followUpPlan: $request->input('followUpPlan'),
                receivingWard: $request->input('receivingWard'),
                receivingBed: $request->input('receivingBed'),
                receivingBedResourceId: $request->input('receivingBedResourceId'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InvalidAdmissionPlacementException $exception) {
            return $this->validationErrorResponse($exception->getMessage(), $exception->errors());
        }

        abort_if($admission === null, 404, 'Admission not found.');

        return response()->json([
            'data' => AdmissionResponseTransformer::transform($admission),
        ]);
    }

    public function auditLogs(string $id, Request $request, ListAdmissionAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(admissionId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Admission not found.');

        return response()->json([
            'data' => array_map([AdmissionAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListAdmissionAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            admissionId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Admission not found.');

        $safeId = $this->safeExportIdentifier($id, 'admission');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('admission_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    admissionId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => [
                $field => [$message],
            ],
        ], 422);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationErrorResponse(string $message, array $errors): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => $errors,
        ], 422);
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'patientId' => 'patient_id',
            'appointmentId' => 'appointment_id',
            'attendingClinicianUserId' => 'attending_clinician_user_id',
            'ward' => 'ward',
            'bed' => 'bed',
            'bedResourceId' => 'bed_resource_id',
            'admittedAt' => 'admitted_at',
            'admissionReason' => 'admission_reason',
            'notes' => 'notes',
            'financialClass' => 'financial_coverage_type',
            'billingPayerContractId' => 'billing_payer_contract_id',
            'coverageReference' => 'coverage_reference',
            'coverageNotes' => 'coverage_notes',
        ];

        $payload = [];

        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }
}
