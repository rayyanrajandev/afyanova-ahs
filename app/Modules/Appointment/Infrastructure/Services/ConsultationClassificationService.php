<?php

namespace App\Modules\Appointment\Infrastructure\Services;

use App\Modules\Appointment\Application\Support\ConsultationReviewPolicyResolver;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\Services\ConsultationClassificationServiceInterface;

class ConsultationClassificationService implements ConsultationClassificationServiceInterface
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly ConsultationReviewPolicyResolver $policyResolver,
    ) {}

    public function classify(
        string $patientId,
        ?string $facilityId,
        string $scheduledAt,
        ?string $reason,
    ): array {
        $policy = $this->policyResolver->resolve($facilityId);
        $followUpDays = (int) ($policy['follow_up_days'] ?? 0);
        $sameComplaintRequired = (bool) ($policy['same_complaint_required'] ?? false);

        if ($followUpDays <= 0) {
            return $this->newResult(
                'Automatic review classification is disabled (follow_up_days = 0).',
                $policy,
            );
        }

        $priorAppointment = $this->appointmentRepository->findLastCompletedForPatientWithinDays(
            patientId: $patientId,
            facilityId: $facilityId,
            scheduledAt: $scheduledAt,
            withinDays: $followUpDays,
        );

        if ($priorAppointment === null) {
            return $this->newResult(
                sprintf('No completed appointment found within %d day(s) at this facility.', $followUpDays),
                $policy,
            );
        }

        if ($sameComplaintRequired) {
            $reasonMatch = $this->complaintsOverlap(
                current: $reason ?? '',
                prior: (string) ($priorAppointment['reason'] ?? ''),
            );

            if (! $reasonMatch) {
                return $this->newResult(
                    'Prior appointment found but complaints do not match (same_complaint_required = true).',
                    $policy,
                );
            }
        }

        return [
            'classification'                => 'review',
            'source'                        => 'auto',
            'prior_completed_appointment_id' => (string) ($priorAppointment['id'] ?? ''),
            'reasoning'                     => sprintf(
                'Patient had a completed appointment (ID: %s) within %d day(s).%s',
                $priorAppointment['id'] ?? 'unknown',
                $followUpDays,
                $sameComplaintRequired ? ' Complaint match confirmed.' : '',
            ),
            'policy'                        => $policy,
        ];
    }

    /**
     * Returns true when the two complaint strings share at least one meaningful word
     * (3+ characters, case-insensitive).
     */
    private function complaintsOverlap(string $current, string $prior): bool
    {
        $currentWords = $this->extractWords($current);
        $priorWords   = $this->extractWords($prior);

        if ($currentWords === [] || $priorWords === []) {
            return false;
        }

        return count(array_intersect($currentWords, $priorWords)) > 0;
    }

    /**
     * @return array<int, string>
     */
    private function extractWords(string $text): array
    {
        preg_match_all('/[a-z]{3,}/i', strtolower($text), $matches);

        $stopWords = [
            'appointment',
            'check',
            'clinic',
            'consultation',
            'follow',
            'progress',
            'result',
            'results',
            'review',
            'routine',
            'visit',
        ];

        return array_values(array_diff(array_unique($matches[0] ?? []), $stopWords));
    }

    /**
     * @return array{classification: string, source: string, prior_completed_appointment_id: null, reasoning: string}
     */
    private function newResult(string $reasoning, array $policy): array
    {
        return [
            'classification'                => 'new',
            'source'                        => 'auto',
            'prior_completed_appointment_id' => null,
            'reasoning'                     => $reasoning,
            'policy'                        => $policy,
        ];
    }
}
