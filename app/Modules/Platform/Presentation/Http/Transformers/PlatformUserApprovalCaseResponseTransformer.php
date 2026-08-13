<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class PlatformUserApprovalCaseResponseTransformer
{
    public static function transform(array $approvalCase): array
    {
        return [
            'id' => $approvalCase['id'] ?? null,
            'tenantId' => $approvalCase['tenant_id'] ?? null,
            'facilityId' => $approvalCase['facility_id'] ?? null,
            'targetUserId' => $approvalCase['target_user_id'] ?? null,
            'requesterUserId' => $approvalCase['requester_user_id'] ?? null,
            'reviewerUserId' => $approvalCase['reviewer_user_id'] ?? null,
            'caseReference' => $approvalCase['case_reference'] ?? null,
            'actionType' => $approvalCase['action_type'] ?? null,
            'actionPayload' => $approvalCase['action_payload'] ?? [],
            'status' => $approvalCase['status'] ?? null,
            'decisionReason' => $approvalCase['decision_reason'] ?? null,
            'submittedAt' => $approvalCase['submitted_at'] ?? null,
            'decidedAt' => $approvalCase['decided_at'] ?? null,
            'comments' => array_map([self::class, 'transformComment'], (array) ($approvalCase['comments'] ?? [])),
            'createdAt' => $approvalCase['created_at'] ?? null,
            'updatedAt' => $approvalCase['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $comment
     * @return array<string, mixed>
     */
    public static function transformComment(array $comment): array
    {
        return [
            'id' => $comment['id'] ?? null,
            'approvalCaseId' => $comment['approval_case_id'] ?? null,
            'authorUserId' => $comment['author_user_id'] ?? null,
            'commentText' => $comment['comment_text'] ?? null,
            'createdAt' => $comment['created_at'] ?? null,
            'updatedAt' => $comment['updated_at'] ?? null,
        ];
    }
}

