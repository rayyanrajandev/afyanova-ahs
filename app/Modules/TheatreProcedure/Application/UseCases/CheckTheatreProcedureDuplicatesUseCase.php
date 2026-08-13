<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;
use Illuminate\Database\Eloquent\Builder;

class CheckTheatreProcedureDuplicatesUseCase
{
    /**
     * @param array<string, mixed> $filters
     * @return array{severity: string, messages: array<int, string>, sameEncounterDuplicates: array<int, array<string, mixed>>, recentPatientDuplicates: array<int, array<string, mixed>>}
     */
    public function execute(array $filters): array
    {
        $patientId = trim((string) ($filters['patient_id'] ?? ''));
        $appointmentId = trim((string) ($filters['appointment_id'] ?? ''));
        $admissionId = trim((string) ($filters['admission_id'] ?? ''));
        $catalogItemId = trim((string) ($filters['theatre_procedure_catalog_item_id'] ?? ''));
        $procedureType = trim((string) ($filters['procedure_type'] ?? ''));

        $sameEncounterDuplicates = [];
        if ($appointmentId !== '' || $admissionId !== '') {
            $sameEncounterDuplicates = $this->baseDuplicateQuery($patientId, $catalogItemId, $procedureType)
                ->whereIn('status', [
                    TheatreProcedureStatus::PLANNED->value,
                    TheatreProcedureStatus::IN_PREOP->value,
                    TheatreProcedureStatus::IN_PROGRESS->value,
                ])
                ->where(function (Builder $query) use ($appointmentId, $admissionId): void {
                    $query->where('appointment_id', $appointmentId !== '' ? $appointmentId : null)
                        ->where('admission_id', $admissionId !== '' ? $admissionId : null);
                })
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (TheatreProcedureModel $procedure): array => $procedure->toArray())
                ->all();
        }

        $recentPatientQuery = $this->baseDuplicateQuery($patientId, $catalogItemId, $procedureType)
            ->where('created_at', '>=', now()->subDays(30))
            ->where('status', '!=', TheatreProcedureStatus::CANCELLED->value);

        if ($sameEncounterDuplicates !== []) {
            $recentPatientQuery->whereNotIn('id', array_values(array_map(
                static fn (array $procedure): string => (string) ($procedure['id'] ?? ''),
                $sameEncounterDuplicates,
            )));
        }

        $recentPatientDuplicates = $recentPatientQuery
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (TheatreProcedureModel $procedure): array => $procedure->toArray())
            ->all();

        $messages = [];
        if ($sameEncounterDuplicates !== []) {
            $messages[] = 'An active theatre procedure for this case already exists in the current encounter.';
        }
        if ($recentPatientDuplicates !== []) {
            $messages[] = 'Recent theatre procedures for this case exist in the last 30 days.';
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
        string $procedureType
    ): Builder {
        return TheatreProcedureModel::query()
            ->where('patient_id', $patientId)
            ->where('entry_state', ClinicalOrderEntryState::ACTIVE->value)
            ->where(function (Builder $query) use ($catalogItemId, $procedureType): void {
                if ($catalogItemId !== '') {
                    $query->where('theatre_procedure_catalog_item_id', $catalogItemId);
                    return;
                }

                $query->where('procedure_type', $procedureType);
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
