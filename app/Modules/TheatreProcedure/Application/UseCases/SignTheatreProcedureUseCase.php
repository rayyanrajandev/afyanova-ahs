<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Validation\ValidationException;

class SignTheatreProcedureUseCase
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
        private readonly TheatreProcedureAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->theatreProcedureRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (! ClinicalOrderLifecycle::isDraft($existing)) {
            throw ValidationException::withMessages([
                'order' => ['This theatre procedure is already signed.'],
            ]);
        }

        $payload = [];
        ClinicalOrderLifecycle::applyActiveEntryState($payload, $actorId);

        $updated = $this->theatreProcedureRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            theatreProcedureId: $id,
            action: 'theatre-procedure.signed',
            actorId: $actorId,
            changes: [
                'entry_state' => [
                    'before' => $existing['entry_state'] ?? null,
                    'after' => $updated['entry_state'] ?? null,
                ],
                'signed_at' => [
                    'before' => $existing['signed_at'] ?? null,
                    'after' => $updated['signed_at'] ?? null,
                ],
                'signed_by_user_id' => [
                    'before' => $existing['signed_by_user_id'] ?? null,
                    'after' => $updated['signed_by_user_id'] ?? null,
                ],
                'lifecycle_locked_at' => [
                    'before' => $existing['lifecycle_locked_at'] ?? null,
                    'after' => $updated['lifecycle_locked_at'] ?? null,
                ],
            ],
        );

        return $updated;
    }
}
