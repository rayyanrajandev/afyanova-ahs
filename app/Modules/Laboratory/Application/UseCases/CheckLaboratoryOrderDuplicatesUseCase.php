<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;
use App\Support\ClinicalOrders\EncounterScopedOrderQuery;
use Illuminate\Database\Eloquent\Builder;

class CheckLaboratoryOrderDuplicatesUseCase
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{severity: string, messages: array<int, string>, sameEncounterDuplicates: array<int, array<string, mixed>>, recentPatientDuplicates: array<int, array<string, mixed>>}
     */
    public function execute(array $filters): array
    {
        $patientId = trim((string) ($filters['patient_id'] ?? ''));
        $encounterId = trim((string) ($filters['encounter_id'] ?? ''));
        $appointmentId = trim((string) ($filters['appointment_id'] ?? ''));
        $admissionId = trim((string) ($filters['admission_id'] ?? ''));
        $catalogItemId = trim((string) ($filters['lab_test_catalog_item_id'] ?? ''));
        $testCode = trim((string) ($filters['test_code'] ?? ''));

        $sameEncounterDuplicates = [];
        if (EncounterScopedOrderQuery::hasVisitScope($encounterId, $appointmentId, $admissionId)) {
            $sameEncounterQuery = $this->baseDuplicateQuery($patientId, $catalogItemId, $testCode)
                ->whereIn('status', [
                    LaboratoryOrderStatus::ORDERED->value,
                    LaboratoryOrderStatus::COLLECTED->value,
                    LaboratoryOrderStatus::IN_PROGRESS->value,
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
                ->map(fn (LaboratoryOrderModel $order): array => $order->toArray())
                ->all();
        }

        $recentPatientQuery = $this->baseDuplicateQuery($patientId, $catalogItemId, $testCode)
            ->where('ordered_at', '>=', now()->subDays(30))
            ->where('status', '!=', LaboratoryOrderStatus::CANCELLED->value);

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
            ->map(fn (LaboratoryOrderModel $order): array => $order->toArray())
            ->all();

        $messages = [];
        if ($sameEncounterDuplicates !== []) {
            $messages[] = 'Active laboratory orders for this test already exist in the current encounter.';
        }
        if ($recentPatientDuplicates !== []) {
            $messages[] = 'Recent laboratory orders for this test exist in the last 30 days.';
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
        string $testCode
    ): Builder {
        return LaboratoryOrderModel::query()
            ->where('patient_id', $patientId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->where(function (Builder $query) use ($catalogItemId, $testCode): void {
                if ($catalogItemId !== '') {
                    $query->where('lab_test_catalog_item_id', $catalogItemId);

                    return;
                }

                $query->where('test_code', $testCode);
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
