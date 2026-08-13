<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Models\User;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffAccepted;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffCancelled;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordAuditLogRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class AcceptMedicalRecordHandoffUseCase
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly MedicalRecordAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $id, string $action, int $actorId): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->medicalRecordRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (($existing['status'] ?? null) !== MedicalRecordStatus::DRAFT->value) {
            throw new MedicalRecordContentLockedException(
                'Only draft notes can accept handoffs.',
            );
        }

        if ($existing['handoff_status'] !== 'pending') {
            throw new MedicalRecordContentLockedException(
                'This note has no pending handoff.',
            );
        }

        if ((int) ($existing['handed_off_to_user_id'] ?? 0) !== $actorId) {
            throw new MedicalRecordContentLockedException(
                'This handoff was not addressed to you.',
            );
        }

        $previousOwnerUserId = (int) $existing['author_user_id'];

        if ($action === 'accept') {
            $updated = $this->medicalRecordRepository->update($id, [
                'author_user_id' => $actorId,
                'handoff_status' => 'accepted',
            ]);

            if (! $updated) {
                return null;
            }

            $acceptor = User::query()->find($actorId);

            $this->auditLogRepository->write(
                medicalRecordId: $id,
                action: 'medical-record.handoff.accepted',
                actorId: $actorId,
                changes: [
                    'author_user_id' => ['before' => $previousOwnerUserId, 'after' => $actorId],
                    'handoff_status' => ['before' => 'pending', 'after' => 'accepted'],
                ],
            );

            DB::afterCommit(function () use ($id, $existing, $actorId, $acceptor, $previousOwnerUserId): void {
                event(new MedicalRecordHandoffAccepted(
                    medicalRecordId: $id,
                    recordNumber: (string) ($existing['record_number'] ?? ''),
                    newOwnerUserId: $actorId,
                    newOwnerName: $acceptor?->name ?? 'Unknown',
                    previousOwnerUserId: $previousOwnerUserId,
                ));
            });

            return $updated;
        }

        $updated = $this->medicalRecordRepository->update($id, [
            'handed_off_to_user_id' => null,
            'handoff_status' => 'declined',
            'handoff_note' => null,
        ]);

        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            medicalRecordId: $id,
            action: 'medical-record.handoff.declined',
            actorId: $actorId,
            changes: [
                'handoff_status' => ['before' => 'pending', 'after' => 'declined'],
            ],
        );

        DB::afterCommit(function () use ($id, $previousOwnerUserId): void {
            event(new MedicalRecordHandoffCancelled(
                medicalRecordId: $id,
                initiatorUserId: $previousOwnerUserId,
            ));
        });

        return $updated;
    }
}
