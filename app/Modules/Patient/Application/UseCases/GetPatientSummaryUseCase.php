<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Admission\Domain\ValueObjects\AdmissionStatus;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Domain\Repositories\PatientInsuranceRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Encounter\Application\UseCases\ListEncountersUseCase;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Domain\Repositories\PatientAllergyRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\PatientFlow\Application\UseCases\GetActiveVisitJourneyUseCase;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ClinicalProcedure\Domain\ValueObjects\ClinicalProcedureOrderStatus;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use Illuminate\Support\Carbon;

/**
 * Backs GET /patients/{id}/summary (reports/patient-summary-module-plan.md
 * §3) — one aggregated round trip for the reusable Patient Summary module,
 * deliberately not a client-side fan-out of several requests: the module's
 * primary reuse targets are queue/list pages, where one request beats
 * several every time a summary is opened.
 *
 * Every piece here reuses an existing repository/use case rather than
 * inventing new query logic — GetActiveVisitJourneyUseCase's patientId
 * parameter, PatientInsuranceRepositoryInterface::findActiveInsurance()
 * (the same "active" derivation ClaimsInsurance/Billing already use, not
 * reimplemented client-side the way the legacy patients/Index.vue sheet
 * did), PatientAllergyRepositoryInterface::listActiveByPatientId() (already
 * sorted severity-desc), and ListEncountersUseCase (asked for perPage=1
 * instead of over-fetching and slicing, unlike
 * resources/js/composables/patientChart/usePatientEncounters.ts's current
 * shape).
 *
 * Extended for the Sheet tier (reports/patient-summary-module-plan.md's
 * follow-up "Patient Detail Sheet" — a second, deliberate-click level of
 * disclosure above the hover/click Popover card, not a duplicate of it):
 * contact details, the next scheduled appointment, any current inpatient
 * admission, cross-module visit/encounter/billing counts, and a short
 * recentActivity preview assembled from each module's own single most
 * recent row (not a dedicated activity-log table — that's real,
 * separate scope). Same endpoint, same query — the Popover card simply
 * displays a subset of this same payload, so opening the Sheet right
 * after the Popover for the same patient costs zero extra requests
 * (TanStack Query's cache already has it under the same key).
 *
 * activeAppointment reuses AppointmentRepositoryInterface::
 * findActiveForPatient() — the exact same active-status set
 * RegisterWalkInAndCheckInUseCase now checks to block a duplicate walk-in/
 * emergency check-in (2026-08-12). Exposing it here lets the Reception
 * Patient Profile disable the Check-In action proactively instead of
 * letting a doomed click round-trip to the server for an error it could
 * have known about in advance — without redefining what "active" means in
 * a second place.
 *
 * Renamed from activeAppointmentToday (2026-08-12, duplicate-check-in
 * fix): the field itself was already unused by any frontend consumer
 * (grep-confirmed) — the PatientVisitActionsMenu.vue this docblock used to
 * cite as its consumer doesn't exist anywhere in the codebase — so this is
 * a safe rename, done specifically because the field now checks *any*
 * active visit regardless of date, and "Today" in the name would have
 * been actively misleading about what it means once a real consumer is
 * wired up.
 */
