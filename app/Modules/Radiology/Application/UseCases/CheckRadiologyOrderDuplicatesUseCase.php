<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;
use App\Support\ClinicalOrders\EncounterScopedOrderQuery;
use Illuminate\Database\Eloquent\Builder;

class CheckRadiologyOrderDuplicatesUseCase
{
    /**
     * @param array<string, mixed> $filters
     * @return array{severity: string, messages: array<int, string>, sameEncounterDuplicates: array<int, array<string, mixed>>, recentPatientDuplicates: array<int, array<string, mixed>>}
     */
    public function execute(array $filters): array
    {
        $patientId = trim((string) ($filters['patient_id'] ?? ''));
        $encounterId = trim((string) ($filters['encounter_id'] ?? ''));
        $appointmentId = trim((string) ($filters['appointment_id'] ?? ''));
        $admissionId = trim((string) ($filters['admission_id'] ?? ''));
        $catalogItemId = trim((string) ($filters['radiology_procedure_catalog_item_id'] ?? ''));
        $procedureCode = trim((string) ($filters['procedure_code'] ?? ''));

        $sameEncounterDuplicates = [];
        if (EncounterScopedOrderQuery::hasVisitScope($encounterId, $appointmentId, $admissionId)) {
            $sameEncounterQuery = $this->baseDuplicateQuery($patientId, $catalogItemId, $procedureCode)
                ->whereIn('status', [
                    RadiologyOrderStatus::ORDERED->value,
                    RadiologyOrderStatus::SCHEDULED->value,
                    RadiologyOrderStatus::IN_PROGRESS->value,
                ]);
            EncounterScopedOrderQuery::applySameVisitScope(
                $sameEncounterQuery,
                $encounterId,
                $appointmentId,
                $admissionId,
            );
            $sameEncounterDuplicates = $sameEncounterQuery
                ->orderByDesc('ordered_at')
                ->limit(10)
                ->get()
                ->map(fn (RadiologyOrderModel $order): array => $order->toArray())
                ->all();
        }

        $recentPatientQuery = $this->baseDuplicateQuery($patientId, $catalogItemId, $procedureCode)
            ->where('ordered_at', '>=', now()->subDays(30))
            ->where('status', '!=', RadiologyOrderStatus::CANCELLED->value);

        if ($sameEncounterDuplicates !== []) {
            $recentPatientQuery->whereNotIn('id', array_values(array_map(
                static fn (array $order): string => (string) ($order['id'] ?? ''),
                $sameEncounterDuplicates,
            )));
        }

        $recentPatientDuplicates = $recentPatientQuery
            ->orderByDesc('ordered_at')
            ->limit(10)
            ->get()
            ->map(fn (RadiologyOrderModel $order): array => $order->toArray())
            ->all();

        $messages = [];
        if ($sameEncounterDuplicates !== []) {
            $messages[] = 'An active imaging order for this study already exists in the current encounter.';
        }
        if ($recentPatientDuplicates !== []) {
            $messages[] = 'Recent imaging orders for this study exist in the last 30 days.';
        }

        return [
            'severity' => $sameEncounterDuplicates !== []
                ? 'critical'
                : ($recentPatientDuplicates !== [] ? 'warning' : 'none'),
            'messages' => $messages,
            'sameEncounterDuplicates' => $sameEncounterDuplicates,
            'recentPatientDuplicates' => $recentPatientDuplicates,
        ];
    }

    private function baseDuplicateQuery(
        string $patientId,
        string $catalogItemId,
        string $procedureCode
    ): Builder {
        return RadiologyOrderModel::query()
            ->where('patient_id', $patientId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->where(function (Builder $query) use ($catalogItemId, $procedureCode): void {
                if ($catalogItemId !== '') {
                    $query->where('radiology_procedure_catalog_item_id', $catalogItemId);
                    return;
                }

                $query->where('procedure_code', $procedureCode);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('entered_in_error_at')
                    ->where(function (Builder $subQuery): void {
                        $subQuery->whereNull('lifecycle_reason_code')
                            ->orWhere('lifecycle_reason_code', '!=', 'entered_in_error');
                    });
            });
    }
}
