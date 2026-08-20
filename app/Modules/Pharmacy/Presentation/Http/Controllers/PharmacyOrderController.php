<?php

namespace App\Modules\Pharmacy\Presentation\Http\Controllers;

use App\Jobs\GenerateAuditExportCsvJob;
use App\Http\Controllers\Controller;
use App\Modules\InventoryProcurement\Application\Exceptions\InsufficientInventoryStockException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\Patient\Presentation\Http\Transformers\PatientAllergyResponseTransformer;
use App\Modules\Patient\Presentation\Http\Transformers\PatientMedicationProfileResponseTransformer;
use App\Modules\Pharmacy\Application\Exceptions\AdmissionNotEligibleForPharmacyOrderException;
use App\Modules\Pharmacy\Application\Exceptions\AppointmentNotEligibleForPharmacyOrderException;
use App\Modules\Pharmacy\Application\Exceptions\PatientNotEligibleForPharmacyOrderException;
use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderApprovedMedicineCatalogItemNotEligibleException;
use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderPolicyUpdateNotAllowedException;
use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderReconciliationNotAllowedException;
use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderStatusUpdateNotAllowedException;
use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderVerificationNotAllowedException;
use App\Modules\Pharmacy\Application\UseCases\ApplyPharmacyOrderLifecycleActionUseCase;
use App\Modules\Pharmacy\Application\UseCases\CheckPharmacyOrderDuplicatesUseCase;
use App\Modules\Pharmacy\Application\UseCases\CreatePharmacyOrderUseCase;
use App\Modules\Pharmacy\Application\UseCases\DiscardPharmacyOrderDraftUseCase;
use App\Modules\Pharmacy\Application\UseCases\GetPharmacyMedicationAvailabilityUseCase;
use App\Modules\Pharmacy\Application\UseCases\GetPharmacyOrderUseCase;
use App\Modules\Pharmacy\Application\UseCases\GetPharmacyOrderSafetyReviewUseCase;
use App\Modules\Pharmacy\Application\UseCases\ListPharmacyOrderAuditLogsUseCase;
use App\Modules\Pharmacy\Application\UseCases\ListPharmacyOrdersUseCase;
use App\Modules\Pharmacy\Application\UseCases\ListPharmacyOrderStatusCountsUseCase;
use App\Modules\Pharmacy\Application\UseCases\ReconcilePharmacyOrderUseCase;
use App\Modules\Pharmacy\Application\UseCases\UpdatePharmacyOrderPolicyUseCase;
use App\Modules\Pharmacy\Application\UseCases\VerifyPharmacyOrderDispenseUseCase;
use App\Modules\Pharmacy\Application\UseCases\SignPharmacyOrderUseCase;
use App\Modules\Pharmacy\Application\UseCases\UpdatePharmacyOrderStatusUseCase;
use App\Modules\Pharmacy\Application\UseCases\UpdatePharmacyOrderUseCase;
use App\Modules\Pharmacy\Presentation\Http\Requests\ReconcilePharmacyOrderRequest;
use App\Modules\Pharmacy\Presentation\Http\Requests\UpdatePharmacyOrderPolicyRequest;
use App\Modules\Pharmacy\Presentation\Http\Requests\VerifyPharmacyOrderDispenseRequest;
use App\Modules\Pharmacy\Presentation\Http\Requests\StorePharmacyOrderRequest;
use App\Modules\Pharmacy\Presentation\Http\Requests\UpdatePharmacyOrderRequest;
use App\Modules\Pharmacy\Presentation\Http\Requests\UpdatePharmacyOrderStatusRequest;
use App\Modules\Pharmacy\Presentation\Http\Transformers\MedicationInteractionConflictResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\MedicationLaboratorySignalResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyOrderAuditLogResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyMedicationAvailabilityResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyOrderResponseTransformer;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Support\ClinicalOrders\ClinicalOrderPaymentEnricher;
use App\Support\ClinicalOrders\ClinicalOrderPatientSummaryEnricher;
use App\Support\ClinicalOrders\ClinicalOrderUserSummaryEnricher;
use App\Support\ClinicalOrders\ClinicalOrderVisitStageEnricher;
use App\Modules\Platform\Application\UseCases\ListClinicalCatalogItemsUseCase;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Presentation\Http\Transformers\ClinicalCatalogItemResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PharmacyOrderController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    private const AUDIT_EXPORT_MODULE = GenerateAuditExportCsvJob::MODULE_PHARMACY;

    public function index(Request $request, ListPharmacyOrdersUseCase $useCase): JsonResponse
    {
        $filters = $request->all();

        // When queried from the pharmacy worklist workspace, only show authorized orders
        if (! array_key_exists('authorizedOnly', $filters) && ($request->routeIs('pharmacy.orders.*') || $request->is('api/v1/pharmacy/*'))) {
            $filters['authorizedOnly'] = true;
        }

        $result = $useCase->execute($filters);
        $orders = ClinicalOrderPatientSummaryEnricher::attachToTransformedOrders(
            $result['data'],
            array_map([PharmacyOrderResponseTransformer::class, 'transform'], $result['data']),
        );

        // visitStage lets the worklist say where each patient actually is rather
        // than only where this prescription is. Laboratory and radiology have
        // carried it since their workspaces were built; without it the shared
        // stage badge simply never renders on a pharmacy row.
        $orders = ClinicalOrderVisitStageEnricher::attachToTransformedOrders($result['data'], $orders);
        $orders = ClinicalOrderPaymentEnricher::attachToTransformedOrders($result['data'], $orders, ChargeSourceKind::PHARMACY_ORDER);

        return response()->json([
            'data' => ClinicalOrderUserSummaryEnricher::attachOrderingClinicianToTransformedOrders($result['data'], $orders),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListPharmacyOrderStatusCountsUseCase $useCase): JsonResponse
    {
        $filters = $request->all();

        if (! array_key_exists('authorizedOnly', $filters) && ($request->routeIs('pharmacy.orders.*') || $request->is('api/v1/pharmacy/*'))) {
            $filters['authorizedOnly'] = true;
        }

        $counts = $useCase->execute($filters);

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function availability(
        Request $request,
        GetPharmacyMedicationAvailabilityUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'medicationCode' => ['nullable', 'string', 'max:255'],
            'medicationName' => ['nullable', 'string', 'max:255'],
            'catalogItemId' => ['nullable', 'uuid'],
        ]);

        $item = $useCase->execute(
            $payload['medicationCode'] ?? null,
            $payload['medicationName'] ?? null,
            $payload['catalogItemId'] ?? null,
        );

        return response()->json([
            'data' => PharmacyMedicationAvailabilityResponseTransformer::transform($item),
        ]);
    }

    public function approvedMedicinesCatalog(
        Request $request,
        ListClinicalCatalogItemsUseCase $useCase
    ): JsonResponse {
        abort_unless($this->canReadOperationalFormulary($request), 403);

        $result = $useCase->execute(
            ClinicalCatalogType::FORMULARY_ITEM->value,
            $request->all(),
        );

        return response()->json([
            'data' => array_map(
                [ClinicalCatalogItemResponseTransformer::class, 'transform'],
                $result['data'],
            ),
            'meta' => $result['meta'],
        ]);
    }

    public function store(StorePharmacyOrderRequest $request, CreatePharmacyOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForPharmacyOrderException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForPharmacyOrderException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForPharmacyOrderException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (PharmacyOrderApprovedMedicineCatalogItemNotEligibleException $exception) {
            $field = array_key_exists('approvedMedicineCatalogItemId', $request->validated())
                ? 'approvedMedicineCatalogItemId'
                : 'medicationCode';
            return $this->validationError($field, $exception->getMessage());
        }

        $transformed = PharmacyOrderResponseTransformer::transform($order);
        $withPayment = ClinicalOrderPaymentEnricher::attachToTransformedOrders([$order], [$transformed], ChargeSourceKind::PHARMACY_ORDER)[0];

        return response()->json([
            'data' => $withPayment,
        ], 201);
    }

    public function show(string $id, GetPharmacyOrderUseCase $useCase): JsonResponse
    {
        $order = $useCase->execute($id);
        abort_if($order === null, 404, 'Pharmacy order not found.');

        $transformed = PharmacyOrderResponseTransformer::transform($order);
        $enriched = ClinicalOrderPatientSummaryEnricher::attachToTransformedOrders([$order], [$transformed]);
        $enriched = ClinicalOrderVisitStageEnricher::attachToTransformedOrders([$order], $enriched);
        $enriched = ClinicalOrderPaymentEnricher::attachToTransformedOrders([$order], $enriched, ChargeSourceKind::PHARMACY_ORDER);
        $enriched = ClinicalOrderUserSummaryEnricher::attachOrderingClinicianToTransformedOrders([$order], $enriched);

        return response()->json([
            'data' => $enriched[0],
        ]);
    }

    public function safetyReview(
        string $id,
        GetPharmacyOrderSafetyReviewUseCase $useCase
    ): JsonResponse {
        $review = $useCase->execute($id);
        abort_if($review === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => [
                'severity' => $review['severity'],
                'blockers' => $review['blockers'],
                'warnings' => $review['warnings'],
                'rules' => $review['rules'],
                'ruleGroups' => $review['ruleGroups'] ?? [],
                'ruleSummary' => $review['ruleSummary'] ?? null,
                'ruleCatalogVersion' => $review['ruleCatalogVersion'] ?? null,
                'overrideOptions' => $review['overrideOptions'],
                'patientContext' => $review['patientContext'] ?? null,
                'allergyConflicts' => array_map(
                    [PatientAllergyResponseTransformer::class, 'transform'],
                    $review['allergyConflicts'],
                ),
                'interactionConflicts' => array_map(
                    [MedicationInteractionConflictResponseTransformer::class, 'transform'],
                    $review['interactionConflicts'] ?? [],
                ),
                'laboratorySignals' => array_map(
                    [MedicationLaboratorySignalResponseTransformer::class, 'transform'],
                    $review['laboratorySignals'] ?? [],
                ),
                'policyRecommendation' => $review['policyRecommendation'] ?? null,
                'activeProfileMatches' => array_map(
                    [PatientMedicationProfileResponseTransformer::class, 'transform'],
                    $review['activeProfileMatches'],
                ),
                'matchingActiveOrders' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $review['matchingActiveOrders'],
                ),
                'sameEncounterDuplicates' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $review['sameEncounterDuplicates'],
                ),
                'recentPatientDuplicates' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $review['recentPatientDuplicates'],
                ),
                'recentMedicationHistory' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $review['recentMedicationHistory'],
                ),
                'unreconciledReleasedOrders' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $review['unreconciledReleasedOrders'],
                ),
                'dispenseInventory' => PharmacyMedicationAvailabilityResponseTransformer::transform(
                    $review['dispenseInventory'],
                ),
            ],
        ]);
    }

    public function updatePolicy(
        string $id,
        UpdatePharmacyOrderPolicyRequest $request,
        UpdatePharmacyOrderPolicyUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute(
                id: $id,
                payload: $this->toPolicyPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->validationExceptionResponse($exception);
        } catch (PharmacyOrderPolicyUpdateNotAllowedException $exception) {
            return $this->validationError('policy', $exception->getMessage());
        }

        abort_if($order === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => PharmacyOrderResponseTransformer::transform($order),
        ]);
    }

    public function reconcile(
        string $id,
        ReconcilePharmacyOrderRequest $request,
        ReconcilePharmacyOrderUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute(
                id: $id,
                payload: $this->toReconciliationPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PharmacyOrderReconciliationNotAllowedException $exception) {
            return $this->validationError('reconciliation', $exception->getMessage());
        }

        abort_if($order === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => PharmacyOrderResponseTransformer::transform($order),
        ]);
    }

    public function update(string $id, UpdatePharmacyOrderRequest $request, UpdatePharmacyOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                id: $id,
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PatientNotEligibleForPharmacyOrderException $exception) {
            return $this->validationError('patientId', $exception->getMessage());
        } catch (AppointmentNotEligibleForPharmacyOrderException $exception) {
            return $this->validationError('appointmentId', $exception->getMessage());
        } catch (AdmissionNotEligibleForPharmacyOrderException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (PharmacyOrderApprovedMedicineCatalogItemNotEligibleException $exception) {
            $field = array_key_exists('approvedMedicineCatalogItemId', $request->validated())
                ? 'approvedMedicineCatalogItemId'
                : 'medicationCode';
            return $this->validationError($field, $exception->getMessage());
        }

        abort_if($order === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => PharmacyOrderResponseTransformer::transform($order),
        ]);
    }

    /**
     * Commits a drafted prescription so it becomes real work for pharmacy.
     *
     * Pharmacy had discardDraft but no sign, so a draft could only ever be
     * thrown away. Mirrors LaboratoryOrderController::sign().
     */
    public function sign(string $id, Request $request, SignPharmacyOrderUseCase $useCase): JsonResponse
    {
        try {
            $order = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
                safetyAcknowledged: $request->boolean('safetyAcknowledged'),
                safetyOverrideCode: $request->input('safetyOverrideCode'),
                safetyOverrideReason: $request->input('safetyOverrideReason'),
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->validationExceptionResponse($exception);
        }

        abort_if($order === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => PharmacyOrderResponseTransformer::transform($order),
        ]);
    }

    public function discardDraft(
        string $id,
        Request $request,
        DiscardPharmacyOrderDraftUseCase $useCase
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

        abort_if(! $discarded, 404, 'Pharmacy order not found.');

        return response()->json(null, 204);
    }

    public function updateStatus(
        string $id,
        UpdatePharmacyOrderStatusRequest $request,
        UpdatePharmacyOrderStatusUseCase $useCase
    ): JsonResponse {
        $order = PharmacyOrderModel::query()->findOrFail($id);

        if ($order->verified_at === null) {
            abort_unless($request->user()?->can('medication.dispense'), 403, 'This action is unauthorized.');
        } else {
            Gate::authorize('dispense', $order);
        }

        try {
            $order = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                quantityDispensed: $request->filled('quantityDispensed') ? (float) $request->input('quantityDispensed') : null,
                dispensedUnit: $request->input('dispensedUnit'),
                dispensingNotes: $request->input('dispensingNotes'),
                batchId: $request->input('batchId'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PharmacyOrderStatusUpdateNotAllowedException $exception) {
            return $this->validationError($exception->field(), $exception->getMessage());
        } catch (InventoryItemNotFoundException $exception) {
            return $this->validationError('inventory', $exception->getMessage());
        } catch (InsufficientInventoryStockException $exception) {
            return $this->validationError('quantityDispensed', $exception->getMessage());
        }

        abort_if($order === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => PharmacyOrderResponseTransformer::transform($order),
        ]);
    }

    public function applyLifecycleAction(
        string $id,
        Request $request,
        ApplyPharmacyOrderLifecycleActionUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'action' => ['required', Rule::in(['cancel', 'discontinue', 'entered_in_error'])],
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

        abort_if($order === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => PharmacyOrderResponseTransformer::transform($order),
        ]);
    }

    public function duplicateCheck(
        Request $request,
        CheckPharmacyOrderDuplicatesUseCase $useCase
    ): JsonResponse {
        $payload = $request->validate([
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'approvedMedicineCatalogItemId' => ['nullable', 'uuid', 'required_without:medicationCode'],
            'medicationCode' => ['nullable', 'string', 'max:100', 'required_without:approvedMedicineCatalogItemId'],
        ]);

        $result = $useCase->execute([
            'patient_id' => $payload['patientId'],
            'encounter_id' => $payload['encounterId'] ?? null,
            'appointment_id' => $payload['appointmentId'] ?? null,
            'admission_id' => $payload['admissionId'] ?? null,
            'approved_medicine_catalog_item_id' => $payload['approvedMedicineCatalogItemId'] ?? null,
            'medication_code' => $payload['medicationCode'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'severity' => $result['severity'],
                'messages' => $result['messages'],
                'sameEncounterDuplicates' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $result['sameEncounterDuplicates'],
                ),
                'recentPatientDuplicates' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $result['recentPatientDuplicates'],
                ),
            ],
        ]);
    }

    public function auditLogs(string $id, Request $request, ListPharmacyOrderAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(pharmacyOrderId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => array_map([PharmacyOrderAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(string $id, Request $request, ListPharmacyOrderAuditLogsUseCase $useCase): StreamedResponse
    {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(pharmacyOrderId: $id, filters: $filters);
        abort_if($firstPage === null, 404, 'Pharmacy order not found.');

        $safeId = $this->safeExportIdentifier($id, 'pharmacy-order');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('pharmacy_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    pharmacyOrderId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function createAuditLogsCsvExportJob(
        string $id,
        Request $request,
        ListPharmacyOrderAuditLogsUseCase $useCase
    ): JsonResponse {
        $filters = $this->normalizeAuditExportFilters($request);
        $resourceCheck = $useCase->execute(
            pharmacyOrderId: $id,
            filters: array_merge($filters, ['page' => 1, 'perPage' => 1]),
        );
        abort_if($resourceCheck === null, 404, 'Pharmacy order not found.');

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
        ListPharmacyOrderAuditLogsUseCase $useCase
    ): JsonResponse {
        $resourceCheck = $useCase->execute(
            pharmacyOrderId: $id,
            filters: ['page' => 1, 'perPage' => 1],
        );
        abort_if($resourceCheck === null, 404, 'Pharmacy order not found.');

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
                sprintf('pharmacy_audit_%s', $this->safeExportIdentifier($id, 'pharmacy-order'))
            ),
            schemaHeaderName: 'X-Audit-CSV-Schema-Version',
            schemaVersion: self::AUDIT_CSV_SCHEMA_VERSION,
        );
    }

    public function verifyDispense(
        string $id,
        VerifyPharmacyOrderDispenseRequest $request,
        VerifyPharmacyOrderDispenseUseCase $useCase
    ): JsonResponse {
        try {
            $order = $useCase->execute(
                id: $id,
                verificationNote: $request->input('verificationNote'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (PharmacyOrderVerificationNotAllowedException $exception) {
            return $this->validationError('verification', $exception->getMessage());
        }

        abort_if($order === null, 404, 'Pharmacy order not found.');

        return response()->json([
            'data' => PharmacyOrderResponseTransformer::transform($order),
        ]);
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

    private function canReadOperationalFormulary(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        foreach ([
            'platform.clinical-catalog.read',
            'pharmacy.orders.read',
            'medication.prescribe',
            'pharmacy.orders.manage-policy',
            'medication.dispense',
            'pharmacy.orders.verify-dispense',
            'pharmacy.orders.reconcile',
        ] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
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
                '/api/v1/pharmacy-orders/%s/audit-logs/export-jobs/%s/download',
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
            'approvedMedicineCatalogItemId' => 'approved_medicine_catalog_item_id',
            'medicationCode' => 'medication_code',
            'medicationName' => 'medication_name',
            'dosageInstruction' => 'dosage_instruction',
            'doseQuantity' => 'dose_quantity',
            'doseUnit' => 'dose_unit',
            'route' => 'route',
            'frequency' => 'frequency',
            'durationValue' => 'duration_value',
            'durationUnit' => 'duration_unit',
            'infusionRateValue' => 'infusion_rate_value',
            'infusionRateUnit' => 'infusion_rate_unit',
            'infusionDurationValue' => 'infusion_duration_value',
            'infusionDurationUnit' => 'infusion_duration_unit',
            'clinicalIndication' => 'clinical_indication',
            'quantityPrescribed' => 'quantity_prescribed',
            'prescribedUnit' => 'prescribed_unit',
            'quantityDispensed' => 'quantity_dispensed',
            'dispensedUnit' => 'dispensed_unit',
            'dispensingNotes' => 'dispensing_notes',
            'safetyAcknowledged' => 'safety_acknowledged',
            'safetyOverrideCode' => 'safety_override_code',
            'safetyOverrideReason' => 'safety_override_reason',
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

    private function toPolicyPayload(array $validated): array
    {
        $fieldMap = [
            'formularyDecisionStatus' => 'formulary_decision_status',
            'formularyDecisionReason' => 'formulary_decision_reason',
            'substitutionAllowed' => 'substitution_allowed',
            'substitutionMade' => 'substitution_made',
            'substitutedMedicationCode' => 'substituted_medication_code',
            'substitutedMedicationName' => 'substituted_medication_name',
            'substitutionReason' => 'substitution_reason',
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

    private function toReconciliationPayload(array $validated): array
    {
        $fieldMap = [
            'reconciliationStatus' => 'reconciliation_status',
            'reconciliationDecision' => 'reconciliation_decision',
            'reconciliationNote' => 'reconciliation_note',
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
