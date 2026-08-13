<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Reception\Application\Exceptions\QueueReorderCrossesTierException;
use App\Modules\Reception\Domain\ValueObjects\ArrivalMode;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use Illuminate\Support\Facades\DB;

/**
 * Volume 2.1 §10.3 "Reorder" / Volume 3.7 T5.5.
 *
 * Persists a manual within-tier reorder as `appointments.queue_position`
 * (Volume 3.7 T5.5 migration) rather than a separate synced table — one
 * column on the same row `GetReceptionQueueUseCase` already reads, so
 * there's nothing that can drift out of sync with it.
 *
 * Tier (emergency > scheduled > walk-in) is a hard floor, decided
 * explicitly (not assumed) because tiering exists as a clinical-safety
 * guarantee, not a display convenience: a drag can freely reshuffle *within*
 * a tier, but can never place a lower-priority tier's patient ahead of a
 * higher-priority tier's — `assertTierOrderPreserved()` is the one rule
 * this whole use case exists to enforce.
 *
 * Tier resolution mirrors `GetReceptionQueueUseCase::buildEntries()`'s
 * latest-arrival-mode lookup exactly (same tier map, same "unknown defaults
 * to the scheduled tier, not last" reasoning) — kept as a small duplicated
 * query rather than a shared abstraction, consistent with this codebase's
 * existing precedent for small, single-purpose lookups like this
 * (`ReceptionController::listAppointments()`'s patientName build documents
 * the same trade-off).
 */
class ReorderReceptionQueueUseCase
{
    private const ARRIVAL_MODE_TIERS = [
        ArrivalMode::EMERGENCY->value => 0,
        ArrivalMode::SCHEDULED_CHECKIN->value => 1,
        ArrivalMode::WALK_IN->value => 2,
    ];

    private const UNKNOWN_ARRIVAL_MODE_TIER = 1;

    public function __construct(
        private readonly AppointmentAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    /**
     * @param  array<int, string>  $orderedAppointmentIds  desired display order, top to bottom
     * @return int number of appointments whose position actually changed
     *
     * @throws QueueReorderCrossesTierException
     */
    public function execute(array $orderedAppointmentIds, ?int $actorId): int
    {
        $appointmentsById = AppointmentModel::query()
            ->whereIn('id', $orderedAppointmentIds)
            ->get(['id', 'queue_position'])
            ->keyBy('id');

        // Preserve the submitted order, dropping any id that no longer
        // exists — a benign race (e.g. cancelled between the client's last
        // fetch and this drop), not an error worth failing the whole request over.
        $orderedIds = array_values(array_filter(
            $orderedAppointmentIds,
            static fn (string $id): bool => $appointmentsById->has($id),
        ));

        if ($orderedIds === []) {
            return 0;
        }

        $tierByAppointmentId = $this->resolveTiers($orderedIds);
        $this->assertTierOrderPreserved($orderedIds, $tierByAppointmentId);

        $updated = 0;
        DB::transaction(function () use ($orderedIds, $appointmentsById, $actorId, &$updated): void {
            foreach ($orderedIds as $index => $id) {
                $newPosition = $index + 1;
                $previousPosition = $appointmentsById->get($id)->queue_position;
                if ($previousPosition === $newPosition) {
                    continue;
                }

                AppointmentModel::whereKey($id)->update(['queue_position' => $newPosition]);

                $this->auditLogRepository->write(
                    appointmentId: $id,
                    action: 'queue.reordered',
                    actorId: $actorId,
                    changes: [
                        'queue_position' => [
                            'before' => $previousPosition,
                            'after' => $newPosition,
                        ],
                    ],
                );
                $updated++;
            }
        });

        return $updated;
    }

    /**
     * @param  array<int, string>  $appointmentIds
     * @return array<string, int> appointmentId => tier
     */
    private function resolveTiers(array $appointmentIds): array
    {
        $latestArrivalModeByAppointmentId = ArrivalEventModel::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->orderByDesc('arrived_at')
            ->get(['appointment_id', 'arrival_mode'])
            ->unique('appointment_id')
            ->pluck('arrival_mode', 'appointment_id');

        $tiers = [];
        foreach ($appointmentIds as $id) {
            $arrivalMode = $latestArrivalModeByAppointmentId->get($id);
            $tiers[$id] = $arrivalMode !== null
                ? (self::ARRIVAL_MODE_TIERS[$arrivalMode] ?? self::UNKNOWN_ARRIVAL_MODE_TIER)
                : self::UNKNOWN_ARRIVAL_MODE_TIER;
        }

        return $tiers;
    }

    /**
     * The submitted order is valid iff the sequence of tiers it implies
     * never decreases — i.e. once you've seen a walk-in (tier 2), you can
     * never see an emergency or scheduled arrival (tier 0/1) after it. That
     * single check is exactly "never place a lower-priority tier ahead of a
     * higher-priority one," without needing to compare every pair.
     *
     * @param  array<int, string>  $orderedIds
     * @param  array<string, int>  $tierByAppointmentId
     *
     * @throws QueueReorderCrossesTierException
     */
    private function assertTierOrderPreserved(array $orderedIds, array $tierByAppointmentId): void
    {
        $previousTier = -1;
        foreach ($orderedIds as $id) {
            $tier = $tierByAppointmentId[$id];
            if ($tier < $previousTier) {
                throw new QueueReorderCrossesTierException;
            }
            $previousTier = $tier;
        }
    }
}
