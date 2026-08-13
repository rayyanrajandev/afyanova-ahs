<?php

namespace App\Support\ClinicalOrders;

use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class ClinicalOrderPatientTextSearch
{
    /**
     * @param  callable(Builder, string): void  $applyOrderFieldMatches
     */
    public static function apply(
        Builder $queryBuilder,
        string $searchTerm,
        PlatformScopeQueryApplier $platformScopeQueryApplier,
        bool $platformScopingEnabled,
        callable $applyOrderFieldMatches,
    ): void {
        $normalizedSearchTerm = mb_strtolower(trim($searchTerm));
        if ($normalizedSearchTerm === '') {
            return;
        }

        $like = '%'.$normalizedSearchTerm.'%';
        $trimmedSearchTerm = trim($searchTerm);

        $patientIdQuery = PatientModel::query()->select('id');
        if ($platformScopingEnabled) {
            $platformScopeQueryApplier->apply(
                $patientIdQuery,
                tenantColumn: 'tenant_id',
                facilityColumn: null,
            );
        }

        $patientIdQuery->where(function (Builder $patientQuery) use ($like): void {
            $patientQuery
                ->whereRaw('LOWER(patient_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(middle_name, \'\')) LIKE ?', [$like])
                ->orWhereRaw("LOWER(concat(first_name, ' ', last_name)) LIKE ?", [$like])
                ->orWhereRaw("LOWER(concat(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name)) LIKE ?", [$like])
                ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(national_id, \'\')) LIKE ?', [$like]);
        });

        $queryBuilder->where(function (Builder $nestedQuery) use ($like, $trimmedSearchTerm, $patientIdQuery, $applyOrderFieldMatches): void {
            $applyOrderFieldMatches($nestedQuery, $like);

            $nestedQuery->orWhereIn('patient_id', $patientIdQuery);

            if (Str::isUuid($trimmedSearchTerm)) {
                $nestedQuery->orWhere('id', $trimmedSearchTerm);
            }
        });
    }
}
