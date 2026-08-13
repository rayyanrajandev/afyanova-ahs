<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * P2+P5 of the Reception/Emergency/Admission/Bed-Management audit
 * follow-through — backs reception/Queue.vue's tab badge counts, replacing
 * the previous client-side `.length` of an already-loaded, unpaginated
 * query result (which breaks once GetReceptionQueueUseCase paginates).
 * Deliberately NOT a reuse of GetTriageQueueStatusCountsUseCase/
 * GetClinicianQueueStatusCountsUseCase — both are scoped to different
 * operational semantics (triage-claim ownership splits, same-shift
 * completed/cancelled totals) that don't apply to Reception's own simple
 * "how many appointments are at each of these three stages, given the
 * same q/department/clinicianUserId filters as the list" question.
 * Non-paging filters only, same "counts independent of the current page"
 * shape as useEmergencyCaseStatusCounts.ts's backing endpoint.
 */
class GetReceptionQueueStatusCountsUseCase
{
    private const STAGES = [
        AppointmentStatus::WAITING_TRIAGE->value,
        AppointmentStatus::WAITING_PROVIDER->value,
        AppointmentStatus::IN_CONSULTATION->value,
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $department = isset($filters['department']) ? trim((string) $filters['department']) : null;
        $department = $department === '' ? null : $department;

        $clinicianUserId = isset($filters['clinicianUserId']) ? trim((string) $filters['clinicianUserId']) : null;
        $clinicianUserId = $clinicianUserId === '' ? null : $clinicianUserId;

        $counts = [];
        $total = 0;

        foreach (self::STAGES as $stage) {
            $count = $this->baseQuery($stage, $query, $department, $clinicianUserId)->count();
            $counts[$stage] = $count;
            $total += $count;
        }

        $counts['total'] = $total;

        return $counts;
    }

    private function baseQuery(string $stage, ?string $query, ?string $department, ?string $clinicianUserId): Builder
    {
        return AppointmentModel::query()
            ->where('status', $stage)
            ->when($department, fn (Builder $builder, string $value) => $builder->where('department', $value))
            ->when($clinicianUserId, fn (Builder $builder, string $value) => $builder->where('clinician_user_id', $value))
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.strtolower($searchTerm).'%';
                $matchingPatientIds = PatientModel::query()
                    ->where(function (Builder $nested) use ($like): void {
                        $nested->whereRaw('LOWER(first_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(patient_number) LIKE ?', [$like]);
                    })
                    ->pluck('id');

                $builder->where(function (Builder $nested) use ($like, $matchingPatientIds): void {
                    $nested->whereRaw('LOWER(appointment_number) LIKE ?', [$like])
                        ->orWhereIn('patient_id', $matchingPatientIds);
                });
            });
    }
}
