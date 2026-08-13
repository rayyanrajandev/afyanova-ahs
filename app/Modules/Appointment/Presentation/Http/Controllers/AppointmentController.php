<?php

namespace App\Modules\Appointment\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AppointmentConsultationTakenOverNotification;
use App\Modules\Appointment\Application\Support\AppointmentConflictMessageFormatter;
use App\Modules\Appointment\Application\Exceptions\ActiveAppointmentConflictException;
use App\Modules\Appointment\Application\Exceptions\ClinicianScheduleConflictException;
use App\Modules\Appointment\Application\Exceptions\InvalidAppointmentReferralTargetFacilityException;
use App\Modules\Appointment\Application\Exceptions\AppointmentConsultationOwnerRequiredException;
use App\Modules\Appointment\Application\Exceptions\InvalidAppointmentStatusTransitionException;
use App\Modules\Appointment\Application\Exceptions\PatientActiveEncounterConflictException;
use App\Modules\Appointment\Application\Exceptions\PatientNotEligibleForAppointmentException;
use App\Modules\Appointment\Application\Exceptions\SourceAdmissionNotEligibleForAppointmentException;
use App\Modules\Appointment\Application\Exceptions\TriageClaimConflictException;
use App\Modules\Appointment\Application\UseCases\ClaimAppointmentTriageUseCase;
use App\Modules\Appointment\Application\UseCases\CreateAppointmentUseCase;
use App\Modules\Appointment\Application\UseCases\CreateAppointmentReferralUseCase;
use App\Modules\Appointment\Application\UseCases\GetAppointmentUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentReferralNetworkUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentReferralNetworkStatusCountsUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentReferralAuditLogsUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentReferralsUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentReferralStatusCountsUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentsUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentDepartmentOptionsUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentStatusCountsUseCase;
use App\Modules\Appointment\Application\UseCases\ListAppointmentAuditLogsUseCase;
use App\Modules\Appointment\Application\UseCases\RecordAppointmentTriageUseCase;
use App\Modules\Appointment\Application\UseCases\ReleaseAppointmentTriageClaimUseCase;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentReferralStatusUseCase;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentReferralUseCase;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentUseCase;
use App\Modules\Appointment\Application\UseCases\OverrideConsultationTypeUseCase;
use App\Modules\Encounter\Application\UseCases\ResolveEncounterForAppointmentUseCase;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterResponseTransformer;
use App\Modules\Encounter\Presentation\Http\Transformers\EncounterWorkspaceResponseTransformer;
use App\Modules\MedicalRecord\Application\Exceptions\AppointmentNotEligibleForMedicalRecordException;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentAuditLogResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Appointment\Presentation\Http\Requests\StoreAppointmentRequest;
use App\Modules\Appointment\Presentation\Http\Requests\StoreAppointmentReferralRequest;
use App\Modules\Appointment\Presentation\Http\Requests\ClaimAppointmentTriageRequest;
use App\Modules\Appointment\Presentation\Http\Requests\RecordAppointmentTriageRequest;
use App\Modules\Appointment\Presentation\Http\Requests\ReleaseAppointmentTriageClaimRequest;
use App\Modules\Appointment\Presentation\Http\Requests\StartAppointmentConsultationRequest;
use App\Modules\Appointment\Presentation\Http\Requests\UpdateAppointmentProviderWorkflowRequest;
use App\Modules\Appointment\Presentation\Http\Requests\UpdateAppointmentRequest;
use App\Modules\Appointment\Presentation\Http\Requests\UpdateAppointmentReferralRequest;
use App\Modules\Appointment\Presentation\Http\Requests\UpdateAppointmentReferralStatusRequest;
use App\Modules\Appointment\Presentation\Http\Requests\UpdateAppointmentStatusRequest;
use App\Modules\Appointment\Presentation\Http\Requests\OverrideConsultationTypeRequest;
use App\Modules\Appointment\Application\Exceptions\AppointmentNotFoundException;
use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentReferralAuditLogResponseTransformer;
use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentReferralResponseTransformer;
use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentResponseTransformer;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Admission\Presentation\Http\Transformers\AdmissionResponseTransformer;
use App\Modules\EmergencyTriage\Presentation\Http\Transformers\EmergencyTriageCaseResponseTransformer;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppointmentController extends Controller
{
    private const AUDIT_CSV_SCHEMA_VERSION = 'audit-log-csv.v1';

    private const AUDIT_CSV_COLUMNS = ['createdAt', 'action', 'actorType', 'actorId', 'changes', 'metadata'];

    public function index(Request $request, ListAppointmentsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([AppointmentResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function statusCounts(Request $request, ListAppointmentStatusCountsUseCase $useCase): JsonResponse
    {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }


    public function departmentOptions(ListAppointmentDepartmentOptionsUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase->execute(),
        ]);
    }
    public function store(StoreAppointmentRequest $request, CreateAppointmentUseCase $useCase): JsonResponse
    {
        try {
            $appointment = $useCase->execute(
                payload: $this->toPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (ActiveAppointmentConflictException $exception) {
            return $this->validationErrorResponse(
                message: $exception->getMessage(),
                errors: [
                    'patientId' => [$exception->getMessage()],
                    'scheduledAt' => [$exception->getMessage()],
                ],
                context: [
                    'activeAppointmentConflict' => AppointmentResponseTransformer::transform(
                        $exception->existingAppointment(),
                    ),
                ],
            );
        } catch (ClinicianScheduleConflictException $exception) {
            return $this->validationErrorResponse(
                message: $exception->getMessage(),
                errors: [
                    'clinicianUserId' => [$exception->getMessage()],
                    'scheduledAt' => [$exception->getMessage()],
                ],
                context: [
                    'clinicianScheduleConflict' => AppointmentResponseTransformer::transform(
                        $exception->existingAppointment(),
                    ),
                ],
            );
        } catch (PatientActiveEncounterConflictException $exception) {
            return $this->patientActiveEncounterConflictResponse($exception);
        } catch (PatientNotEligibleForAppointmentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'patientId' => [$exception->getMessage()],
                ],
            ], 422);
        } catch (SourceAdmissionNotEligibleForAppointmentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'sourceAdmissionId' => [$exception->getMessage()],
                ],
            ], 422);
        }

        // Soft-warning check (2026-08-12, Reception scheduling-duplicate
        // audit): non-blocking, so it runs *after* creation succeeds, not
        // as another assertNo*Conflict() thrown before it — see
        // CreateAppointmentUseCase::findUpcomingConflictWarning()'s own
        // docblock for why this isn't folded into execute() itself.
        $warnings = [];
        $upcomingConflict = $useCase->findUpcomingConflictWarning(
            patientId: (string) $appointment['patient_id'],
            excludeAppointmentId: (string) $appointment['id'],
        );
        if ($upcomingConflict !== null) {
            $warnings[] = [
                'code' => 'appointment.upcoming_conflict',
                'message' => AppointmentConflictMessageFormatter::upcomingConflictWarning($upcomingConflict),
                'existingAppointment' => AppointmentResponseTransformer::transform($upcomingConflict),
            ];
        }

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
            'warnings' => $warnings,
        ], 201);
    }

    public function show(string $id, GetAppointmentUseCase $useCase): JsonResponse
    {
        $appointment = $useCase->execute($id);
        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }

    public function encounter(
        Request $request,
        string $id,
        ResolveEncounterForAppointmentUseCase $useCase,
    ): JsonResponse {
        try {
            $workspace = $useCase->execute(
                appointmentId: $id,
                actorId: $request->user()?->id,
                includeWorkspace: $request->query('view') === 'workspace',
            );
        } catch (AppointmentNotEligibleForMedicalRecordException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'APPOINTMENT_ENCOUNTER_NOT_ELIGIBLE',
                'errors' => [
                    'appointmentId' => [$exception->getMessage()],
                ],
            ], 422);
        }

        abort_if($workspace === null, 404, 'Appointment not found.');

        if ($request->query('view') === 'workspace') {
            return response()->json([
                'data' => EncounterWorkspaceResponseTransformer::transform($workspace),
            ]);
        }

        $encounter = is_array($workspace['encounter'] ?? null) ? $workspace['encounter'] : [];

        return response()->json([
            'data' => EncounterResponseTransformer::transform($encounter),
        ]);
    }

    public function update(string $id, UpdateAppointmentRequest $request, UpdateAppointmentUseCase $useCase): JsonResponse
    {
        try {
            $payload = $this->toPersistencePayload($request->validated());

            $appointment = $useCase->execute(
                id: $id,
                payload: $payload,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (ActiveAppointmentConflictException $exception) {
            return $this->validationErrorResponse(
                message: $exception->getMessage(),
                errors: [
                    'patientId' => [$exception->getMessage()],
                    'scheduledAt' => [$exception->getMessage()],
                ],
                context: [
                    'activeAppointmentConflict' => AppointmentResponseTransformer::transform(
                        $exception->existingAppointment(),
                    ),
                ],
            );
        } catch (ClinicianScheduleConflictException $exception) {
            return $this->validationErrorResponse(
                message: $exception->getMessage(),
                errors: [
                    'clinicianUserId' => [$exception->getMessage()],
                    'scheduledAt' => [$exception->getMessage()],
                ],
                context: [
                    'clinicianScheduleConflict' => AppointmentResponseTransformer::transform(
                        $exception->existingAppointment(),
                    ),
                ],
            );
        } catch (PatientNotEligibleForAppointmentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'patientId' => [$exception->getMessage()],
                ],
            ], 422);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        Gate::authorize('reschedule', $appointment);

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }
    public function recordTriage(
        string $id,
        RecordAppointmentTriageRequest $request,
        RecordAppointmentTriageUseCase $useCase
    ): JsonResponse {
        try {
            $validated = $request->validated();
            $notes = trim((string) $request->input('triageNotes', ''));
            $routing = [];

            if (array_key_exists('department', $validated)) {
                $department = trim((string) ($validated['department'] ?? ''));
                $routing['department'] = $department !== '' ? $department : null;
            }

            if (array_key_exists('clinicianUserId', $validated)) {
                $clinicianUserId = isset($validated['clinicianUserId'])
                    ? (int) $validated['clinicianUserId']
                    : null;
                $routing['clinician_user_id'] = $clinicianUserId !== null && $clinicianUserId > 0
                    ? $clinicianUserId
                    : null;
            }

            $appointment = $useCase->execute(
                id: $id,
                triageVitalsSummary: trim($request->string('triageVitalsSummary')->value()),
                triageNotes: $notes !== '' ? $notes : null,
                triageCategory: $request->filled('triageCategory') ? strtoupper(trim((string) $request->input('triageCategory'))) : null,
                routing: $routing,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }

    public function claimTriage(
        string $id,
        ClaimAppointmentTriageRequest $request,
        ClaimAppointmentTriageUseCase $useCase,
    ): JsonResponse {
        try {
            $appointment = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
                forceTakeover: $request->boolean('forceTakeover'),
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (TriageClaimConflictException $exception) {
            return $this->triageClaimConflictResponse($exception->ownerUserId);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }

    public function releaseTriageClaim(
        string $id,
        ReleaseAppointmentTriageClaimRequest $request,
        ReleaseAppointmentTriageClaimUseCase $useCase,
    ): JsonResponse {
        try {
            $appointment = $useCase->execute(
                id: $id,
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (TriageClaimConflictException $exception) {
            return $this->triageClaimConflictResponse($exception->ownerUserId);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }

    private function triageClaimConflictResponse(int $ownerUserId): JsonResponse
    {
        return response()->json([
            'message' => 'This visit is already claimed by another nurse for triage.',
            'code' => 'TRIAGE_CLAIM_CONFLICT',
            'errors' => [
                'forceTakeover' => ['Triage claim confirmation is required before takeover.'],
            ],
            'context' => [
                'triageOwnerUserId' => $ownerUserId,
                'takeoverAllowed' => true,
            ],
        ], 409);
    }

    public function updateStatus(
        string $id,
        UpdateAppointmentStatusRequest $request,
        UpdateAppointmentStatusUseCase $useCase
    ): JsonResponse {
        try {
            $appointment = $useCase->execute(
                id: $id,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
                isFacilitySuperAdmin: $request->user()?->isFacilitySuperAdminAccess() ?? false,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (InvalidAppointmentStatusTransitionException $exception) {
            return $this->invalidStatusTransitionResponse($exception);
        } catch (AppointmentConsultationOwnerRequiredException $exception) {
            return $this->consultationOwnerRequiredResponse($exception->ownerUserId);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        Gate::authorize('checkIn', $appointment);

        $billingCapture = $useCase->getLastAutoCaptureResult();

        return response()->json(array_merge(
            ['data' => AppointmentResponseTransformer::transform($appointment)],
            $billingCapture !== null ? ['billing_capture' => $billingCapture] : [],
        ));
    }

    private function invalidStatusTransitionResponse(InvalidAppointmentStatusTransitionException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => 'APPOINTMENT_STATUS_TRANSITION_INVALID',
            'errors' => [
                'status' => [$exception->getMessage()],
            ],
        ], 422);
    }

    private function consultationOwnerRequiredResponse(int $ownerUserId): JsonResponse
    {
        return response()->json([
            'message' => 'Only the consultation owner or a facility administrator can change this visit\'s status while it is in consultation.',
            'code' => 'CONSULTATION_OWNER_REQUIRED',
            'errors' => [
                'status' => ['Only the consultation owner or a facility administrator can perform this action.'],
            ],
            'context' => [
                'consultationOwnerUserId' => $ownerUserId,
            ],
        ], 409);
    }

    public function overrideConsultationType(
        string $id,
        OverrideConsultationTypeRequest $request,
        OverrideConsultationTypeUseCase $useCase,
    ): JsonResponse {
        try {
            $appointment = $useCase->execute(
                appointmentId: $id,
                payload: [
                    'consultation_type'                 => $request->input('consultationType'),
                    'consultation_type_override_reason' => $request->input('consultationTypeOverrideReason'),
                ],
                actorId: $request->user()?->id,
            );
        } catch (AppointmentNotFoundException) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(
                message: $e->getMessage(),
                errors: ['consultationType' => [$e->getMessage()]],
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        }

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }

    /**
     * Consultation type volume analytics — NEW vs REVIEW counts over a date window.
     * Supports granularity: daily | weekly | monthly.
     * Optional filters: department, from, to (ISO date strings).
     */
    public function consultationTypeSummary(Request $request, PlatformScopeQueryApplier $scopeApplier): JsonResponse
    {
        $from        = $request->query('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : Carbon::now()->subDays(30)->startOfDay();
        $to          = $request->query('to')   ? Carbon::parse((string) $request->query('to'))->endOfDay()   : Carbon::now()->endOfDay();
        $granularity = (string) $request->query('granularity', 'daily');
        $department  = $request->query('department');

        $dateFormat = match ($granularity) {
            'weekly'  => "TO_CHAR(scheduled_at, 'IYYY-\"W\"IW')",
            'monthly' => "TO_CHAR(scheduled_at, 'YYYY-MM')",
            default   => "TO_CHAR(scheduled_at, 'YYYY-MM-DD')",
        };

        $query = AppointmentModel::query()
            ->whereBetween('scheduled_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'no_show']);

        $scopeApplier->apply($query);

        if ($department) {
            $query->where('department', $department);
        }

        $trends = (clone $query)
            ->select(
                DB::raw("{$dateFormat} as period"),
                DB::raw("COALESCE(consultation_type, 'new') as consultation_type"),
                DB::raw('COUNT(*) as visit_count'),
            )
            ->groupBy('period', 'consultation_type')
            ->orderBy('period')
            ->get()
            ->groupBy('period')
            ->map(fn ($rows) => [
                'period'      => $rows->first()->period,
                'newCount'    => (int) ($rows->firstWhere('consultation_type', 'new')?->visit_count ?? 0),
                'reviewCount' => (int) ($rows->firstWhere('consultation_type', 'review')?->visit_count ?? 0),
            ])
            ->values()
            ->all();

        $totals = (clone $query)
            ->select(
                DB::raw("COALESCE(consultation_type, 'new') as consultation_type"),
                DB::raw('COUNT(*) as visit_count'),
            )
            ->groupBy('consultation_type')
            ->get();

        $newTotal    = (int) ($totals->firstWhere('consultation_type', 'new')?->visit_count ?? 0);
        $reviewTotal = (int) ($totals->firstWhere('consultation_type', 'review')?->visit_count ?? 0);
        $grandTotal  = $newTotal + $reviewTotal;

        return response()->json([
            'data' => [
                'window'  => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'granularity' => $granularity],
                'totals'  => [
                    'new'           => $newTotal,
                    'review'        => $reviewTotal,
                    'total'         => $grandTotal,
                    'reviewRatePct' => $grandTotal > 0 ? round($reviewTotal / $grandTotal * 100, 1) : 0.0,
                ],
                'trends'  => $trends,
            ],
        ]);
    }


    public function startConsultation(
        string $id,
        StartAppointmentConsultationRequest $request,
        GetAppointmentUseCase $getAppointment,
        UpdateAppointmentStatusUseCase $updateStatus,
        AppointmentAuditLogRepositoryInterface $auditLogRepository,
    ): JsonResponse {
        $existing = $getAppointment->execute($id);
        abort_if($existing === null, 404, 'Appointment not found.');
        $actorId = $request->user()?->id;
        $explicitOwnerUserId = $this->normalizeOwnerUserId($existing['consultation_owner_user_id'] ?? null);
        $ownerUserId = $this->resolvedConsultationOwnerUserId($existing);
        $status = (string) ($existing['status'] ?? '');

        if ($status === 'in_consultation') {
            if ($ownerUserId !== null && $actorId !== null && $ownerUserId !== $actorId) {
                if (! $request->boolean('forceTakeover')) {
                    $this->recordBlockedConsultationTakeoverAttempt(
                        auditLogRepository: $auditLogRepository,
                        appointmentId: $id,
                        actorId: $actorId,
                        ownerUserId: $ownerUserId,
                        appointment: $existing,
                    );

                    return $this->consultationOwnerConflictResponse($ownerUserId);
                }

                $takeoverReason = $this->normalizedTakeoverReason($request->input('takeoverReason'));
                $currentTakeoverCount = max((int) ($existing['consultation_takeover_count'] ?? 0), 0);

                try {
                    $appointment = $updateStatus->execute(
                        id: $id,
                        status: 'in_consultation',
                        reason: $existing['status_reason'] ?? null,
                        actorId: $actorId,
                        statusAttributes: [
                            'consultation_owner_user_id' => $actorId,
                            'consultation_owner_assigned_at' => now(),
                            'consultation_takeover_count' => $currentTakeoverCount + 1,
                        ],
                        auditMetadata: [
                            'consultation_takeover' => [
                                'from_owner_user_id' => $ownerUserId,
                                'to_owner_user_id' => $actorId,
                                'reason' => $takeoverReason,
                            ],
                        ],
                    );
                } catch (InvalidAppointmentStatusTransitionException $exception) {
                    return $this->invalidStatusTransitionResponse($exception);
                }

                abort_if($appointment === null, 404, 'Appointment not found.');

                $this->notifyPreviousConsultationOwnerAboutTakeover(
                    previousOwnerUserId: $ownerUserId,
                    replacementOwner: $request->user(),
                    appointment: $appointment,
                    takeoverReason: $takeoverReason,
                );

                return response()->json([
                    'data' => AppointmentResponseTransformer::transform($appointment),
                ]);
            }

            if ($explicitOwnerUserId === null && $actorId !== null) {
                try {
                    $appointment = $updateStatus->execute(
                        id: $id,
                        status: 'in_consultation',
                        reason: $existing['status_reason'] ?? null,
                        actorId: $actorId,
                        statusAttributes: [
                            'consultation_owner_user_id' => $actorId,
                            'consultation_owner_assigned_at' => now(),
                            'consultation_started_at' => $existing['consultation_started_at'] ?? now(),
                        ],
                        auditMetadata: [
                            'consultation_owner_assigned' => true,
                        ],
                    );
                } catch (InvalidAppointmentStatusTransitionException $exception) {
                    return $this->invalidStatusTransitionResponse($exception);
                }

                abort_if($appointment === null, 404, 'Appointment not found.');

                return response()->json([
                    'data' => AppointmentResponseTransformer::transform($appointment),
                ]);
            }

            return response()->json([
                'data' => AppointmentResponseTransformer::transform($existing),
            ]);
        }

        if ($status !== 'waiting_provider') {
            return $this->validationErrorResponse(
                message: 'Only provider-ready visits can be started from this action.',
                errors: [
                    'status' => ['Move the visit into the provider-ready queue before starting consultation, or take over the active consultation owner session.'],
                ],
                context: [
                    'currentStatus' => $existing['status'] ?? null,
                ],
            );
        }

        try {
            $appointment = $updateStatus->execute(
                id: $id,
                status: 'in_consultation',
                reason: null,
                actorId: $actorId,
                statusAttributes: [
                    'consultation_started_at' => now(),
                    'consultation_owner_user_id' => $actorId,
                    'consultation_owner_assigned_at' => now(),
                ],
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (InvalidAppointmentStatusTransitionException $exception) {
            return $this->invalidStatusTransitionResponse($exception);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentResponseTransformer::transform($appointment),
        ]);
    }


    public function updateProviderWorkflow(
        string $id,
        UpdateAppointmentProviderWorkflowRequest $request,
        GetAppointmentUseCase $getAppointment,
        UpdateAppointmentStatusUseCase $updateStatus,
        MedicalRecordRepositoryInterface $medicalRecordRepository,
    ): JsonResponse {
        $existing = $getAppointment->execute($id);
        abort_if($existing === null, 404, 'Appointment not found.');

        $targetStatus = $request->string('status')->value();
        $currentStatus = $existing['status'] ?? null;
        $actorId = $request->user()?->id;
        $allowedTransitions = [
            'waiting_provider' => ['waiting_triage'],
            'in_consultation' => ['waiting_provider', 'waiting_triage', 'completed'],
        ];

        if (
            $currentStatus === 'in_consultation'
            && $actorId !== null
            && ($ownerUserId = $this->resolvedConsultationOwnerUserId($existing)) !== null
            && $ownerUserId !== $actorId
        ) {
            return $this->consultationOwnerConflictResponse($ownerUserId);
        }

        if ($currentStatus === $targetStatus) {
            return response()->json([
                'data' => AppointmentResponseTransformer::transform($existing),
            ]);
        }

        if (!in_array($targetStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            return $this->validationErrorResponse(
                message: 'This provider workflow action is not allowed from the current visit step.',
                errors: [
                    'status' => ['Choose a provider workflow action that matches the current visit state.'],
                ],
                context: [
                    'currentStatus' => $currentStatus,
                    'requestedStatus' => $targetStatus,
                ],
            );
        }

        if ($currentStatus === 'in_consultation' && $targetStatus === 'completed') {
            $hasDraftConsultationNote = $medicalRecordRepository
                ->hasDraftConsultationNoteForAppointment($id);
            $hasSignedConsultationNote = $medicalRecordRepository
                ->hasSignedConsultationNoteForAppointment($id);

            if ($hasDraftConsultationNote || ! $hasSignedConsultationNote) {
                return $this->validationErrorResponse(
                    message: 'Finalize the consultation note before closing this visit.',
                    errors: [
                        'status' => ['Closing the visit requires a finalized consultation note.'],
                    ],
                    context: [
                        'requiresFinalizedConsultationNote' => true,
                        'hasDraftConsultationNote' => $hasDraftConsultationNote,
                        'hasSignedConsultationNote' => $hasSignedConsultationNote,
                    ],
                );
            }
        }

        try {
            $statusAttributes = [];
            if ($currentStatus === 'in_consultation') {
                $statusAttributes = [
                    'consultation_owner_user_id' => null,
                    'consultation_owner_assigned_at' => null,
                ];

                // Preserve consultation_started_at when returning to
                // waiting_provider (sent out for labs, will return) — this is
                // the signal GetActiveVisitJourneyUseCase uses to distinguish
                // "on hold" (waiting_clinician_review) from "never yet seen a
                // provider" (waiting_clinician). Previously this was nulled
                // unconditionally on every exit from in_consultation, which
                // meant that distinction could never actually fire in
                // practice — only clear it when the visit leaves the
                // provider-review flow entirely (sent back to triage) or
                // closes (completed/cancelled).
                if (! in_array($targetStatus, ['in_consultation', 'waiting_provider'], true)) {
                    $statusAttributes['consultation_started_at'] = null;
                }
            }

            $appointment = $updateStatus->execute(
                id: $id,
                status: $targetStatus,
                reason: $request->input('reason'),
                actorId: $actorId,
                statusAttributes: $statusAttributes,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (InvalidAppointmentStatusTransitionException $exception) {
            return $this->invalidStatusTransitionResponse($exception);
        }

        abort_if($appointment === null, 404, 'Appointment not found.');

        $billingCapture = $updateStatus->getLastAutoCaptureResult();

        return response()->json(array_merge(
            ['data' => AppointmentResponseTransformer::transform($appointment)],
            $billingCapture !== null ? ['billing_capture' => $billingCapture] : [],
        ));
    }

    public function referrals(
        string $id,
        Request $request,
        ListAppointmentReferralsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => array_map([AppointmentReferralResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function referralStatusCounts(
        string $id,
        Request $request,
        ListAppointmentReferralStatusCountsUseCase $useCase
    ): JsonResponse {
        $counts = $useCase->execute($id, $request->all());
        abort_if($counts === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function referralNetwork(
        Request $request,
        ListAppointmentReferralNetworkUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($request->all());

        return response()->json([
            'data' => array_map([AppointmentReferralResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function referralNetworkStatusCounts(
        Request $request,
        ListAppointmentReferralNetworkStatusCountsUseCase $useCase
    ): JsonResponse {
        $counts = $useCase->execute($request->all());

        return response()->json([
            'data' => $counts,
        ]);
    }

    public function storeReferral(
        string $id,
        StoreAppointmentReferralRequest $request,
        CreateAppointmentReferralUseCase $useCase
    ): JsonResponse {
        try {
            $referral = $useCase->execute(
                appointmentId: $id,
                payload: $this->toReferralPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (InvalidAppointmentReferralTargetFacilityException $exception) {
            return $this->validationErrorResponse(
                message: $exception->getMessage(),
                errors: $exception->errors(),
            );
        }

        abort_if($referral === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => AppointmentReferralResponseTransformer::transform($referral),
        ], 201);
    }

    public function updateReferral(
        string $id,
        string $referralId,
        UpdateAppointmentReferralRequest $request,
        UpdateAppointmentReferralUseCase $useCase
    ): JsonResponse {
        try {
            $referral = $useCase->execute(
                appointmentId: $id,
                referralId: $referralId,
                payload: $this->toReferralPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        } catch (InvalidAppointmentReferralTargetFacilityException $exception) {
            return $this->validationErrorResponse(
                message: $exception->getMessage(),
                errors: $exception->errors(),
            );
        }

        abort_if($referral === null, 404, 'Appointment referral not found.');

        return response()->json([
            'data' => AppointmentReferralResponseTransformer::transform($referral),
        ]);
    }

    public function updateReferralStatus(
        string $id,
        string $referralId,
        UpdateAppointmentReferralStatusRequest $request,
        UpdateAppointmentReferralStatusUseCase $useCase
    ): JsonResponse {
        try {
            $referral = $useCase->execute(
                appointmentId: $id,
                referralId: $referralId,
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                handoffNotes: $request->input('handoffNotes'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredResponse($exception->getMessage());
        }

        abort_if($referral === null, 404, 'Appointment referral not found.');

        return response()->json([
            'data' => AppointmentReferralResponseTransformer::transform($referral),
        ]);
    }

    public function referralAuditLogs(
        string $id,
        string $referralId,
        Request $request,
        ListAppointmentReferralAuditLogsUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute(
            appointmentId: $id,
            referralId: $referralId,
            filters: $request->all(),
        );
        abort_if($result === null, 404, 'Appointment referral not found.');

        return response()->json([
            'data' => array_map([AppointmentReferralAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportReferralAuditLogsCsv(
        string $id,
        string $referralId,
        Request $request,
        ListAppointmentReferralAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            appointmentId: $id,
            referralId: $referralId,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Appointment referral not found.');

        $safeReferralId = $this->safeExportIdentifier($referralId, 'referral');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('appointment_referral_audit_%s_%s', $safeReferralId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $referralId, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    appointmentId: $id,
                    referralId: $referralId,
                    filters: $pageFilters,
                );
            },
        );
    }

    public function auditLogs(string $id, Request $request, ListAppointmentAuditLogsUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute(appointmentId: $id, filters: $request->all());
        abort_if($result === null, 404, 'Appointment not found.');

        return response()->json([
            'data' => array_map([AppointmentAuditLogResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function exportAuditLogsCsv(
        string $id,
        Request $request,
        ListAppointmentAuditLogsUseCase $useCase
    ): StreamedResponse {
        $filters = $request->all();
        $filters['page'] = 1;
        $filters['perPage'] = 100;

        $firstPage = $useCase->execute(
            appointmentId: $id,
            filters: $filters,
        );
        abort_if($firstPage === null, 404, 'Appointment not found.');

        $safeId = $this->safeExportIdentifier($id, 'appointment');

        return $this->streamAuditLogCsvExport(
            baseName: sprintf('appointment_audit_%s_%s', $safeId, now()->format('Ymd_His')),
            firstPage: $firstPage,
            fetchPage: function (int $page) use ($useCase, $id, $filters): ?array {
                $pageFilters = $filters;
                $pageFilters['page'] = $page;

                return $useCase->execute(
                    appointmentId: $id,
                    filters: $pageFilters,
                );
            },
        );
    }


    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'patientId' => 'patient_id',
            'sourceAdmissionId' => 'source_admission_id',
            'clinicianUserId' => 'clinician_user_id',
            'department' => 'department',
            'scheduledAt' => 'scheduled_at',
            'durationMinutes' => 'duration_minutes',
            'reason' => 'reason',
            'notes' => 'notes',
            'financialClass' => 'financial_coverage_type',
            'billingPayerContractId' => 'billing_payer_contract_id',
            'coverageReference' => 'coverage_reference',
            'coverageNotes' => 'coverage_notes',
            'appointmentType' => 'appointment_type',
        ];

        $payload = [];

        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        if (! array_key_exists('duration_minutes', $payload)) {
            $payload['duration_minutes'] = 30;
        }

        return $payload;
    }

    private function toReferralPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'referralType' => 'referral_type',
            'priority' => 'priority',
            'targetDepartment' => 'target_department',
            'targetFacilityId' => 'target_facility_id',
            'targetFacilityCode' => 'target_facility_code',
            'targetFacilityName' => 'target_facility_name',
            'targetClinicianUserId' => 'target_clinician_user_id',
            'referralReason' => 'referral_reason',
            'clinicalNotes' => 'clinical_notes',
            'handoffNotes' => 'handoff_notes',
            'requestedAt' => 'requested_at',
            'status' => 'status',
            'statusReason' => 'status_reason',
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

    private function tenantScopeRequiredResponse(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationErrorResponse(string $message, array $errors, array $context = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'VALIDATION_ERROR',
            'errors' => $errors,
            'context' => $context === [] ? null : $context,
        ], 422);
    }

    private function patientActiveEncounterConflictResponse(PatientActiveEncounterConflictException $exception): JsonResponse
    {
        $conflictType = $exception->conflictType();
        $existingRecord = $exception->existingRecord();

        return $this->validationErrorResponse(
            message: $exception->getMessage(),
            errors: [
                'patientId' => [$exception->getMessage()],
            ],
            context: [
                'activePatientEncounterConflict' => [
                    'conflictType' => $conflictType,
                    'record' => $conflictType === 'emergency_case'
                        ? EmergencyTriageCaseResponseTransformer::transform($existingRecord)
                        : AdmissionResponseTransformer::transform($existingRecord),
                ],
            ],
        );
    }

    private function consultationOwnerConflictResponse(int $ownerUserId): JsonResponse
    {
        return response()->json([
            'message' => 'This consultation is currently owned by another clinician. Confirm takeover to continue.',
            'code' => 'CONSULTATION_OWNER_CONFLICT',
            'errors' => [
                'forceTakeover' => ['Consultation ownership confirmation is required before takeover.'],
            ],
            'context' => [
                'consultationOwnerUserId' => $ownerUserId,
                'takeoverAllowed' => true,
            ],
        ], 409);
    }

    private function normalizeOwnerUserId(mixed $value): ?int
    {
        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    /**
     * Legacy active consultations may not have explicit ownership stored yet.
     * Treat the assigned clinician as the effective owner until the record is
     * touched again and the ownership field is repaired.
     *
     * @param  array<string, mixed>  $appointment
     */
    private function resolvedConsultationOwnerUserId(array $appointment): ?int
    {
        $explicitOwnerUserId = $this->normalizeOwnerUserId($appointment['consultation_owner_user_id'] ?? null);
        if ($explicitOwnerUserId !== null) {
            return $explicitOwnerUserId;
        }

        $status = strtolower(trim((string) ($appointment['status'] ?? '')));
        if ($status !== 'in_consultation') {
            return null;
        }

        return $this->normalizeOwnerUserId($appointment['clinician_user_id'] ?? null);
    }

    private function normalizedTakeoverReason(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $appointment
     */
    private function recordBlockedConsultationTakeoverAttempt(
        AppointmentAuditLogRepositoryInterface $auditLogRepository,
        string $appointmentId,
        ?int $actorId,
        int $ownerUserId,
        array $appointment,
    ): void {
        $auditLogRepository->write(
            appointmentId: $appointmentId,
            action: 'appointment.consultation.takeover.blocked',
            actorId: $actorId,
            metadata: [
                'consultation_takeover_blocked' => [
                    'from_owner_user_id' => $ownerUserId,
                    'attempted_by_user_id' => $actorId,
                    'current_status' => $appointment['status'] ?? null,
                    'requires_confirmation' => true,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $appointment
     */
    private function notifyPreviousConsultationOwnerAboutTakeover(
        int $previousOwnerUserId,
        mixed $replacementOwner,
        array $appointment,
        ?string $takeoverReason,
    ): void {
        $previousOwner = User::query()->find($previousOwnerUserId);
        if ($previousOwner === null) {
            return;
        }

        $appointmentId = trim((string) ($appointment['id'] ?? ''));
        if ($appointmentId === '') {
            return;
        }

        $appointmentNumber = trim((string) ($appointment['appointment_number'] ?? ''));
        if ($appointmentNumber === '') {
            $appointmentNumber = $appointmentId;
        }

        $replacementOwnerName = trim((string) data_get($replacementOwner, 'name', ''));
        if ($replacementOwnerName === '') {
            $replacementOwnerName = 'another clinician';
        }

        try {
            $previousOwner->notify(new AppointmentConsultationTakenOverNotification(
                appointmentId: $appointmentId,
                appointmentNumber: $appointmentNumber,
                newOwnerName: $replacementOwnerName,
                takeoverReason: $takeoverReason,
                takenOverAt: now(),
            ));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}

