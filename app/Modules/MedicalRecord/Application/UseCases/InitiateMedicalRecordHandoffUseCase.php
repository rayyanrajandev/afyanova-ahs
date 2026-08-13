<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Models\User;
use App\Modules\MedicalRecord\Application\Exceptions\MedicalRecordContentLockedException;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffInitiated;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordAuditLogRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class InitiateMedicalRecordHandoffUseCase
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly MedicalRecordAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $id, int $targetUserId, ?string $note, int $actorId): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->medicalRecordRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (($existing['status'] ?? null) !== MedicalRecordStatus::DRAFT->value) {
            throw new MedicalRecordContentLockedException(
                'Only draft notes can be handed off.',
            );
        }

        if ((int) $existing['author_user_id'] !== $actorId) {
            throw new MedicalRecordContentLockedException(
                'Only the note author can initiate a handoff.',
            );
        }

        $updated = $this->medicalRecordRepository->update($id, [
            'handed_off_to_user_id' => $targetUserId,
            'handoff_initiated_by_user_id' => $actorId,
            'handoff_status' => 'pending',
            'handoff_note' => $note,
            'handed_off_at' => now(),
        ]);

        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            medicalRecordId: $id,
            action: 'medical-record.handoff.initiated',
            actorId: $actorId,
            changes: [
                'handed_off_to_user_id' => ['before' => null, 'after' => $targetUserId],
                'handoff_status' => ['before' => null, 'after' => 'pending'],
            ],
            metadata: [
                'note' => $note,
            ],
        );

        $initiator = User::query()->find($actorId);

        DB::afterCommit(function () use ($id, $existing, $targetUserId, $actorId, $note, $initiator): void {
            event(new MedicalRecordHandoffInitiated(
                medicalRecordId: $id,
                recordNumber: (string) ($existing['record_number'] ?? ''),
                targetUserId: $targetUserId,
                initiatorUserId: $actorId,
                initiatorName: $initiator?->name ?? 'Unknown',
                note: $note,
                patientId: (string) ($existing['patient_id'] ?? ''),
            ));
        });

        return $updated;
    }
}
