<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;
use App\Support\ClinicalOrders\EncounterScopedOrderQuery;
use Illuminate\Database\Eloquent\Builder;

class CheckPharmacyOrderDuplicatesUseCase
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
        $catalogItemId = trim((string) ($filters['approved_medicine_catalog_item_id'] ?? ''));
        $medicationCode = trim((string) ($filters['medication_code'] ?? ''));
        $excludeOrderId = trim((string) ($filters['exclude_order_id'] ?? ''));

        $sameEncounterDuplicates = [];
        if (EncounterScopedOrderQuery::hasVisitScope($encounterId, $appointmentId, $admissionId)) {
            $sameEncounterQuery = $this->baseDuplicateQuery($patientId, $catalogItemId, $medicationCode, $excludeOrderId)
                ->whereIn('status', [
                    PharmacyOrderStatus::PENDING->value,
                    PharmacyOrderStatus::IN_PREPARATION->value,
                    PharmacyOrderStatus::PARTIALLY_DISPENSED->value,
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
                ->map(fn (PharmacyOrderModel $order): array => $order->toArray())
                ->all();
        }

        $recentPatientQuery = $this->baseDuplicateQuery($patientId, $catalogItemId, $medicationCode, $excludeOrderId)
            ->where('ordered_at', '>=', now()->subDays(30))
            ->where('status', '!=', PharmacyOrderStatus::CANCELLED->value);

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
            ->map(fn (PharmacyOrderModel $order): array => $order->toArray())
            ->all();

        $messages = [];
        if ($sameEncounterDuplicates !== []) {
            $messages[] = 'An active medication order for this medicine already exists in the current encounter.';
        }
        if ($recentPatientDuplicates !== []) {
            $messages[] = 'Recent medication orders for this medicine exist in the last 30 days.';
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
        string $medicationCode,
        string $excludeOrderId
    ): Builder {
        return PharmacyOrderModel::query()
            ->where('patient_id', $patientId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->when(
                $excludeOrderId !== '',
                static fn (Builder $query) => $query->where('id', '!=', $excludeOrderId),
            )
            ->where(function (Builder $query) use ($catalogItemId, $medicationCode): void {
                if ($catalogItemId !== '') {
                    $query->where('approved_medicine_catalog_item_id', $catalogItemId);
                    return;
                }

                $query->where('medication_code', $medicationCode);
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
