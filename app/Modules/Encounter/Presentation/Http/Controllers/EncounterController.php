<?php

namespace App\Modules\Encounter\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encounter\Application\Exceptions\EncounterCloseBlockedException;
use App\Modules\Encounter\Application\Exceptions\EncounterOwnerConflictException;
use App\Modules\Encounter\Application\Exceptions\InvalidEncounterStatusTransitionException;
use App\Modules\Encounter\Application\UseCases\GetEncounterUseCase;
use App\Modules\Encounter\Application\UseCases\GetEncounterWorkspaceUseCase;
use App\Modules\Encounter\Application\UseCases\ListEncounterAuditLogsUseCase;
use App\Modules\Encounter\Application\UseCases\ListEncounterStatusCountsUseCase;
use App\Modules\Encounter\Application\UseCases\ListEncountersUseCase;
use App\Modules\Encounter\Application\UseCases\ResolveEncounterForAppointmentUseCase;
use App\Modules\Encounter\Application\UseCases\UpdateEncounterStatusUseCase;
use App\Modules\MedicalRecord\Application\Exceptions\AppointmentNotEligibleForMedicalRecordException;
use App\Modules\Encounter\Presentation\Http\Requests\UpdateEncounterStatusRequest;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterAuditLogResponseTransformer;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterListItemResponseTransformer;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterResponseTransformer;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterWorkspaceResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Support\CanonicalEncounterState\CanonicalEncounterShadowLogger;
use App\Support\CanonicalEncounterState\CanonicalEncounterStateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EncounterController extends Controller
{
    public function index(Request $request, ListEncountersUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([EncounterListItemResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListEncounterStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function show(
        Request $request,
        string $id,
        GetEncounterUseCase $useCase,
        GetEncounterWorkspaceUseCase $workspaceUseCase,
        CanonicalEncounterStateResolver $canonicalStateResolver,
        CanonicalEncounterShadowLogger $canonicalStateLogger,
    ): JsonResponse {
        if ($request->query('view') === 'workspace') {
            $workspace = $workspaceUseCase->execute($id);
            abort_if($workspace === null, 404, 'Encounter not found.');

            // Shadow Mode only (Mode B) — non-blocking, never affects this response.
            // See reports/encounter-state-machine-design/01-integration-and-migration-architecture.md §3.
            $this->runCanonicalEncounterStateShadowEvaluation($id, $canonicalStateResolver, $canonicalStateLogger);

            return response()->json([
                'data' => EncounterWorkspaceResponseTransformer::transform($workspace),
            ]);
        }

        $encounter = $useCase->execute($id);
        abort_if($encounter === null, 404, 'Encounter not found.');

        return response()->json([
            'data' => EncounterResponseTransformer::transform($encounter),
        ]);
    }

    public function resolveForAppointment(
        Request $request,
        string $appointmentId,
        ResolveEncounterForAppointmentUseCase $useCase,
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                appointmentId: $appointmentId,
                actorId: $request->user()?->id,
                includeWorkspace: $request->query('view') === 'workspace',
            );
        } catch (AppointmentNotEligibleForMedicalRecordException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'APPOINTMENT_NOT_ELIGIBLE_FOR_ENCOUNTER',
            ], 422);
        }

        abort_if($result === null, 404, 'Appointment not found.');

        if ($request->query('view') === 'workspace') {
            return response()->json([
                'data' => EncounterWorkspaceResponseTransformer::transform($result),
            ]);
        }

        $encounter = is_array($result['encounter'] ?? null) ? $result['encounter'] : [];

        return response()->json([
            'data' => EncounterResponseTransformer::transform($encounter),
        ]);
    }

    public function updateStatus(
        string $id,
        UpdateEncounterStatusRequest $request,
        UpdateEncounterStatusUseCase $useCase,
    ): JsonResponse {
        try {
            $encounter = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
                acknowledgeCloseGaps: (bool) $request->boolean('acknowledgeCloseGaps'),
                disposition: $request->input('disposition'),
                dispositionNotes: $request->input('dispositionNotes'),
                isFacilitySuperAdmin: $request->user()?->isFacilitySuperAdminAccess() ?? false,
            );
        } catch (EncounterOwnerConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'ENCOUNTER_OWNER_REQUIRED',
                'errors' => [
                    'status' => ['Only the encounter\'s primary clinician or a facility administrator can perform this action.'],
                ],
                'context' => [
                    'encounterOwnerUserId' => $exception->ownerUserId,
                ],
            ], 409);
        } catch (EncounterCloseBlockedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'ENCOUNTER_CLOSE_BLOCKED',
                'data' => [
                    'closeReadiness' => $exception->readiness(),
                ],
            ], 422);
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return response()->json([
                'code' => 'TENANT_SCOPE_REQUIRED',
                'message' => $exception->getMessage(),
            ], 403);
        } catch (InvalidEncounterStatusTransitionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'ENCOUNTER_STATUS_TRANSITION_INVALID',
                'errors' => [
                    'status' => [$exception->getMessage()],
                ],
            ], 422);
        }

        abort_if($encounter === null, 404, 'Encounter not found.');

        return response()->json([
            'data' => EncounterResponseTransformer::transform($encounter),
        ]);
    }

    public function auditLogs(
        string $id,
        Request $request,
        ListEncounterAuditLogsUseCase $useCase,
    ): JsonResponse {
        $result = $useCase->execute(encounterId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Encounter not found.');

        return response()->json([
            'data' => array_map([EncounterAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListEncounterAuditLogsUseCase $useCase,
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(encounterId: $id, filters: $filters);
        abort_if($firstPage === null, 404, 'Encounter not found.');

        $safeId = $this->safeExportIdentifier($id, 'encounter');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('encounter_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(encounterId: $id, filters: $pageFilters);
            },
        );
    }

    /**
     * Canonical Encounter State — Shadow Mode only. Config-gated (off by default,
     * i.e. Mode A / Legacy Only unless CANONICAL_ENCOUNTER_SHADOW_MODE_ENABLED=true),
     * fully try/catch isolated, and never merged into any response. See
     * reports/encounter-state-machine-design/01-integration-and-migration-architecture.md.
     */
    private function runCanonicalEncounterStateShadowEvaluation(
        string $encounterId,
        CanonicalEncounterStateResolver $resolver,
        CanonicalEncounterShadowLogger $logger,
    ): void {
        if (! config('canonical_encounter_state.shadow_mode_enabled')) {
            return;
        }

        try {
            $snapshot = $resolver->resolve($encounterId);
            if ($snapshot !== null) {
                $logger->log($snapshot);
            }
        } catch (Throwable $exception) {
            $logger->logFailure($encounterId, $exception);
        }
    }
}
