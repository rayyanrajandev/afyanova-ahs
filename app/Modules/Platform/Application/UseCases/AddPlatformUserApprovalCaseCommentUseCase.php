<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserApprovalCaseRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use DomainException;

class AddPlatformUserApprovalCaseCommentUseCase
{
    public function __construct(
        private readonly PlatformUserApprovalCaseRepositoryInterface $platformUserApprovalCaseRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $approvalCaseId, string $commentText, ?int $authorUserId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $approvalCase = $this->platformUserApprovalCaseRepository->findCaseById($approvalCaseId);
        if ($approvalCase === null) {
            return null;
        }

        $normalizedComment = trim($commentText);
        if ($normalizedComment === '') {
            throw new DomainException('Comment cannot be blank.');
        }

        if ($authorUserId !== null && $this->platformUserApprovalCaseRepository->resolveUserInScope($authorUserId) === null) {
            throw new DomainException('Comment author was not found in current scope.');
        }

        $comment = $this->platformUserApprovalCaseRepository->createComment($approvalCaseId, [
            'author_user_id' => $authorUserId,
            'comment_text' => $normalizedComment,
        ]);

        $this->platformUserApprovalCaseRepository->writeAuditLog(
            approvalCaseId: $approvalCaseId,
            action: 'platform.user-approval-case.comment.added',
            actorId: $authorUserId,
            metadata: [
                'comment_id' => $comment['id'] ?? null,
                'comment_length' => strlen($normalizedComment),
            ],
        );

        return $comment;
    }
}

