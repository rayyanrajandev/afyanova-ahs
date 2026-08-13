<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class MultiFacilityRolloutPlanResponseTransformer
{
    public static function transform(array $plan): array
    {
        return [
            'id' => $plan['id'] ?? null,
            'tenantId' => $plan['tenant_id'] ?? null,
            'facilityId' => $plan['facility_id'] ?? null,
            'rolloutCode' => $plan['rollout_code'] ?? null,
            'status' => $plan['status'] ?? null,
            'targetGoLiveAt' => $plan['target_go_live_at'] ?? null,
            'actualGoLiveAt' => $plan['actual_go_live_at'] ?? null,
            'ownerUserId' => $plan['owner_user_id'] ?? null,
            'rollbackRequired' => $plan['rollback_required'] ?? false,
            'rollbackReason' => $plan['rollback_reason'] ?? null,
            'metadata' => $plan['metadata'] ?? [],
            'checkpoints' => array_map([self::class, 'transformCheckpoint'], (array) ($plan['checkpoints'] ?? [])),
            'incidents' => array_map([self::class, 'transformIncident'], (array) ($plan['incidents'] ?? [])),
            'acceptance' => self::transformAcceptance($plan['acceptance'] ?? null),
            'createdAt' => $plan['created_at'] ?? null,
            'updatedAt' => $plan['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    private static function transformCheckpoint(array $checkpoint): array
    {
        return [
            'id' => $checkpoint['id'] ?? null,
            'rolloutPlanId' => $checkpoint['rollout_plan_id'] ?? null,
            'checkpointCode' => $checkpoint['checkpoint_code'] ?? null,
            'checkpointName' => $checkpoint['checkpoint_name'] ?? null,
            'status' => $checkpoint['status'] ?? null,
            'decisionNotes' => $checkpoint['decision_notes'] ?? null,
            'completedByUserId' => $checkpoint['completed_by_user_id'] ?? null,
            'completedAt' => $checkpoint['completed_at'] ?? null,
            'createdAt' => $checkpoint['created_at'] ?? null,
            'updatedAt' => $checkpoint['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $incident
     * @return array<string, mixed>
     */
    private static function transformIncident(array $incident): array
    {
        return [
            'id' => $incident['id'] ?? null,
            'rolloutPlanId' => $incident['rollout_plan_id'] ?? null,
            'incidentCode' => $incident['incident_code'] ?? null,
            'severity' => $incident['severity'] ?? null,
            'status' => $incident['status'] ?? null,
            'summary' => $incident['summary'] ?? null,
            'details' => $incident['details'] ?? null,
            'escalatedTo' => $incident['escalated_to'] ?? null,
            'openedByUserId' => $incident['opened_by_user_id'] ?? null,
            'resolvedByUserId' => $incident['resolved_by_user_id'] ?? null,
            'openedAt' => $incident['opened_at'] ?? null,
            'resolvedAt' => $incident['resolved_at'] ?? null,
            'createdAt' => $incident['created_at'] ?? null,
            'updatedAt' => $incident['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $acceptance
     * @return array<string, mixed>|null
     */
    private static function transformAcceptance(?array $acceptance): ?array
    {
        if ($acceptance === null) {
            return null;
        }

        return [
            'id' => $acceptance['id'] ?? null,
            'rolloutPlanId' => $acceptance['rollout_plan_id'] ?? null,
            'trainingCompletedAt' => $acceptance['training_completed_at'] ?? null,
            'acceptanceStatus' => $acceptance['acceptance_status'] ?? null,
            'acceptedByUserId' => $acceptance['accepted_by_user_id'] ?? null,
            'acceptanceCaseReference' => $acceptance['acceptance_case_reference'] ?? null,
            'acceptedAt' => $acceptance['accepted_at'] ?? null,
            'createdAt' => $acceptance['created_at'] ?? null,
            'updatedAt' => $acceptance['updated_at'] ?? null,
        ];
    }
}
