<?php

namespace App\Modules\InpatientWard\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InpatientWard\Application\Exceptions\InpatientWardAdmissionNotFoundException;
use App\Modules\InpatientWard\Application\Exceptions\InpatientWardDischargeChecklistAlreadyExistsException;
use App\Modules\InpatientWard\Application\Exceptions\InpatientWardDischargeChecklistStatusNotEligibleException;
use App\Modules\InpatientWard\Application\Exceptions\InpatientWardRoundNoteNotEligibleForAcknowledgementException;
use App\Modules\InpatientWard\Application\UseCases\AcknowledgeInpatientWardRoundNoteUseCase;
use App\Modules\InpatientWard\Application\UseCases\CreateInpatientWardCarePlanUseCase;
use App\Modules\InpatientWard\Application\UseCases\CreateInpatientWardDischargeChecklistUseCase;
use App\Modules\InpatientWard\Application\UseCases\CreateInpatientWardRoundNoteUseCase;
use App\Modules\InpatientWard\Application\UseCases\CreateInpatientWardTaskUseCase;
use App\Modules\InpatientWard\Application\UseCases\GetInpatientWardFollowUpRailUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardRoundNotesUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardCarePlanAuditLogsUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardCarePlansUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardCarePlanStatusCountsUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardCensusUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardDischargeChecklistAuditLogsUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardDischargeChecklistsUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardDischargeChecklistStatusCountsUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardTaskAuditLogsUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardTasksUseCase;
use App\Modules\InpatientWard\Application\UseCases\ListInpatientWardTaskStatusCountsUseCase;
use App\Modules\InpatientWard\Application\UseCases\UpdateInpatientWardCarePlanStatusUseCase;
use App\Modules\InpatientWard\Application\UseCases\UpdateInpatientWardCarePlanUseCase;
use App\Modules\InpatientWard\Application\UseCases\UpdateInpatientWardDischargeChecklistStatusUseCase;
use App\Modules\InpatientWard\Application\UseCases\UpdateInpatientWardDischargeChecklistUseCase;
use App\Modules\InpatientWard\Application\UseCases\UpdateInpatientWardTaskStatusUseCase;
use App\Modules\InpatientWard\Application\UseCases\UpdateInpatientWardTaskUseCase;
use App\Modules\InpatientWard\Presentation\Http\Requests\StoreInpatientWardCarePlanRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\StoreInpatientWardDischargeChecklistRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\StoreInpatientWardRoundNoteRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\StoreInpatientWardTaskRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\UpdateInpatientWardCarePlanRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\UpdateInpatientWardCarePlanStatusRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\UpdateInpatientWardDischargeChecklistRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\UpdateInpatientWardDischargeChecklistStatusRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\UpdateInpatientWardTaskStatusRequest;
use App\Modules\InpatientWard\Presentation\Http\Requests\UpdateInpatientWardTaskRequest;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardCarePlanAuditLogResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardCarePlanResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardCensusRowResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardDischargeChecklistAuditLogResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardDischargeChecklistResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardFollowUpRailResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardRoundNoteResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardTaskAuditLogResponseTransformer;
use App\Modules\InpatientWard\Presentation\Http\Transformers\InpatientWardTaskResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InpatientWardController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function census(Request $request, ListInpatientWardCensusUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InpatientWardCensusRowResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function tasks(Request $request, ListInpatientWardTasksUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InpatientWardTaskResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function taskStatusCounts(Request $request, ListInpatientWardTaskStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }


    public function followUpRail(Request $request, GetInpatientWardFollowUpRailUseCase $useCase): JsonResponse
    {
        $admissionId = trim((string) $request->query('admissionId', ''));

        if ($admissionId === '') {
            return $this->validationError('admissionId', 'Select an inpatient admission first.');
        }

        try {
            $result = $useCase->execute(
                admissionId: $admissionId,
                itemLimit: max(1, min((int) $request->integer('itemLimit', 3), 5)),
            );
        } catch (InpatientWardAdmissionNotFoundException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        }

        return response()->json([
            'data' => InpatientWardFollowUpRailResponseTransformer::transform($result),
        ]);
    }

    public function storeTask(StoreInpatientWardTaskRequest $request, CreateInpatientWardTaskUseCase $useCase): JsonResponse
    {
        try {
            $task = $useCase->execute(
                payload: $this->toTaskPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InpatientWardAdmissionNotFoundException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        }

        return response()->json([
            'data' => InpatientWardTaskResponseTransformer::transform($task),
        ], 201);
    }
    public function updateTask(
        string $id,
        UpdateInpatientWardTaskRequest $request,
        UpdateInpatientWardTaskUseCase $useCase
    ): JsonResponse {
        try {
            $task = $useCase->execute(
                id: $id,
                payload: $this->toTaskPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($task === null, 404, 'Inpatient ward task not found.');

        return response()->json([
            'data' => InpatientWardTaskResponseTransformer::transform($task),
        ]);
    }

    public function updateTaskStatus(
        string $id,
        UpdateInpatientWardTaskStatusRequest $request,
        UpdateInpatientWardTaskStatusUseCase $useCase
    ): JsonResponse {
        try {
            $task = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($task === null, 404, 'Inpatient ward task not found.');

        return response()->json([
            'data' => InpatientWardTaskResponseTransformer::transform($task),
        ]);
    }


    public function roundNotes(Request $request, ListInpatientWardRoundNotesUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InpatientWardRoundNoteResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }
    public function storeRoundNote(
        StoreInpatientWardRoundNoteRequest $request,
        CreateInpatientWardRoundNoteUseCase $useCase
    ): JsonResponse {
        try {
            $note = $useCase->execute(
                payload: $this->toRoundNotePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InpatientWardAdmissionNotFoundException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        }

        return response()->json([
            'data' => InpatientWardRoundNoteResponseTransformer::transform($note),
        ], 201);
    }

    public function acknowledgeRoundNote(
        string $id,
        Request $request,
        AcknowledgeInpatientWardRoundNoteUseCase $useCase
    ): JsonResponse {
        try {
            $note = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InpatientWardRoundNoteNotEligibleForAcknowledgementException $exception) {
            return $this->validationError('handoffNotes', $exception->getMessage());
        }

        abort_if($note === null, 404, 'Inpatient ward round note not found.');

        return response()->json([
            'data' => InpatientWardRoundNoteResponseTransformer::transform($note),
        ]);
    }

    public function carePlans(Request $request, ListInpatientWardCarePlansUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InpatientWardCarePlanResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function carePlanStatusCounts(Request $request, ListInpatientWardCarePlanStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function storeCarePlan(
        StoreInpatientWardCarePlanRequest $request,
        CreateInpatientWardCarePlanUseCase $useCase
    ): JsonResponse {
        try {
            $carePlan = $useCase->execute(
                payload: $this->toCarePlanPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InpatientWardAdmissionNotFoundException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        }

        return response()->json([
            'data' => InpatientWardCarePlanResponseTransformer::transform($carePlan),
        ], 201);
    }

    public function updateCarePlan(
        string $id,
        UpdateInpatientWardCarePlanRequest $request,
        UpdateInpatientWardCarePlanUseCase $useCase
    ): JsonResponse {
        try {
            $carePlan = $useCase->execute(
                id: $id,
                payload: $this->toCarePlanPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($carePlan === null, 404, 'Inpatient ward care plan not found.');

        return response()->json([
            'data' => InpatientWardCarePlanResponseTransformer::transform($carePlan),
        ]);
    }

    public function updateCarePlanStatus(
        string $id,
        UpdateInpatientWardCarePlanStatusRequest $request,
        UpdateInpatientWardCarePlanStatusUseCase $useCase
    ): JsonResponse {
        try {
            $carePlan = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($carePlan === null, 404, 'Inpatient ward care plan not found.');

        return response()->json([
            'data' => InpatientWardCarePlanResponseTransformer::transform($carePlan),
        ]);
    }

    public function carePlanAuditLogs(
        string $id,
        Request $request,
        ListInpatientWardCarePlanAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(carePlanId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Inpatient ward care plan not found.');

        return response()->json([
            'data' => array_map([InpatientWardCarePlanAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportCarePlanAuditLogsCsv(
        string $id,
        Request $request,
        ListInpatientWardCarePlanAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            carePlanId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Inpatient ward care plan not found.');

        $safeId = $this->safeExportIdentifier($id, 'inpatient-ward-care-plan');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('inpatient_ward_care_plan_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    carePlanId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function dischargeChecklists(
        Request $request,
        ListInpatientWardDischargeChecklistsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([InpatientWardDischargeChecklistResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function dischargeChecklistStatusCounts(
        Request $request,
        ListInpatientWardDischargeChecklistStatusCountsUseCase $useCase
    ): JsonResponse {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function storeDischargeChecklist(
        StoreInpatientWardDischargeChecklistRequest $request,
        CreateInpatientWardDischargeChecklistUseCase $useCase
    ): JsonResponse {
        try {
            $checklist = $useCase->execute(
                payload: $this->toDischargeChecklistPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InpatientWardAdmissionNotFoundException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (InpatientWardDischargeChecklistAlreadyExistsException $exception) {
            return $this->validationError('admissionId', $exception->getMessage());
        } catch (InpatientWardDischargeChecklistStatusNotEligibleException $exception) {
            return $this->validationError('status', $exception->getMessage());
        }

        return response()->json([
            'data' => InpatientWardDischargeChecklistResponseTransformer::transform($checklist),
        ], 201);
    }

    public function updateDischargeChecklist(
        string $id,
        UpdateInpatientWardDischargeChecklistRequest $request,
        UpdateInpatientWardDischargeChecklistUseCase $useCase
    ): JsonResponse {
        try {
            $checklist = $useCase->execute(
                id: $id,
                payload: $this->toDischargeChecklistPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InpatientWardDischargeChecklistStatusNotEligibleException $exception) {
            return $this->validationError('status', $exception->getMessage());
        }

        abort_if($checklist === null, 404, 'Inpatient ward discharge checklist not found.');

        return response()->json([
            'data' => InpatientWardDischargeChecklistResponseTransformer::transform($checklist),
        ]);
    }

    public function updateDischargeChecklistStatus(
        string $id,
        UpdateInpatientWardDischargeChecklistStatusRequest $request,
        UpdateInpatientWardDischargeChecklistStatusUseCase $useCase
    ): JsonResponse {
        try {
            $checklist = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (InpatientWardDischargeChecklistStatusNotEligibleException $exception) {
            return $this->validationError('status', $exception->getMessage());
        }

        abort_if($checklist === null, 404, 'Inpatient ward discharge checklist not found.');

        return response()->json([
            'data' => InpatientWardDischargeChecklistResponseTransformer::transform($checklist),
        ]);
    }

    public function dischargeChecklistAuditLogs(
        string $id,
        Request $request,
        ListInpatientWardDischargeChecklistAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(checklistId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Inpatient ward discharge checklist not found.');

        return response()->json([
            'data' => array_map([InpatientWardDischargeChecklistAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportDischargeChecklistAuditLogsCsv(
        string $id,
        Request $request,
        ListInpatientWardDischargeChecklistAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            checklistId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Inpatient ward discharge checklist not found.');

        $safeId = $this->safeExportIdentifier($id, 'inpatient-ward-discharge-checklist');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('inpatient_ward_discharge_checklist_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    checklistId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function taskAuditLogs(string $id, Request $request, ListInpatientWardTaskAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(taskId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Inpatient ward task not found.');

        return response()->json([
            'data' => array_map([InpatientWardTaskAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportTaskAuditLogsCsv(
        string $id,
        Request $request,
        ListInpatientWardTaskAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            taskId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Inpatient ward task not found.');

        $safeId = $this->safeExportIdentifier($id, 'inpatient-ward-task');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('inpatient_ward_task_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    taskId: $id,
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

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    private function toTaskPayload(array $validated): array
    {
        $fieldMap = [
            'admissionId' => 'admission_id',
            'taskType' => 'task_type',
            'title' => 'title',
            'priority' => 'priority',
            'assignedToUserId' => 'assigned_to_user_id',
            'dueAt' => 'due_at',
            'notes' => 'notes',
            'metadata' => 'metadata',
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

    private function toRoundNotePayload(array $validated): array
    {
        $fieldMap = [
            'admissionId' => 'admission_id',
            'roundedAt' => 'rounded_at',
            'shiftLabel' => 'shift_label',
            'roundNote' => 'round_note',
            'carePlan' => 'care_plan',
            'handoffNotes' => 'handoff_notes',
            'metadata' => 'metadata',
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

    private function toCarePlanPayload(array $validated): array
    {
        $fieldMap = [
            'admissionId' => 'admission_id',
            'title' => 'title',
            'planText' => 'plan_text',
            'goals' => 'goals',
            'interventions' => 'interventions',
            'targetDischargeAt' => 'target_discharge_at',
            'reviewDueAt' => 'review_due_at',
            'metadata' => 'metadata',
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

    private function toDischargeChecklistPayload(array $validated): array
    {
        $fieldMap = [
            'admissionId' => 'admission_id',
            'status' => 'status',
            'statusReason' => 'status_reason',
            'clinicalSummaryCompleted' => 'clinical_summary_completed',
            'medicationReconciliationCompleted' => 'medication_reconciliation_completed',
            'followUpPlanCompleted' => 'follow_up_plan_completed',
            'patientEducationCompleted' => 'patient_education_completed',
            'transportArranged' => 'transport_arranged',
            'billingCleared' => 'billing_cleared',
            'documentationCompleted' => 'documentation_completed',
            'notes' => 'notes',
            'metadata' => 'metadata',
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