class GetPatientSummaryUseCase
{
    private const ACTIVE_THEATRE_PROCEDURE_STATUSES = [
        TheatreProcedureStatus::PLANNED->value,
        TheatreProcedureStatus::IN_PREOP->value,
        TheatreProcedureStatus::IN_PROGRESS->value,
    ];

    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAllergyRepositoryInterface $allergyRepository,
        private readonly PatientInsuranceRepositoryInterface $insuranceRepository,
        private readonly ListEncountersUseCase $listEncountersUseCase,
        private readonly GetActiveVisitJourneyUseCase $activeVisitJourneyUseCase,
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $id): ?array
    {
        $patient = $this->patientRepository->findById($id);
        if ($patient === null) {
            return null;
        }

        $tenantId = (string) ($this->platformScopeContext->tenantId() ?? '');

        $allergies = array_slice($this->allergyRepository->listActiveByPatientId($id), 0, 5);

        $insurance = $tenantId !== ''
            ? $this->insuranceRepository->findActiveInsurance($id, $tenantId)
            : null;

        $latestEncounterResult = $this->listEncountersUseCase->execute([
            'patientId' => $id,
            'perPage' => 1,
            'sortBy' => 'openedAt',
            'sortDir' => 'desc',
        ]);
        $latestEncounter = $latestEncounterResult['data'][0] ?? null;

        $activeVisitEntries = $this->activeVisitJourneyUseCase->execute($id);
        $workflowStatus = $activeVisitEntries[0] ?? null;

        return [
            'patient' => $patient,
            'allergies' => $allergies,
            'insurance' => $insurance,
            'latestEncounter' => $latestEncounter,
            'workflowStatus' => $workflowStatus,
            'activeOrders' => $this->activeOrderCounts($id),
            'upcomingAppointment' => $this->upcomingAppointment($id),
            'currentAdmission' => $this->currentAdmission($id),
            'stats' => $this->stats($id),
            'recentActivity' => $this->recentActivity($id, $latestEncounter),
            'activeAppointment' => $this->appointmentRepository->findActiveForPatient(
                patientId: $id,
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function upcomingAppointment(string $patientId): ?array
    {
        $appointment = AppointmentModel::query()
            ->where('patient_id', $patientId)
            ->where('status', AppointmentStatus::SCHEDULED->value)
            ->where('scheduled_at', '>', Carbon::now())
            ->orderBy('scheduled_at')
            ->first();

        return $appointment?->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentAdmission(string $patientId): ?array
    {
        $admission = AdmissionModel::query()
            ->where('patient_id', $patientId)
            ->where('status', AdmissionStatus::ADMITTED->value)
            ->orderByDesc('admitted_at')
            ->first();

        return $admission?->toArray();
    }

    /**
     * @return array<string, int>
     */
    private function stats(string $patientId): array
    {
        return [
            'totalVisits' => AppointmentModel::query()->where('patient_id', $patientId)->count(),
            'totalEncounters' => EncounterModel::query()->where('patient_id', $patientId)->count(),
            'outstandingInvoices' => BillingInvoiceModel::query()
                ->where('patient_id', $patientId)
                ->whereIn('status', [BillingInvoiceStatus::ISSUED->value, BillingInvoiceStatus::PARTIALLY_PAID->value])
                ->count(),
        ];
    }

    /**
     * A lightweight stand-in for a real cross-module activity feed (which
     * would need its own event-sourcing/audit infrastructure — real,
     * separate scope, not built here): the single most recent row from
     * each module this patient has touched, merged and sorted by
     * recency. Reuses $latestEncounter (already fetched) instead of
     * querying it again.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(string $patientId, ?array $latestEncounter): array
    {
        $entries = [];

        if ($latestEncounter !== null) {
            $entries[] = [
                'type' => 'encounter',
                'label' => 'Encounter '.($latestEncounter['encounter_number'] ?? ''),
                'occurredAt' => $latestEncounter['opened_at'] ?? null,
            ];
        }

        $latestLabOrder = LaboratoryOrderModel::query()->where('patient_id', $patientId)->latest('ordered_at')->first();
        if ($latestLabOrder !== null) {
            $entries[] = [
                'type' => 'laboratory',
                'label' => 'Lab order: '.($latestLabOrder->test_name ?? 'Unknown test'),
                'occurredAt' => $latestLabOrder->ordered_at,
            ];
        }

        $latestPharmacyOrder = PharmacyOrderModel::query()->where('patient_id', $patientId)->latest('ordered_at')->first();
        if ($latestPharmacyOrder !== null) {
            $entries[] = [
                'type' => 'pharmacy',
                'label' => 'Pharmacy order: '.($latestPharmacyOrder->medication_name ?? 'Unknown medication'),
                'occurredAt' => $latestPharmacyOrder->ordered_at,
            ];
        }

        $latestClinicalProcedure = ClinicalProcedureOrderModel::query()->where('patient_id', $patientId)->latest('ordered_at')->first();
        if ($latestClinicalProcedure !== null) {
            $entries[] = [
                'type' => 'clinical_procedure',
                'label' => 'Clinical procedure: '.($latestClinicalProcedure->procedure_description ?? 'Unknown procedure'),
                'occurredAt' => $latestClinicalProcedure->ordered_at,
            ];
        }

        $latestInvoice = BillingInvoiceModel::query()->where('patient_id', $patientId)->latest('created_at')->first();
        if ($latestInvoice !== null) {
            $entries[] = [
                'type' => 'billing',
                'label' => 'Invoice '.($latestInvoice->invoice_number ?? ''),
                'occurredAt' => $latestInvoice->created_at,
            ];
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => strcmp((string) ($right['occurredAt'] ?? ''), (string) ($left['occurredAt'] ?? '')),
        );

        return array_slice($entries, 0, 5);
    }

    /**
     * @return array<string, int>
     */
    private function activeOrderCounts(string $patientId): array
    {
        return [
            'labActive' => LaboratoryOrderModel::query()
                ->where('patient_id', $patientId)
                ->whereIn('status', LaboratoryOrderStatus::openWorklistValues())
                ->count(),
            'pharmacyActive' => PharmacyOrderModel::query()
                ->where('patient_id', $patientId)
                ->whereIn('status', PharmacyOrderStatus::openWorklistValues())
                ->count(),
            'imagingActive' => RadiologyOrderModel::query()
                ->where('patient_id', $patientId)
                ->whereIn('status', RadiologyOrderStatus::openWorklistValues())
                ->count(),
            'procedureActive' => TheatreProcedureModel::query()
                ->where('patient_id', $patientId)
                ->whereIn('status', self::ACTIVE_THEATRE_PROCEDURE_STATUSES)
                ->count(),
            'clinicalProcedureActive' => ClinicalProcedureOrderModel::query()
                ->where('patient_id', $patientId)
                ->whereIn('status', ClinicalProcedureOrderStatus::openWorklistValues())
                ->count(),
        ];
    }
}
