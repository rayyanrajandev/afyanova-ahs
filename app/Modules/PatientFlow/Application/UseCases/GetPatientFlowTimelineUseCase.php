<?php

namespace App\Modules\PatientFlow\Application\UseCases;

use App\Modules\PatientFlow\Domain\Repositories\PatientFlowEventRepositoryInterface;

/**
 * The per-patient activity log the flow ticket asked for — "Called by Dr. X at
 * 10:42", "Nursing: vitals recorded at 10:50".
 *
 * This is a straight read of patient_flow_events with no derivation at all,
 * which is the point: before the log existed, this view was impossible to
 * build honestly. The nearest thing available was
 * /reception/patients/{id}/activity-feed, which reads patient_audit_logs —
 * demographic record changes — and so could never answer who moved a patient
 * through their visit.
 */
class GetPatientFlowTimelineUseCase
{
    private const MAX_PER_PAGE = 50;

    private const DEFAULT_PER_PAGE = 25;

    private const VISIT_TIMELINE_LIMIT = 200;

    public function __construct(
        private readonly PatientFlowEventRepositoryInterface $repository,
    ) {}

    /**
     * Newest first — a paginated history for the patient record view.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function forPatient(string $patientId, int $page = 1, ?int $perPage = null): array
    {
        return $this->repository->listForPatient(
            patientId: $patientId,
            page: max($page, 1),
            perPage: min(max($perPage ?? self::DEFAULT_PER_PAGE, 1), self::MAX_PER_PAGE),
        );
    }

    /**
     * Oldest first — the sequence of one visit, as staff read it on a patient
     * card. Unpaginated but bounded: a single visit producing more than
     * VISIT_TIMELINE_LIMIT transitions indicates a loop somewhere, and
     * truncating is better than streaming an unbounded log into a card.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forVisit(?string $appointmentId, ?string $serviceRequestId): array
    {
        return $this->repository->listForVisit(
            appointmentId: $appointmentId,
            serviceRequestId: $serviceRequestId,
            limit: self::VISIT_TIMELINE_LIMIT,
        );
    }
}
