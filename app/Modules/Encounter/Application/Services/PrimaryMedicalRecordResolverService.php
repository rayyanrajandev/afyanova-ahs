<?php

namespace App\Modules\Encounter\Application\Services;

use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;

/**
 * C-6 (reports/clinical-note-audit/15-critical-system-integrity-review.md):
 * GetEncounterWorkspaceUseCase and GetEncounterCloseReadinessUseCase each used
 * to implement their own "primary consultation note for this encounter"
 * resolution, and disagreed — the workspace only recognized a FINALIZED/
 * AMENDED note (null for a draft-only encounter), while close-readiness also
 * fell back to DRAFT. The same encounter could therefore show "no note" in
 * the workspace while close-readiness was correctly aware of, and blocking
 * on, an existing draft. This is the single resolution both now share,
 * aligned to the more complete finalized -> amended -> draft priority so a
 * draft is never invisible to one caller while visible to the other.
 */
class PrimaryMedicalRecordResolverService
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $encounterId, string $patientId): ?array
    {
        if ($patientId === '') {
            return null;
        }

        foreach ([
            MedicalRecordStatus::FINALIZED->value,
            MedicalRecordStatus::AMENDED->value,
            MedicalRecordStatus::DRAFT->value,
        ] as $status) {
            $search = $this->medicalRecordRepository->search(
                query: null,
                patientId: $patientId,
                encounterId: $encounterId,
                appointmentId: null,
                appointmentReferralId: null,
                admissionId: null,
                theatreProcedureId: null,
                authorUserId: null,
                status: $status,
                recordType: MedicalRecordNoteType::CONSULTATION_NOTE->value,
                fromDateTime: null,
                toDateTime: null,
                page: 1,
                perPage: 1,
                sortBy: 'updated_at',
                sortDirection: 'desc',
            );

            if ($search['data'] !== []) {
                return $search['data'][0];
            }
        }

        return null;
    }
}
