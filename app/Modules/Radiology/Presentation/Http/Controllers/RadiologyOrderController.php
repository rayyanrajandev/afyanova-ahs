<?php

namespace App\Modules\Radiology\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Radiology\Application\UseCases\ApplyRadiologyOrderLifecycleActionUseCase;
use App\Modules\Radiology\Application\UseCases\CheckRadiologyOrderDuplicatesUseCase;
use App\Modules\Radiology\Application\Exceptions\AdmissionNotEligibleForRadiologyOrderException;
use App\Modules\Radiology\Application\Exceptions\AppointmentNotEligibleForRadiologyOrderException;
use App\Modules\Radiology\Application\Exceptions\PatientNotEligibleForRadiologyOrderException;
use App\Modules\Radiology\Application\Exceptions\RadiologyOrderVerificationNotAllowedException;
use App\Modules\Radiology\Application\Exceptions\RadiologyOrderProcedureCatalogItemNotEligibleException;
use App\Modules\Radiology\Application\UseCases\CreateRadiologyOrderUseCase;
use App\Modules\Radiology\Application\UseCases\DiscardRadiologyOrderDraftUseCase;
use App\Modules\Radiology\Application\UseCases\GetRadiologyOrderUseCase;
use App\Modules\Radiology\Application\UseCases\ListRadiologyOrderAuditLogsUseCase;
use App\Modules\Radiology\Application\UseCases\ListRadiologyOrdersUseCase;
use App\Modules\Radiology\Application\UseCases\ListRadiologyOrderStatusCountsUseCase;
use App\Modules\Radiology\Application\UseCases\SignRadiologyOrderUseCase;
use App\Modules\Radiology\Application\UseCases\UpdateRadiologyOrderStatusUseCase;
use App\Modules\Radiology\Application\UseCases\VerifyRadiologyOrderResultUseCase;
use App\Modules\Radiology\Application\UseCases\UpdateRadiologyOrderUseCase;
use App\Modules\Radiology\Presentation\Http\Requests\StoreRadiologyOrderRequest;
use App\Modules\Radiology\Presentation\Http\Requests\UpdateRadiologyOrderRequest;
use App\Modules\Radiology\Presentation\Http\Requests\UpdateRadiologyOrderStatusRequest;
use App\Modules\Radiology\Presentation\Http\Requests\VerifyRadiologyOrderResultRequest;
use App\Modules\Radiology\Presentation\Http\Transformers\RadiologyOrderAuditLogResponseTransformer;
use App\Modules\Radiology\Presentation\Http\Transformers\RadiologyOrderResponseTransformer;
use App\Support\ClinicalOrders\ClinicalOrderPatientSummaryEnricher;
use App\Support\ClinicalOrders\ClinicalOrderUserSummaryEnricher;
use App\Support\ClinicalOrders\ClinicalOrderVisitStageEnricher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RadiologyOrderController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListRadiologyOrdersUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        $orders = ClinicalOrderPatientSummaryEnricher::attachToTransformedOrders(
            $result['data'],
            array_map([RadiologyOrderResponseTransformer::class, 'transform'], $result['data']),
        );

        // Same as the laboratory worklist: a radiographer needs to know whether
        // this patient is still with a doctor, already waiting at imaging, or
        // long gone — the order's own status cannot say.
        $orders = ClinicalOrderVisitStageEnricher::attachToTransformedOrders($result['data'], $orders);

        return response()->json([
            'data' => ClinicalOrderUserSummaryEnricher::attachOrderingClinicianToTransformedOrders($result['data'], $orders),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListRadiologyOrderStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(StoreRadiologyOrderRequest $request, CreateRadiologyOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForRadiologyOrderException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForRadiologyOrderException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForRadiologyOrderException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (RadiologyOrderProcedureCatalogItemNotEligibleException $exception) {
            $field = array_key_exists('radiologyProcedureCatalogItemId', $request->validated())
                ? 'radiologyProcedureCatalogItemId'
                : 'procedureCode';

            return $this->validationError($field, $exception->getMessage());
        }

        return response()->json([
            'data' => RadiologyOrderResponseTransformer::transform($order),
        ], 201);
    }

    public function show(string $id, GetRadiologyOrderUseCase $useCase): JsonResponse
    {
        $order = $useCase->execute($id);
        abort_if($order === null, 404, 'Radiology order not found.');

        $transformed = RadiologyOrderResponseTransformer::transform($order, true);
        $enriched = ClinicalOrderPatientSummaryEnricher::attachToTransformedOrders([$order], [$transformed]);
        $enriched = ClinicalOrderUserSummaryEnricher::attachOrderingClinicianToTransformedOrders([$order], $enriched);

        return response()->json([
            'data' => $enriched[0],
        ]);
    }

    public function update(string $id, UpdateRadiologyOrderRequest $request, UpdateRadiologyOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForRadiologyOrderException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForRadiologyOrderException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForRadiologyOrderException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (RadiologyOrderProcedureCatalogItemNotEligibleException $exception) {
            $field = array_key_exists('radiologyProcedureCatalogItemId', $request->validated())
                ? 'radiologyProcedureCatalogItemId'
                : 'procedureCode';

            return $this->validationError($field, $exception->getMessage());
        }

        abort_if($order === null, 404, 'Radiology order not found.');

        return response()->json([
            'data' => RadiologyOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function sign(string $id, Request $request, SignRadiologyOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->validationExceptionResponse($exception);
        }

        abort_if($order === null, 404, 'Radiology order not found.');

        return response()->json([
            'data' => RadiologyOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function discardDraft(
        string $id,
        Request $request,
        DiscardRadiologyOrderDraftUseCase $useCase
    ): JsonResponse {
        try {
            $discarded = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->validationExceptionResponse($exception);
        }

        abort_if(! $discarded, 404, 'Radiology order not found.');

        return response()->json(null, 204);
    }

    public function updateStatus(
        string $id,
        UpdateRadiologyOrderStatusRequest $request,
        UpdateRadiologyOrderStatusUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                reportSummary: $request->input('reportSummary'),
                actorId: $request->user()?->id,
                scheduledFor: $request->input('scheduledFor'),
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($order === null, 404, 'Radiology order not found.');

        // A `Gate::authorize('perform', $order)` used to sit here. It threw
        // "Unknown named parameter $id" on every call: RadiologyOrderPolicy::perform()
        // expects a RadiologyOrderModel, but the use case returns an array, so
        // Laravel fell through to treating it as gate callback arguments. The
        // 403 from the stale route permission masked the 500 until now.
        //
        // Nothing is lost by dropping it. The permission half is enforced by the
        // route (`can:imaging.perform`), and the state half by the use case,
        // which runs assertActiveForWorkflow() and canTransitionForward() before
        // it writes. The policy check ran *after* the write, so it inspected the
        // status the request had just set rather than the one it came from —
        // it could never have enforced what it claimed. This mirrors
        // LaboratoryOrderController::updateStatus(), which has no such call.
        return response()->json([
            'data' => RadiologyOrderResponseTransformer::transform($order),
        ]);
    }

    public function applyLifecycleAction(
        string $id,
        Request $request,
        ApplyRadiologyOrderLifecycleActionUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'action' => ['required', Rule::in(['cancel', 'entered_in_error'])],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $order = $useCase->execute(
                id: $id,
                action: (string) $payload['action'],
                reason: (string) $payload['reason'],
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($order === null, 404, 'Radiology order not found.');

        return response()->json([
            'data' => RadiologyOrderResponseTransformer::transform($order),
        ]);
    }

    public function duplicateCheck(
        Request $request,
        CheckRadiologyOrderDuplicatesUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'radiologyProcedureCatalogItemId' => ['nullable', 'uuid', 'required_without:procedureCode'],
            'procedureCode' => ['nullable', 'string', 'max:100', 'required_without:radiologyProcedureCatalogItemId'],
        ]);

        $result = $useCase->execute([
            'patient_id' => $payload['patientId'],
            'encounter_id' => $payload['encounterId'] ?? null,
            'appointment_id' => $payload['appointmentId'] ?? null,
            'admission_id' => $payload['admissionId'] ?? null,
            'radiology_procedure_catalog_item_id' => $payload['radiologyProcedureCatalogItemId'] ?? null,
            'procedure_code' => $payload['procedureCode'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'severity' => $result['severity'],
                'messages' => $result['messages'],
                'sameEncounterDuplicates' => array_map(
                    [RadiologyOrderResponseTransformer::class, 'transform'],
                    $result['sameEncounterDuplicates'],
                ),
                'recentPatientDuplicates' => array_map(
                    [RadiologyOrderResponseTransformer::class, 'transform'],
                    $result['recentPatientDuplicates'],
                ),
            ],
        ]);
    }

    /**
     * Releases a completed report to the patient chart.
     *
     * Every precondition — including the two-person rule — is enforced inside
     * VerifyRadiologyOrderResultUseCase, ahead of the write, so a refusal here
     * never leaves a half-verified row behind.
     */
    public function verifyResult(
        string $id,
        VerifyRadiologyOrderResultRequest $request,
        VerifyRadiologyOrderResultUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute(
                id: $id,
                verificationNote: $request->input('verificationNote'),
                actorId: $request->user()?->id,
            );

            abort_if($order === null, 404, 'Radiology order not found.');

            return response()->json([
                'data' => RadiologyOrderResponseTransformer::transform($order),
            ]);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (RadiologyOrderVerificationNotAllowedException $exception) {
            return $this->validationError('verification', $exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->validationExceptionResponse($exception);
        }
    }

    public function auditLogs(string $id, Request $request, ListRadiologyOrderAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(radiologyOrderId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Radiology order not found.');

        return response()->json([
            'data' => array_map([RadiologyOrderAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListRadiologyOrderAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            radiologyOrderId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Radiology order not found.');

        $safeId = $this->safeExportIdentifier($id, 'radiology-order');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('radiology_order_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    radiologyOrderId: $id,
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

    private function validationExceptionResponse(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $field = array_key_first($errors) ?? 'order';
        $message = $errors[$field][0] ?? $exception->getMessage();

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
            'encounterId' => 'encounter_id',
            'admissionId' => 'admission_id',
            'appointmentId' => 'appointment_id',
            'entryMode' => 'entry_mode',
            'orderSessionId' => 'clinical_order_session_id',
            'serviceRequestId' => 'service_request_id',
            'replacesOrderId' => 'replaces_order_id',
            'addOnToOrderId' => 'add_on_to_order_id',
            'orderedByUserId' => 'ordered_by_user_id',
            'orderedAt' => 'ordered_at',
            'radiologyProcedureCatalogItemId' => 'radiology_procedure_catalog_item_id',
            'procedureCode' => 'procedure_code',
            'modality' => 'modality',
            'studyDescription' => 'study_description',
            'clinicalIndication' => 'clinical_indication',
            'scheduledFor' => 'scheduled_for',
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
