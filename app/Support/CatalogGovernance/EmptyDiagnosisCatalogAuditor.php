<?php

namespace App\Support\CatalogGovernance;

use App\Modules\MedicalRecord\Domain\Services\DiagnosisTerminologyLookupServiceInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Services\RequestCurrentPlatformScopeContext;
use App\Modules\Platform\Infrastructure\Services\StaticCurrentPlatformScopeContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-10 (reports/clinical-note-audit/15-critical-system-integrity-review.md),
 * Option C (decided, alongside Option B): a scheduled/on-demand check
 * flagging any facility whose diagnosis-terminology catalog has zero active
 * entries — the condition that makes per-request validation silently
 * permissive (Option B logs when it happens; this catches the
 * misconfiguration at its source instead of waiting for it to happen).
 *
 * Deliberately reuses DiagnosisTerminologyLookupServiceInterface — the exact
 * service the real create/update validation path calls — rather than
 * re-querying the catalog tables directly. The catalog's real scoping rules
 * (tenant/facility matching, facility-tier availability fallback via
 * FacilityTierSupport, and whether multi-tenant scoping is even enabled) are
 * genuinely complex; reimplementing them a second time here would risk
 * exactly the kind of drift that let C-11 go stale. Instead, this
 * temporarily rebinds CurrentPlatformScopeContextInterface to each
 * facility in turn and asks the real service what it would see.
 */
class EmptyDiagnosisCatalogAuditor
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function audit(): array
    {
        $findings = [];

        try {
            foreach ($this->facilities() as $facility) {
                if ($this->hasActiveDiagnosisCodesForFacility($facility)) {
                    continue;
                }

                $findings[] = $this->finding($facility);
            }
        } finally {
            // Restore normal request-derived scope resolution for whatever
            // runs after this in the same process.
            app()->bind(CurrentPlatformScopeContextInterface::class, RequestCurrentPlatformScopeContext::class);
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    public function writeAuditFindings(array $findings): void
    {
        if ($findings === [] || ! Schema::hasTable('catalog_integrity_audit_findings')) {
            return;
        }

        DB::table('catalog_integrity_audit_findings')->insert(array_map(
            fn (array $finding): array => [
                'id' => (string) str()->uuid(),
                'issue_code' => 'diagnosis_catalog.empty_for_facility',
                'severity' => 'warning',
                'module' => 'medical_records',
                'source_table' => 'facilities',
                'source_id' => $finding['sourceId'] ?? null,
                'summary' => $finding['summary'] ?? '',
                'payload' => json_encode($finding['payload'] ?? [], JSON_THROW_ON_ERROR),
                'resolution' => 'audited',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            $findings,
        ));
    }

    /**
     * @return Collection<int, FacilityModel>
     */
    private function facilities(): Collection
    {
        return FacilityModel::query()->get(['id', 'tenant_id', 'code', 'name']);
    }

    private function hasActiveDiagnosisCodesForFacility(FacilityModel $facility): bool
    {
        app()->instance(CurrentPlatformScopeContextInterface::class, new StaticCurrentPlatformScopeContext(
            tenant: $facility->tenant_id !== null ? ['id' => $facility->tenant_id] : null,
            facility: ['id' => $facility->id],
        ));

        return app(DiagnosisTerminologyLookupServiceInterface::class)->hasAnyActiveDiagnosisCodes();
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(FacilityModel $facility): array
    {
        return [
            'sourceId' => $facility->id,
            'summary' => sprintf(
                'Facility "%s" (%s) has no active diagnosis-terminology catalog entries — diagnosis code validation is silently permissive for this facility.',
                $facility->name,
                $facility->code,
            ),
            'payload' => [
                'facilityId' => $facility->id,
                'facilityCode' => $facility->code,
                'facilityName' => $facility->name,
                'tenantId' => $facility->tenant_id,
            ],
        ];
    }
}
