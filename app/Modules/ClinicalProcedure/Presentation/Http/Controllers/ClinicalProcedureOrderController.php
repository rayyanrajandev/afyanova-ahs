<?php

namespace App\Modules\ClinicalProcedure\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\ClinicalProcedure\Application\UseCases\ApplyClinicalProcedureOrderLifecycleActionUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\CheckClinicalProcedureOrderDuplicatesUseCase;
use App\Modules\ClinicalProcedure\Application\Exceptions\AdmissionNotEligibleForClinicalProcedureOrderException;
use App\Modules\ClinicalProcedure\Application\Exceptions\AppointmentNotEligibleForClinicalProcedureOrderException;
use App\Modules\ClinicalProcedure\Application\Exceptions\PatientNotEligibleForClinicalProcedureOrderException;
use App\Modules\ClinicalProcedure\Application\Exceptions\ClinicalProcedureOrderProcedureCatalogItemNotEligibleException;
use App\Modules\ClinicalProcedure\Application\UseCases\CreateClinicalProcedureOrderUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\DiscardClinicalProcedureOrderDraftUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\GetClinicalProcedureOrderUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\ListClinicalProcedureOrderAuditLogsUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\ListClinicalProcedureOrdersUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\ListClinicalProcedureOrderStatusCountsUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\SignClinicalProcedureOrderUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\UpdateClinicalProcedureOrderStatusUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\UpdateClinicalProcedureOrderUseCase;
use App\Modules\ClinicalProcedure\Presentation\Http\Requests\StoreClinicalProcedureOrderRequest;
use App\Modules\ClinicalProcedure\Presentation\Http\Requests\UpdateClinicalProcedureOrderRequest;
use App\Modules\ClinicalProcedure\Presentation\Http\Requests\UpdateClinicalProcedureOrderStatusRequest;
use App\Modules\ClinicalProcedure\Presentation\Http\Transformers\ClinicalProcedureOrderAuditLogResponseTransformer;
use App\Modules\ClinicalProcedure\Presentation\Http\Transformers\ClinicalProcedureOrderResponseTransformer;
use App\Support\ClinicalOrders\ClinicalOrderPatientSummaryEnricher;
use App\Support\ClinicalOrders\ClinicalOrderUserSummaryEnricher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClinicalProcedureOrderController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListClinicalProcedureOrdersUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        $orders = ClinicalOrderPatientSummaryEnricher::attachToTransformedOrders(
            $result['data'],
            array_map([ClinicalProcedureOrderResponseTransformer::class, 'transform'], $result['data']),
        );

        return response()->json([
            'data' => ClinicalOrderUserSummaryEnricher::attachOrderingClinicianToTransformedOrders($result['data'], $orders),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListClinicalProcedureOrderStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(StoreClinicalProcedureOrderRequest $request, CreateClinicalProcedureOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForClinicalProcedureOrderException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForClinicalProcedureOrderException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForClinicalProcedureOrderException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (ClinicalProcedureOrderProcedureCatalogItemNotEligibleException $exception) {
            $field = array_key_exists('clinicalProcedureCatalogItemId', $request->validated())
                ? 'clinicalProcedureCatalogItemId'
                : 'procedureCode';

            return $this->validationError($field, $exception->getMessage());
        }

        return response()->json([
            'data' => ClinicalProcedureOrderResponseTransformer::transform($order),
        ], 201);
    }

    public function show(string $id, GetClinicalProcedureOrderUseCase $useCase): JsonResponse
    {
        $order = $useCase->execute($id);
        abort_if($order === null, 404, 'Clinical procedure order not found.');

        return response()->json([
            'data' => ClinicalProcedureOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function update(string $id, UpdateClinicalProcedureOrderRequest $request, UpdateClinicalProcedureOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForClinicalProcedureOrderException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForClinicalProcedureOrderException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForClinicalProcedureOrderException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (ClinicalProcedureOrderProcedureCatalogItemNotEligibleException $exception) {
            $field = array_key_exists('clinicalProcedureCatalogItemId', $request->validated())
                ? 'clinicalProcedureCatalogItemId'
                : 'procedureCode';

            return $this->validationError($field, $exception->getMessage());
        }

        abort_if($order === null, 404, 'Clinical procedure order not found.');

        return response()->json([
            'data' => ClinicalProcedureOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function sign(string $id, Request $request, SignClinicalProcedureOrderUseCase $useCase): JsonResponse
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

        abort_if($order === null, 404, 'Clinical procedure order not found.');

        return response()->json([
            'data' => ClinicalProcedureOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function discardDraft(
        string $id,
        Request $request,
        DiscardClinicalProcedureOrderDraftUseCase $useCase
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

        abort_if(! $discarded, 404, 'Clinical procedure order not found.');

        return response()->json(null, 204);
    }

    public function updateStatus(
        string $id,
        UpdateClinicalProcedureOrderStatusRequest $request,
        UpdateClinicalProcedureOrderStatusUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                reportSummary: $request->input('reportSummary'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($order === null, 404, 'Clinical procedure order not found.');

        return response()->json([
            'data' => ClinicalProcedureOrderResponseTransformer::transform($order),
        ]);
    }

    public function applyLifecycleAction(
        string $id,
        Request $request,
        ApplyClinicalProcedureOrderLifecycleActionUseCase $useCase
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

        abort_if($order === null, 404, 'Clinical procedure order not found.');

        return response()->json([
            'data' => ClinicalProcedureOrderResponseTransformer::transform($order),
        ]);
    }

    public function duplicateCheck(
        Request $request,
        CheckClinicalProcedureOrderDuplicatesUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'clinicalProcedureCatalogItemId' => ['nullable', 'uuid', 'required_without:procedureCode'],
            'procedureCode' => ['nullable', 'string', 'max:100', 'required_without:clinicalProcedureCatalogItemId'],
        ]);

        $result = $useCase->execute([
            'patient_id' => $payload['patientId'],
            'encounter_id' => $payload['encounterId'] ?? null,
            'appointment_id' => $payload['appointmentId'] ?? null,
            'admission_id' => $payload['admissionId'] ?? null,
            'clinical_procedure_catalog_item_id' => $payload['clinicalProcedureCatalogItemId'] ?? null,
            'procedure_code' => $payload['procedureCode'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'severity' => $result['severity'],
                'messages' => $result['messages'],
                'sameEncounterDuplicates' => array_map(
                    [ClinicalProcedureOrderResponseTransformer::class, 'transform'],
                    $result['sameEncounterDuplicates'],
                ),
                'recentPatientDuplicates' => array_map(
                    [ClinicalProcedureOrderResponseTransformer::class, 'transform'],
                    $result['recentPatientDuplicates'],
                ),
            ],
        ]);
    }

    public function auditLogs(string $id, Request $request, ListClinicalProcedureOrderAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(clinicalProcedureOrderId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Clinical procedure order not found.');

        return response()->json([
            'data' => array_map([ClinicalProcedureOrderAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListClinicalProcedureOrderAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            clinicalProcedureOrderId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Clinical procedure order not found.');

        $safeId = $this->safeExportIdentifier($id, 'clinical-procedure-order');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('clinical_procedure_order_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    clinicalProcedureOrderId: $id,
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
            'clinicalProcedureCatalogItemId' => 'clinical_procedure_catalog_item_id',
            'procedureCode' => 'procedure_code',
            'procedureSetting' => 'procedure_setting',
            'procedureDescription' => 'procedure_description',
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
