<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Application\UseCases\CreateMultiFacilityRolloutIncidentUseCase;
use App\Modules\Platform\Application\UseCases\CreateMultiFacilityRolloutPlanUseCase;
use App\Modules\Platform\Application\UseCases\ExecuteMultiFacilityRolloutRollbackUseCase;
use App\Modules\Platform\Application\UseCases\GetMultiFacilityRolloutPlanUseCase;
use App\Modules\Platform\Application\UseCases\ListMultiFacilityRolloutAuditLogsUseCase;
use App\Modules\Platform\Application\UseCases\ListMultiFacilityRolloutPlansUseCase;
use App\Modules\Platform\Application\UseCases\UpdateMultiFacilityRolloutAcceptanceUseCase;
use App\Modules\Platform\Application\UseCases\UpdateMultiFacilityRolloutIncidentUseCase;
use App\Modules\Platform\Application\UseCases\UpdateMultiFacilityRolloutPlanUseCase;
use App\Modules\Platform\Application\UseCases\UpsertMultiFacilityRolloutCheckpointsUseCase;
use App\Modules\Platform\Presentation\Http\Requests\ExecuteMultiFacilityRolloutRollbackRequest;
use App\Modules\Platform\Presentation\Http\Requests\StoreMultiFacilityRolloutIncidentRequest;
use App\Modules\Platform\Presentation\Http\Requests\StoreMultiFacilityRolloutPlanRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdateMultiFacilityRolloutAcceptanceRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdateMultiFacilityRolloutIncidentRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpdateMultiFacilityRolloutPlanRequest;
use App\Modules\Platform\Presentation\Http\Requests\UpsertMultiFacilityRolloutCheckpointsRequest;
use App\Modules\Platform\Presentation\Http\Transformers\MultiFacilityRolloutAuditLogResponseTransformer;
use App\Modules\Platform\Presentation\Http\Transformers\MultiFacilityRolloutPlanResponseTransformer;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MultiFacilityRolloutController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListMultiFacilityRolloutPlansUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([MultiFacilityRolloutPlanResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function store(StoreMultiFacilityRolloutPlanRequest $request, CreateMultiFacilityRolloutPlanUseCase $useCase): JsonResponse
    {
        try {
            $plan = $useCase->execute(
                payload: $this->toPlanPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError($this->rolloutDomainErrorField($exception->getMessage()), $exception->getMessage());
        }

        return response()->json([
            'data' => MultiFacilityRolloutPlanResponseTransformer::transform($plan),
        ], 201);
    }

    public function show(string $id, GetMultiFacilityRolloutPlanUseCase $useCase): JsonResponse
    {
        $plan = $useCase->execute($id);
        abort_if($plan === null, 404, 'Rollout plan not found.');

        return response()->json([
            'data' => MultiFacilityRolloutPlanResponseTransformer::transform($plan),
        ]);
    }

    public function update(
        string $id,
        UpdateMultiFacilityRolloutPlanRequest $request,
        UpdateMultiFacilityRolloutPlanUseCase $useCase
    ): JsonResponse {
        try {
            $plan = $useCase->execute(
                id: $id,
                payload: $this->toPlanPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError($this->rolloutDomainErrorField($exception->getMessage()), $exception->getMessage());
        }

        abort_if($plan === null, 404, 'Rollout plan not found.');

        return response()->json([
            'data' => MultiFacilityRolloutPlanResponseTransformer::transform($plan),
        ]);
    }

    public function upsertCheckpoints(
        string $id,
        UpsertMultiFacilityRolloutCheckpointsRequest $request,
        UpsertMultiFacilityRolloutCheckpointsUseCase $useCase
    ): JsonResponse {
        try {
            $plan = $useCase->execute(
                rolloutPlanId: $id,
                checkpoints: $this->toCheckpointPayload((array) ($request->validated()['checkpoints'] ?? [])),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($plan === null, 404, 'Rollout plan not found.');

        return response()->json([
            'data' => MultiFacilityRolloutPlanResponseTransformer::transform($plan),
        ]);
    }

    public function createIncident(
        string $id,
        StoreMultiFacilityRolloutIncidentRequest $request,
        CreateMultiFacilityRolloutIncidentUseCase $useCase
    ): JsonResponse {
        try {
            $plan = $useCase->execute(
                rolloutPlanId: $id,
                payload: $this->toIncidentPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError('incidentCode', $exception->getMessage());
        }

        abort_if($plan === null, 404, 'Rollout plan not found.');

        return response()->json([
            'data' => MultiFacilityRolloutPlanResponseTransformer::transform($plan),
        ], 201);
    }

    public function updateIncident(
        string $id,
        string $incidentId,
        UpdateMultiFacilityRolloutIncidentRequest $request,
        UpdateMultiFacilityRolloutIncidentUseCase $useCase
    ): JsonResponse {
        try {
            $plan = $useCase->execute(
                rolloutPlanId: $id,
                incidentId: $incidentId,
                payload: $this->toIncidentPayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError('payload', $exception->getMessage());
        }

        abort_if($plan === null, 404, 'Rollout plan or incident not found.');

        return response()->json([
            'data' => MultiFacilityRolloutPlanResponseTransformer::transform($plan),
        ]);
    }

    public function executeRollback(
        string $id,
        ExecuteMultiFacilityRolloutRollbackRequest $request,
        ExecuteMultiFacilityRolloutRollbackUseCase $useCase
    ): JsonResponse {
        try {
            $plan = $useCase->execute(
                rolloutPlanId: $id,
                reason: (string) $request->validated('reason'),
                approvalCaseReference: (string) $request->validated('approvalCaseReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError('reason', $exception->getMessage());
        }

        abort_if($plan === null, 404, 'Rollout plan not found.');

        return response()->json([
            'data' => MultiFacilityRolloutPlanResponseTransformer::transform($plan),
        ]);
    }

    public function updateAcceptance(
        string $id,
        UpdateMultiFacilityRolloutAcceptanceRequest $request,
        UpdateMultiFacilityRolloutAcceptanceUseCase $useCase
    ): JsonResponse {
        try {
            $plan = $useCase->execute(
                rolloutPlanId: $id,
                acceptanceStatus: (string) $request->validated('acceptanceStatus'),
                trainingCompletedAt: $request->input('trainingCompletedAt'),
                acceptanceCaseReference: $request->input('acceptanceCaseReference'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        } catch (DomainException $exception) {
            return $this->validationError('acceptanceStatus', $exception->getMessage());
        }

        abort_if($plan === null, 404, 'Rollout plan not found.');

        return response()->json([
            'data' => MultiFacilityRolloutPlanResponseTransformer::transform($plan),
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListMultiFacilityRolloutAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Rollout plan not found.');

        return response()->json([
            'data' => array_map([MultiFacilityRolloutAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListMultiFacilityRolloutAuditLogsUseCase $useCase
    ): JsonResponse|StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute($id, $filters);
        abort_if($firstPage === null, 404, 'Rollout plan not found.');

        $safeId = $this->safeExportIdentifier($id, 'rollout');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('platform_multi_facility_rollout_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute($id, $pageFilters);
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

    private function rolloutDomainErrorField(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'facility')) {
            return 'facilityId';
        }

        if (str_contains($normalized, 'targetgoliveat')) {
            return 'targetGoLiveAt';
        }

        if (str_contains($normalized, 'owneruserid')) {
            return 'ownerUserId';
        }

        if (str_contains($normalized, 'status')) {
            return 'status';
        }

        if (str_contains($normalized, 'metadata')) {
            return 'metadata';
        }

        return 'rolloutCode';
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function toPlanPayload(array $validated): array
    {
        $payload = [];

        if (array_key_exists('facilityId', $validated)) {
            $payload['facility_id'] = $validated['facilityId'];
        }

        if (array_key_exists('rolloutCode', $validated)) {
            $payload['rollout_code'] = $validated['rolloutCode'];
        }

        if (array_key_exists('status', $validated)) {
            $payload['status'] = $validated['status'];
        }

        if (array_key_exists('targetGoLiveAt', $validated)) {
            $payload['target_go_live_at'] = $validated['targetGoLiveAt'];
        }

        if (array_key_exists('actualGoLiveAt', $validated)) {
            $payload['actual_go_live_at'] = $validated['actualGoLiveAt'];
        }

        if (array_key_exists('ownerUserId', $validated)) {
            $payload['owner_user_id'] = $validated['ownerUserId'];
        }

        if (array_key_exists('metadata', $validated)) {
            $payload['metadata'] = $validated['metadata'];
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checkpoints
     * @return array<int, array<string, mixed>>
     */
    private function toCheckpointPayload(array $checkpoints): array
    {
        return array_map(static fn (array $checkpoint): array => [
            'checkpoint_code' => $checkpoint['checkpointCode'] ?? null,
            'checkpoint_name' => $checkpoint['checkpointName'] ?? null,
            'status' => $checkpoint['status'] ?? null,
            'decision_notes' => $checkpoint['decisionNotes'] ?? null,
        ], $checkpoints);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function toIncidentPayload(array $validated): array
    {
        $payload = [];

        if (array_key_exists('incidentCode', $validated)) {
            $payload['incident_code'] = $validated['incidentCode'];
        }

        if (array_key_exists('severity', $validated)) {
            $payload['severity'] = $validated['severity'];
        }

        if (array_key_exists('status', $validated)) {
            $payload['status'] = $validated['status'];
        }

        if (array_key_exists('summary', $validated)) {
            $payload['summary'] = $validated['summary'];
        }

        if (array_key_exists('details', $validated)) {
            $payload['details'] = $validated['details'];
        }

        if (array_key_exists('escalatedTo', $validated)) {
            $payload['escalated_to'] = $validated['escalatedTo'];
        }

        if (array_key_exists('openedAt', $validated)) {
            $payload['opened_at'] = $validated['openedAt'];
        }

        if (array_key_exists('resolvedAt', $validated)) {
            $payload['resolved_at'] = $validated['resolvedAt'];
        }

        return $payload;
    }
}
