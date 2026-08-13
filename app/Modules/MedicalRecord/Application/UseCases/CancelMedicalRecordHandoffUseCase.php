<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffCancelled;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordAuditLogRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class CancelMedicalRecordHandoffUseCase
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly MedicalRecordAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $id, int $actorId): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->medicalRecordRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (($existing['status'] ?? null) !== MedicalRecordStatus::DRAFT->value) {
            throw new MedicalRecordContentLockedException(
                'Only draft notes can cancel handoffs.',
            );
        }

        if ($existing['handoff_status'] !== 'pending') {
            throw new MedicalRecordContentLockedException(
                'This note has no pending handoff to cancel.',
            );
        }

        if ((int) $existing['handoff_initiated_by_user_id'] !== $actorId) {
            throw new MedicalRecordContentLockedException(
                'Only the initiating clinician can cancel a handoff.',
            );
        }

        $updated = $this->medicalRecordRepository->update($id, [
            'handed_off_to_user_id' => null,
            'handoff_initiated_by_user_id' => null,
            'handoff_status' => null,
            'handoff_note' => null,
            'handed_off_at' => null,
        ]);

        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            medicalRecordId: $id,
            action: 'medical-record.handoff.cancelled',
            actorId: $actorId,
            changes: [
                'handoff_status' => ['before' => 'pending', 'after' => null],
            ],
        );

        DB::afterCommit(function () use ($id, $actorId): void {
            event(new MedicalRecordHandoffCancelled(
                medicalRecordId: $id,
                initiatorUserId: $actorId,
            ));
        });

        return $updated;
    }
}
