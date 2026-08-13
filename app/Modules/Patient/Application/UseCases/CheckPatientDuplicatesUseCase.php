<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Application\Exceptions\DuplicatePatientException;
use App\Modules\Patient\Application\Services\PatientDuplicateDetectionService;

/**
 * Phase 2 of reports/patients-index-modernization-plan.md (decided: server
 * is the sole authority on duplicate scoring; the client is a thin UI layer
 * that calls this and renders the result — reports/patients-index-audit.md
 * §1's finding that the legacy page reimplemented this scoring client-side
 * is the problem this closes). A dry-run wrapper around
 * PatientDuplicateDetectionService::evaluate() — the exact same service
 * CreatePatientUseCase already calls — so "check before you submit" and
 * "what actually happens on submit" can never disagree, because they are
 * the same call.
 */
class CheckPatientDuplicatesUseCase
{
    public function __construct(
        private readonly PatientDuplicateDetectionService $duplicateDetectionService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{severity: string, duplicates: array<int, array<string, mixed>>}
     */
    public function execute(array $payload, ?string $excludePatientId = null): array
    {
        try {
            $warnings = $this->duplicateDetectionService->evaluate($payload, $excludePatientId);
        } catch (DuplicatePatientException $exception) {
            return [
                'severity' => 'hard_block',
                'duplicates' => $exception->getDuplicates(),
            ];
        }

        if ($warnings === []) {
            return ['severity' => 'none', 'duplicates' => []];
        }

        $topLabel = $warnings[0]['duplicateConfidenceLabel'] ?? 'possible';

        return [
            'severity' => $topLabel === 'strong' ? 'strong_warning' : 'possible_warning',
            'duplicates' => $warnings,
        ];
    }
}
