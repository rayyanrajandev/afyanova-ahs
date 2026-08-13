<?php

namespace App\Modules\Laboratory\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAuditExportCsvJob;
use App\Modules\Laboratory\Application\Exceptions\AdmissionNotEligibleForLaboratoryOrderException;
use App\Modules\Laboratory\Application\Exceptions\AppointmentNotEligibleForLaboratoryOrderException;
use App\Modules\Laboratory\Application\Exceptions\LaboratoryOrderTestCatalogItemNotEligibleException;
use App\Modules\Laboratory\Application\Exceptions\LaboratoryOrderVerificationNotAllowedException;
use App\Modules\Laboratory\Application\Exceptions\PatientNotEligibleForLaboratoryOrderException;
use App\Modules\Laboratory\Application\UseCases\ApplyLaboratoryOrderLifecycleActionUseCase;
use App\Modules\Laboratory\Application\UseCases\CheckLaboratoryOrderDuplicatesUseCase;
use App\Modules\Laboratory\Application\UseCases\CreateLaboratoryOrderUseCase;
use App\Modules\Laboratory\Application\UseCases\DiscardLaboratoryOrderDraftUseCase;
use App\Modules\Laboratory\Application\UseCases\GetLaboratoryOrderUseCase;
use App\Modules\Laboratory\Application\UseCases\ListLaboratoryOrderAuditLogsUseCase;
use App\Modules\Laboratory\Application\UseCases\ListLaboratoryOrderStatusCountsUseCase;
use App\Modules\Laboratory\Application\UseCases\ListLaboratoryOrdersUseCase;
use App\Modules\Laboratory\Application\UseCases\SignLaboratoryOrderUseCase;
use App\Modules\Laboratory\Application\UseCases\UpdateLaboratoryOrderStatusUseCase;
use App\Modules\Laboratory\Application\UseCases\UpdateLaboratoryOrderUseCase;
use App\Modules\Laboratory\Application\UseCases\VerifyLaboratoryOrderResultUseCase;
use App\Modules\Laboratory\Presentation\Http\Requests\StoreLaboratoryOrderRequest;
use App\Modules\Laboratory\Presentation\Http\Requests\UpdateLaboratoryOrderRequest;
use App\Modules\Laboratory\Presentation\Http\Requests\UpdateLaboratoryOrderStatusRequest;
use App\Modules\Laboratory\Presentation\Http\Requests\VerifyLaboratoryOrderResultRequest;
use App\Modules\Laboratory\Presentation\Http\Transformers\LaboratoryOrderAuditLogResponseTransformer;
use App\Modules\Laboratory\Presentation\Http\Transformers\LaboratoryOrderResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use App\Support\ClinicalOrders\ClinicalOrderPatientSummaryEnricher;
use App\Support\ClinicalOrders\ClinicalOrderUserSummaryEnricher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaboratoryOrderController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    private const AUDIT_EXPORT_MODULE = GenerateAuditExportCsvJob::MODULE_LABORATORY;

    public function index(Request $request, ListLaboratoryOrdersUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());
        $orders = ClinicalOrderPatientSummaryEnricher::attachToTransformedOrders(
            $result['data'],
            array_map([LaboratoryOrderResponseTransformer::class, 'transform'], $result['data']),
        );

        return response()->json([
            'data' => ClinicalOrderUserSummaryEnricher::attachOrderingClinicianToTransformedOrders($result['data'], $orders),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListLaboratoryOrderStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function store(StoreLaboratoryOrderRequest $request, CreateLaboratoryOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForLaboratoryOrderException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForLaboratoryOrderException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForLaboratoryOrderException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (LaboratoryOrderTestCatalogItemNotEligibleException $exception) {
            $field = array_key_exists('labTestCatalogItemId', $request->validated())
                ? 'labTestCatalogItemId'
                : 'testCode';

            return $this->validationError($field, $exception->getMessage());
        }

        return response()->json([
            'data' => LaboratoryOrderResponseTransformer::transform($order),
        ], 201);
    }

    public function show(string $id, GetLaboratoryOrderUseCase $useCase): JsonResponse
    {
        $order = $useCase->execute($id);
        abort_if($order === null, 404, 'Laboratory order not found.');

        $transformed = LaboratoryOrderResponseTransformer::transform($order, true);
        $withPatient = ClinicalOrderPatientSummaryEnricher::attachToTransformedOrders(
            [$order],
            [$transformed],
        );

        return response()->json([
            'data' => ClinicalOrderUserSummaryEnricher::attachOrderingClinicianToTransformedOrders(
                [$order],
                $withPatient,
            )[0],
        ]);
    }

    public function update(string $id, UpdateLaboratoryOrderRequest $request, UpdateLaboratoryOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForLaboratoryOrderException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForLaboratoryOrderException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForLaboratoryOrderException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (LaboratoryOrderTestCatalogItemNotEligibleException $exception) {
            $field = array_key_exists('labTestCatalogItemId', $request->validated())
                ? 'labTestCatalogItemId'
                : 'testCode';

            return $this->validationError($field, $exception->getMessage());
        }

        abort_if($order === null, 404, 'Laboratory order not found.');

        return response()->json([
            'data' => LaboratoryOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function sign(string $id, Request $request, SignLaboratoryOrderUseCase $useCase): JsonResponse
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

        abort_if($order === null, 404, 'Laboratory order not found.');

        return response()->json([
            'data' => LaboratoryOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function discardDraft(
        string $id,
        Request $request,
        DiscardLaboratoryOrderDraftUseCase $useCase
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

        abort_if(! $discarded, 404, 'Laboratory order not found.');

        return response()->json(null, 204);
    }

    public function updateStatus(
        string $id,
        UpdateLaboratoryOrderStatusRequest $request,
        UpdateLaboratoryOrderStatusUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                resultSummary: $request->input('resultSummary'),
                actorId: $request->user()?->id,
                resultParameters: $request->input('resultParameters'),
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($order === null, 404, 'Laboratory order not found.');

        return response()->json([
            'data' => LaboratoryOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function applyLifecycleAction(
        string $id,
        Request $request,
        ApplyLaboratoryOrderLifecycleActionUseCase $useCase
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

        abort_if($order === null, 404, 'Laboratory order not found.');

        return response()->json([
            'data' => LaboratoryOrderResponseTransformer::transform($order, true),
        ]);
    }

    public function duplicateCheck(
        Request $request,
        CheckLaboratoryOrderDuplicatesUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'labTestCatalogItemId' => ['nullable', 'uuid', 'required_without:testCode'],
            'testCode' => ['nullable', 'string', 'max:50', 'required_without:labTestCatalogItemId'],
        ]);

        $result = $useCase->execute([
            'patient_id' => $payload['patientId'],
            'encounter_id' => $payload['encounterId'] ?? null,
            'appointment_id' => $payload['appointmentId'] ?? null,
            'admission_id' => $payload['admissionId'] ?? null,
            'lab_test_catalog_item_id' => $payload['labTestCatalogItemId'] ?? null,
            'test_code' => $payload['testCode'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'severity' => $result['severity'],
                'messages' => $result['messages'],
                'sameEncounterDuplicates' => array_map(
                    [LaboratoryOrderResponseTransformer::class, 'transform'],
                    $result['sameEncounterDuplicates'],
                ),
                'recentPatientDuplicates' => array_map(
                    [LaboratoryOrderResponseTransformer::class, 'transform'],
                    $result['recentPatientDuplicates'],
                ),
            ],
        ]);
    }

    public function auditLogs(string $id, Request $request, ListLaboratoryOrderAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(laboratoryOrderId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Laboratory order not found.');

        return response()->json([
            'data' => array_map([LaboratoryOrderAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(string $id, Request $request, ListLaboratoryOrderAuditLogsUseCase $useCase): StreamedResponse
    {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(laboratoryOrderId: $id, filters: $filters);
        abort_if($firstPage === null, 404, 'Laboratory order not found.');

        $safeId = $this->safeExportIdentifier($id, 'laboratory-order');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('laboratory_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    laboratoryOrderId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function createAuditLogsCsvExportJob(
        string $id,
        Request $request,
        ListLaboratoryOrderAuditLogsUseCase $useCase
    ): JsonResponse {
        $filters = $this->normalizeAuditExportFilters($request);
        $resourceCheck = $useCase->execute(
            laboratoryOrderId: $id,
            filters: array_merge($filters, ['page' => 1, 'perPage' => 1]),
        );
        abort_if($resourceCheck === null, 404, 'Laboratory order not found.');

        $auditExportJob = AuditExportJobModel::query()->create([
            'module' => self::AUDIT_EXPORT_MODULE,
            'target_resource_id' => $id,
            'status' => 'queued',
            'filters' => $filters,
            'created_by_user_id' => $request->user()?->id,
        ]);

        GenerateAuditExportCsvJob::dispatch((string) $auditExportJob->id);
        $auditExportJob->refresh();

        return response()->json([
            'data' => $this->transformAuditExportJob($auditExportJob, $id),
        ], 202);
    }

    public function auditLogsCsvExportJobs(
        string $id,
        Request $request,
        ListLaboratoryOrderAuditLogsUseCase $useCase
    ): JsonResponse {
        $resourceCheck = $useCase->execute(
            laboratoryOrderId: $id,
            filters: ['page' => 1, 'perPage' => 1],
        );
        abort_if($resourceCheck === null, 404, 'Laboratory order not found.');

        $perPage = max(min((int) $request->input('perPage', 10), 50), 1);
        $page = max((int) $request->input('page', 1), 1);
        $statusGroup = strtolower((string) $request->input('statusGroup', 'all'));
        if (! in_array($statusGroup, ['all', 'failed', 'backlog', 'completed'], true)) {
            $statusGroup = 'all';
        }

        $query = AuditExportJobModel::query()
            ->where('module', self::AUDIT_EXPORT_MODULE)
            ->where('target_resource_id', $id)
            ->where('created_by_user_id', $request->user()?->id)
            ->orderByDesc('created_at');

        if ($statusGroup === 'failed') {
            $query->where('status', 'failed');
        } elseif ($statusGroup === 'backlog') {
            $query->whereIn('status', ['queued', 'processing']);
        } elseif ($statusGroup === 'completed') {
            $query->where('status', 'completed');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (AuditExportJobModel $job): array => $this->transformAuditExportJob($job, $id))
                ->values()
                ->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'filters' => [
                    'statusGroup' => $statusGroup,
                ],
            ],
        ]);
    }

    public function auditLogsCsvExportJob(string $id, string $jobId, Request $request): JsonResponse
    {
        $auditExportJob = $this->findAuditExportJob($id, $jobId, $request->user()?->id);
        abort_if($auditExportJob === null, 404, 'Audit export job not found.');

        return response()->json([
            'data' => $this->transformAuditExportJob($auditExportJob, $id),
        ]);
    }

    public function retryAuditLogsCsvExportJob(
        string $id,
        string $jobId,
        Request $request
    ): JsonResponse {
        $auditExportJob = $this->findAuditExportJob($id, $jobId, $request->user()?->id);
        abort_if($auditExportJob === null, 404, 'Audit export job not found.');

        $retryJob = AuditExportJobModel::query()->create([
            'module' => self::AUDIT_EXPORT_MODULE,
            'target_resource_id' => $id,
            'status' => 'queued',
            'filters' => is_array($auditExportJob->filters) ? $auditExportJob->filters : [],
            'created_by_user_id' => $request->user()?->id,
        ]);

        GenerateAuditExportCsvJob::dispatch((string) $retryJob->id);
        $retryJob->refresh();

        return response()->json([
            'data' => $this->transformAuditExportJob($retryJob, $id),
        ], 202);
    }

    public function downloadAuditLogsCsvExportJob(
        string $id,
        string $jobId,
        Request $request
    ): JsonResponse|StreamedResponse {
        $auditExportJob = $this->findAuditExportJob($id, $jobId, $request->user()?->id);
        abort_if($auditExportJob === null, 404, 'Audit export job not found.');

        if ($auditExportJob->status !== 'completed' || ! $auditExportJob->file_path) {
            return response()->json([
                'code' => 'EXPORT_JOB_NOT_READY',
                'message' => 'Audit export job is not ready for download.',
            ], 409);
        }

        $disk = Storage::disk('local');
        abort_if(! $disk->exists($auditExportJob->file_path), 404, 'Audit export file not found.');

        return $this->downloadStoredCsvExport(
            filePath: $auditExportJob->file_path,
            downloadName: $auditExportJob->file_name ?: $this->brandedCsvFilename(
                sprintf('laboratory_audit_%s', $this->safeExportIdentifier($id, 'laboratory-order'))
            ),
            schemaHeaderName: 'X-Audit-CSV-Schema-Version',
            schemaVersion: self::AUDIT_CSV_SCHEMA_VERSION,
        );
    }

    public function verifyResult(
        string $id,
        VerifyLaboratoryOrderResultRequest $request,
        VerifyLaboratoryOrderResultUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute(
                id: $id,
                verificationNote: $request->input('verificationNote'),
                actorId: $request->user()?->id,
            );

            abort_if($order === null, 404, 'Laboratory order not found.');

            if (($order['ordered_by_user_id'] ?? null) === $request->user()?->id) {
                return $this->validationError('verification', 'You cannot verify your own laboratory order.');
            }

            return response()->json([
                'data' => LaboratoryOrderResponseTransformer::transform($order),
            ]);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (LaboratoryOrderVerificationNotAllowedException $exception) {
            return $this->validationError('verification', $exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->validationExceptionResponse($exception);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function normalizeAuditExportFilters(Request $request): array
    {
        $query = trim((string) $request->input('q', ''));
        $action = trim((string) $request->input('action', ''));
        $actorTypeInput = strtolower(trim((string) $request->input('actorType', '')));
        $actorType = in_array($actorTypeInput, ['system', 'user'], true) ? $actorTypeInput : null;
        $actorIdInput = trim((string) $request->input('actorId', ''));
        $actorId = $actorIdInput !== '' && ctype_digit($actorIdInput)
            ? $actorIdInput
            : null;
        $from = trim((string) $request->input('from', ''));
        $to = trim((string) $request->input('to', ''));

        return [
            'q' => $query !== '' ? $query : null,
            'action' => $action !== '' ? $action : null,
            'actorType' => $actorType,
            'actorId' => $actorId,
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
        ];
    }

    private function findAuditExportJob(string $resourceId, string $jobId, ?int $actorId): ?AuditExportJobModel
    {
        return AuditExportJobModel::query()
            ->where('id', $jobId)
            ->where('module', self::AUDIT_EXPORT_MODULE)
            ->where('target_resource_id', $resourceId)
            ->where('created_by_user_id', $actorId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAuditExportJob(AuditExportJobModel $auditExportJob, string $resourceId): array
    {
        $downloadUrl = null;
        if ($auditExportJob->status === 'completed' && $auditExportJob->file_path) {
            $downloadUrl = sprintf(
                '/api/v1/laboratory-orders/%s/audit-logs/export-jobs/%s/download',
                $resourceId,
                $auditExportJob->id,
            );
        }

        return [
            'id' => $auditExportJob->id,
            'status' => $auditExportJob->status,
            'rowCount' => $auditExportJob->row_count,
            'schemaVersion' => self::AUDIT_CSV_SCHEMA_VERSION,
            'errorMessage' => $auditExportJob->error_message,
            'createdAt' => optional($auditExportJob->created_at)?->toISOString(),
            'startedAt' => optional($auditExportJob->started_at)?->toISOString(),
            'completedAt' => optional($auditExportJob->completed_at)?->toISOString(),
            'failedAt' => optional($auditExportJob->failed_at)?->toISOString(),
            'downloadUrl' => $downloadUrl,
        ];
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
            'labTestCatalogItemId' => 'lab_test_catalog_item_id',
            'testCode' => 'test_code',
            'testName' => 'test_name',
            'priority' => 'priority',
            'specimenType' => 'specimen_type',
            'clinicalNotes' => 'clinical_notes',
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
