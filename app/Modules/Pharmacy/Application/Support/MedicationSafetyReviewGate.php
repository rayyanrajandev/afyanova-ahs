<?php

namespace App\Modules\Pharmacy\Application\Support;

use App\Modules\Patient\Application\UseCases\GetPatientMedicationSafetySummaryUseCase;
use Illuminate\Validation\ValidationException;

class MedicationSafetyReviewGate
{
    public function __construct(
        private readonly GetPatientMedicationSafetySummaryUseCase $getPatientMedicationSafetySummaryUseCase,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @return array{
     *     severity:string,
     *     blockers:array<int,string>,
     *     warnings:array<int,string>,
     *     suggestedActions:array<int,string>,
     *     rules:array<int,array<string,mixed>>,
     *     ruleGroups:array<int,array<string,mixed>>,
     *     ruleCodes:array<int,string>,
     *     ruleCatalogVersion:string,
     *     overrideCode:string|null,
     *     overrideOption:array<string,string>|null,
     *     overrideSummary:array<string,mixed>
     * }
     */
    public function reviewOrFail(
        string $patientId,
        array $context,
        bool $safetyAcknowledged,
        ?string $safetyOverrideCode,
        ?string $safetyOverrideReason,
    ): array {
        $summary = $this->getPatientMedicationSafetySummaryUseCase->execute($patientId, $context);

        if ($summary === null) {
            return [
                'severity' => 'none',
                'blockers' => [],
                'warnings' => [],
                'suggestedActions' => [],
                'rules' => [],
                'ruleGroups' => [],
                'ruleCodes' => [],
                'ruleCatalogVersion' => MedicationSafetyRuleCatalog::catalogVersion(),
                'overrideCode' => null,
                'overrideOption' => null,
                'overrideSummary' => MedicationSafetyRuleCatalog::buildOverrideSummary([], null, null),
            ];
        }

        $overrideCode = trim((string) ($safetyOverrideCode ?? ''));
        $overrideReason = trim((string) ($safetyOverrideReason ?? ''));
        $rules = array_values(array_filter(
            $summary['rules'] ?? [],
            static fn (mixed $value): bool => is_array($value),
        ));
        $blockers = array_values(array_filter(
            $summary['blockers'] ?? [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        $warnings = array_values(array_filter(
            $summary['warnings'] ?? [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        if (
            $overrideCode !== ''
            && ! MedicationSafetyRuleCatalog::isValidOverrideCode($overrideCode)
        ) {
            throw ValidationException::withMessages([
                'safetyOverrideCode' => [
                    'Select a valid clinical override category before continuing.',
                ],
            ]);
        }

        if ($blockers !== [] && $overrideCode === '') {
            throw ValidationException::withMessages([
                'safetyOverrideCode' => [
                    'Clinical override category is required because this medication has active patient-safety blockers.',
                ],
            ]);
        }

        if ($blockers !== [] && $overrideReason === '') {
            throw ValidationException::withMessages([
                'safetyOverrideReason' => [
                    'Clinical override reason is required because this medication has active patient-safety blockers.',
                ],
            ]);
        }

        if ($warnings !== [] && ! $safetyAcknowledged) {
            throw ValidationException::withMessages([
                'safetyAcknowledged' => [
                    'Acknowledge the medication safety review before continuing with this order.',
                ],
            ]);
        }

        return [
            'severity' => (string) ($summary['severity'] ?? ($blockers !== [] ? 'critical' : ($warnings !== [] ? 'warning' : 'none'))),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'suggestedActions' => array_values(array_filter(
                $summary['suggestedActions'] ?? [],
                static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
            )),
            'rules' => $rules,
            'ruleGroups' => array_values(array_filter(
                $summary['ruleGroups'] ?? [],
                static fn (mixed $value): bool => is_array($value),
            )),
            'ruleCodes' => array_values(array_filter(
                array_map(
                    static fn (array $rule): string => trim((string) ($rule['code'] ?? '')),
                    $rules,
                ),
                static fn (string $value): bool => $value !== '',
            )),
            'ruleCatalogVersion' => trim((string) ($summary['ruleCatalogVersion'] ?? MedicationSafetyRuleCatalog::catalogVersion())),
            'overrideCode' => $overrideCode !== '' ? $overrideCode : null,
            'overrideOption' => MedicationSafetyRuleCatalog::findOverrideOption($overrideCode),
            'overrideSummary' => MedicationSafetyRuleCatalog::buildOverrideSummary(
                $rules,
                $overrideCode,
                $overrideReason,
            ),
        ];
    }
}
