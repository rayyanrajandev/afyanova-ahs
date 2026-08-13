<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseTransferVarianceReviewStatus;

class InventoryWarehouseTransferResponseTransformer
{
    private const APPROVAL_STALE_HOURS = 4;
    private const PICK_OVERDUE_HOURS = 8;
    private const RECEIVE_OVERDUE_HOURS = 12;

    public static function transform(array $transfer): array
    {
        $now = now();
        $status = (string) ($transfer['status'] ?? '');
        $lines = array_map(
            static fn (array $line, int $index): array => self::transformLine($line, $index, $now, $status),
            array_values(is_array($transfer['lines'] ?? null) ? $transfer['lines'] : []),
            array_keys(array_values(is_array($transfer['lines'] ?? null) ? $transfer['lines'] : [])),
        );

        $allReservations = collect($lines)
            ->flatMap(static fn (array $line): array => is_array($line['reservations'] ?? null) ? $line['reservations'] : [])
            ->values();

        $activeReservations = $allReservations->where('status', 'active');
        $staleReservations = $activeReservations->filter(static fn (array $reservation): bool => self::isStaleReservation($reservation, $now));
        $freshReservations = $activeReservations->reject(static fn (array $reservation): bool => self::isStaleReservation($reservation, $now));
        $consumedReservations = $allReservations->where('status', 'consumed');
        $releasedReservations = $allReservations->where('status', 'released');
        $expiredReleasedReservations = $releasedReservations->filter(static fn (array $reservation): bool => self::isExpiredReleaseReservation($reservation));

        $requestedQuantity = round((float) collect($lines)->sum(static fn (array $line): float => (float) ($line['requested_quantity'] ?? 0)), 3);
        $packedQuantity = round((float) collect($lines)->sum(static fn (array $line): float => (float) ($line['packedQuantity'] ?? 0)), 3);
        $dispatchReadyQuantity = round((float) collect($lines)->sum(static fn (array $line): float => (float) ($line['dispatchReadyQuantity'] ?? 0)), 3);
        $dispatchedQuantity = round((float) collect($lines)->sum(static fn (array $line): float => (float) ($line['dispatched_quantity'] ?? 0)), 3);
        $receivedQuantity = round((float) collect($lines)->sum(static fn (array $line): float => (float) ($line['received_quantity'] ?? 0)), 3);
        $receiptVarianceLines = collect($lines)
            ->filter(static fn (array $line): bool => ((float) ($line['receiptVarianceQuantity'] ?? 0)) > 0)
            ->values();
        $dispatchRequiresRevalidation = (int) $staleReservations->count() > 0
            || ((int) $freshReservations->count() === 0 && (int) $expiredReleasedReservations->count() > 0);

        $reservationSummary = [
            'state' => self::reservationState(
                (int) $freshReservations->count(),
                (int) $staleReservations->count(),
                (int) $expiredReleasedReservations->count(),
                (int) $consumedReservations->count(),
                (int) $releasedReservations->count(),
            ),
            'activeCount' => (int) $freshReservations->count(),
            'staleCount' => (int) $staleReservations->count(),
            'expiredReleasedCount' => (int) $expiredReleasedReservations->count(),
            'consumedCount' => (int) $consumedReservations->count(),
            'releasedCount' => (int) $releasedReservations->count(),
            'activeQuantity' => round((float) $freshReservations->sum(static fn (array $reservation): float => (float) ($reservation['quantity'] ?? 0)), 3),
            'staleQuantity' => round((float) $staleReservations->sum(static fn (array $reservation): float => (float) ($reservation['quantity'] ?? 0)), 3),
            'expiredReleasedQuantity' => round((float) $expiredReleasedReservations->sum(static fn (array $reservation): float => (float) ($reservation['quantity'] ?? 0)), 3),
            'consumedQuantity' => round((float) $consumedReservations->sum(static fn (array $reservation): float => (float) ($reservation['quantity'] ?? 0)), 3),
            'releasedQuantity' => round((float) $releasedReservations->sum(static fn (array $reservation): float => (float) ($reservation['quantity'] ?? 0)), 3),
            'heldLineCount' => (int) collect($lines)->filter(static fn (array $line): bool => ((float) ($line['reservedQuantity'] ?? 0)) > 0)->count(),
            'staleLineCount' => (int) collect($lines)->filter(static fn (array $line): bool => ($line['isStaleReservation'] ?? false) === true)->count(),
            'refreshLineCount' => (int) collect($lines)->filter(static fn (array $line): bool => ($line['needsReservationRefresh'] ?? false) === true)->count(),
            'holdExpiresAt' => self::closestExpiry($freshReservations->all(), $now),
            'staleSince' => self::closestExpiry($staleReservations->all(), $now),
            'refreshRequiredSince' => self::reservationMoment($expiredReleasedReservations->all(), 'released_at'),
            'dispatchRequiresRevalidation' => $dispatchRequiresRevalidation,
        ];

        $receiptVarianceSummary = [
            'state' => $receiptVarianceLines->isNotEmpty() ? 'variance' : 'clean',
            'lineCount' => (int) $receiptVarianceLines->count(),
            'quantity' => round((float) $receiptVarianceLines->sum(static fn (array $line): float => (float) ($line['receiptVarianceQuantity'] ?? 0)), 3),
            'types' => $receiptVarianceLines
                ->pluck('receiptVarianceType')
                ->filter()
                ->countBy()
                ->all(),
        ];
        $storedVarianceReviewStatus = trim((string) ($transfer['receipt_variance_review_status'] ?? ''));
        $varianceReviewState = $receiptVarianceLines->isEmpty()
            ? 'not_required'
            : ($storedVarianceReviewStatus === InventoryWarehouseTransferVarianceReviewStatus::REVIEWED->value ? 'reviewed' : 'pending');
        $varianceReview = [
            'state' => $varianceReviewState,
            'lineCount' => (int) ($receiptVarianceSummary['lineCount'] ?? 0),
            'quantity' => (float) ($receiptVarianceSummary['quantity'] ?? 0),
            'types' => $receiptVarianceSummary['types'] ?? [],
            'reviewedAt' => self::timestampOrNull($transfer['receipt_variance_reviewed_at'] ?? null)?->toISOString(),
            'reviewedByUserId' => $transfer['receipt_variance_reviewed_by_user_id'] ?? null,
            'notes' => self::nullableString($transfer['receipt_variance_review_notes'] ?? null),
            'needsReview' => $varianceReviewState === 'pending',
            'canReview' => $status === 'received' && $receiptVarianceLines->isNotEmpty(),
        ];

        $attentionSignals = self::attentionSignals($transfer, $reservationSummary, $receiptVarianceSummary, $varianceReview, $now);

        return array_merge($transfer, [
            'sourceWarehouseName' => self::warehouseName($transfer['source_warehouse'] ?? null),
            'destinationWarehouseName' => self::warehouseName($transfer['destination_warehouse'] ?? null),
            'routeLabel' => self::routeLabel($transfer),
            'dispatchNoteNumber' => $transfer['dispatch_note_number'] ?? null,
            'packNotes' => $transfer['pack_notes'] ?? null,
            'lines' => $lines,
            'reservationSummary' => $reservationSummary,
            'receiptVarianceSummary' => $receiptVarianceSummary,
            'varianceReview' => $varianceReview,
            'pickingSummary' => [
                'requestedQuantity' => $requestedQuantity,
                'packedQuantity' => $packedQuantity,
                'dispatchedQuantity' => $dispatchedQuantity,
                'receivedQuantity' => $receivedQuantity,
                'remainingToPack' => round(max($requestedQuantity - $packedQuantity, 0), 3),
                'dispatchReadyQuantity' => $dispatchReadyQuantity,
                'remainingToDispatch' => round(max($dispatchReadyQuantity - $dispatchedQuantity, 0), 3),
                'remainingToReceive' => $status === 'received'
                    ? 0.0
                    : round(max($dispatchedQuantity - $receivedQuantity, 0), 3),
            ],
            'attentionSignals' => $attentionSignals,
            'attentionState' => $attentionSignals[0]['severity'] ?? 'normal',
        ]);
    }

    private static function transformLine(array $line, int $index, \Illuminate\Support\Carbon $now, string $transferStatus): array
    {
        $reservations = array_values(is_array($line['reservations'] ?? null) ? $line['reservations'] : []);
        $activeReservations = collect($reservations)->where('status', 'active');
        $staleReservations = $activeReservations->filter(static fn (array $reservation): bool => self::isStaleReservation($reservation, $now));
        $freshReservations = $activeReservations->reject(static fn (array $reservation): bool => self::isStaleReservation($reservation, $now));
        $consumedReservations = collect($reservations)->where('status', 'consumed');
        $releasedReservations = collect($reservations)->where('status', 'released');
        $expiredReleasedReservations = $releasedReservations->filter(static fn (array $reservation): bool => self::isExpiredReleaseReservation($reservation));
        $needsReservationRefresh = $staleReservations->isNotEmpty()
            || ($freshReservations->isEmpty() && $expiredReleasedReservations->isNotEmpty());
        $requestedQuantity = round((float) ($line['requested_quantity'] ?? 0), 3);
        $packedQuantity = $line['packed_quantity'] !== null
            ? round((float) $line['packed_quantity'], 3)
            : 0.0;
        $dispatchReadyQuantity = $line['packed_quantity'] !== null
            ? $packedQuantity
            : $requestedQuantity;
        $receivedQuantity = round((float) ($line['received_quantity'] ?? 0), 3);
        $reportedReceivedQuantity = $line['reported_received_quantity'] !== null
            ? round((float) $line['reported_received_quantity'], 3)
            : $receivedQuantity;
        $receiptVarianceQuantity = round((float) ($line['receipt_variance_quantity'] ?? 0), 3);
        $receiptVarianceType = trim((string) ($line['receipt_variance_type'] ?? ''));
        $receiptVarianceReason = trim((string) ($line['receipt_variance_reason'] ?? ''));

        return array_merge($line, [
            'lineIndex' => $index,
            'itemName' => $line['item']['item_name'] ?? null,
            'itemCode' => $line['item']['item_code'] ?? null,
            'itemCategory' => $line['item']['category'] ?? null,
            'batchNumber' => $line['batch']['batch_number'] ?? null,
            'batchStatus' => $line['batch']['status'] ?? null,
            'packedQuantity' => $packedQuantity,
            'dispatchReadyQuantity' => $dispatchReadyQuantity,
            'reportedReceivedQuantity' => $reportedReceivedQuantity,
            'receiptVarianceType' => $receiptVarianceType !== '' ? $receiptVarianceType : null,
            'receiptVarianceQuantity' => $receiptVarianceQuantity,
            'receiptVarianceReason' => $receiptVarianceReason !== '' ? $receiptVarianceReason : null,
            'reservedQuantity' => round((float) $freshReservations->sum(static fn (array $reservation): float => (float) ($reservation['quantity'] ?? 0)), 3),
            'staleReservedQuantity' => round((float) $staleReservations->sum(static fn (array $reservation): float => (float) ($reservation['quantity'] ?? 0)), 3),
            'expiredReleasedQuantity' => round((float) $expiredReleasedReservations->sum(static fn (array $reservation): float => (float) ($reservation['quantity'] ?? 0)), 3),
            'reservationState' => self::reservationState(
                (int) $freshReservations->count(),
                (int) $staleReservations->count(),
                (int) $expiredReleasedReservations->count(),
                (int) $consumedReservations->count(),
                (int) $releasedReservations->count(),
            ),
            'isStaleReservation' => $staleReservations->isNotEmpty(),
            'needsReservationRefresh' => $needsReservationRefresh,
            'holdExpiresAt' => self::closestExpiry($freshReservations->all(), $now),
            'staleSince' => self::closestExpiry($staleReservations->all(), $now),
            'refreshRequiredSince' => self::reservationMoment($expiredReleasedReservations->all(), 'released_at'),
            'dispatchRemainingQuantity' => round(max(
                $dispatchReadyQuantity - (float) ($line['dispatched_quantity'] ?? 0),
                0
            ), 3),
            'packRemainingQuantity' => round(max(
                $requestedQuantity - $packedQuantity,
                0
            ), 3),
            'receiptRemainingQuantity' => $transferStatus === 'received'
                ? 0.0
                : round(max(
                    (float) ($line['dispatched_quantity'] ?? 0) - $receivedQuantity,
                    0
                ), 3),
        ]);
    }

    private static function isStaleReservation(array $reservation, \Illuminate\Support\Carbon $now): bool
    {
        $expiresAt = $reservation['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }

        $expiry = $expiresAt instanceof \Illuminate\Support\Carbon
            ? $expiresAt->copy()
            : \Illuminate\Support\Carbon::parse((string) $expiresAt);

        return ! $expiry->gt($now);
    }

    private static function isExpiredReleaseReservation(array $reservation): bool
    {
        return (($reservation['metadata'] ?? [])['releaseSource'] ?? null) === 'expired_reservation';
    }

    /**
     * @param  array<int, array<string, mixed>>  $reservations
     */
    private static function closestExpiry(array $reservations, \Illuminate\Support\Carbon $now): ?string
    {
        return collect($reservations)
            ->map(static function (array $reservation) {
                $expiresAt = $reservation['expires_at'] ?? null;
                if ($expiresAt === null || $expiresAt === '') {
                    return null;
                }

                return $expiresAt instanceof \Illuminate\Support\Carbon
                    ? $expiresAt->copy()
                    : \Illuminate\Support\Carbon::parse((string) $expiresAt);
            })
            ->filter()
            ->sortBy(static fn (\Illuminate\Support\Carbon $value): int => $value->timestamp)
            ->map(static fn (\Illuminate\Support\Carbon $value): string => $value->toISOString())
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $reservations
     */
    private static function reservationMoment(array $reservations, string $field): ?string
    {
        return collect($reservations)
            ->map(static function (array $reservation) use ($field) {
                $value = $reservation[$field] ?? null;
                if ($value === null || $value === '') {
                    return null;
                }

                return $value instanceof \Illuminate\Support\Carbon
                    ? $value->copy()
                    : \Illuminate\Support\Carbon::parse((string) $value);
            })
            ->filter()
            ->sortBy(static fn (\Illuminate\Support\Carbon $value): int => $value->timestamp)
            ->map(static fn (\Illuminate\Support\Carbon $value): string => $value->toISOString())
            ->first();
    }

    private static function warehouseName(mixed $warehouse): ?string
    {
        if (! is_array($warehouse)) {
            return null;
        }

        $name = trim((string) ($warehouse['warehouse_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $code = trim((string) ($warehouse['warehouse_code'] ?? ''));

        return $code === '' ? $name : sprintf('%s (%s)', $name, $code);
    }

    private static function routeLabel(array $transfer): ?string
    {
        $source = self::warehouseName($transfer['source_warehouse'] ?? null);
        $destination = self::warehouseName($transfer['destination_warehouse'] ?? null);

        if ($source === null && $destination === null) {
            return null;
        }

        if ($source === null) {
            return $destination;
        }

        if ($destination === null) {
            return $source;
        }

        return sprintf('%s -> %s', $source, $destination);
    }

    private static function reservationState(int $activeCount, int $staleCount, int $expiredReleasedCount, int $consumedCount, int $releasedCount): string
    {
        if ($staleCount > 0) {
            return 'stale';
        }

        if ($activeCount > 0) {
            return 'held';
        }

        if ($expiredReleasedCount > 0) {
            return 'refresh_required';
        }

        if ($consumedCount > 0 && $releasedCount > 0) {
            return 'partial';
        }

        if ($consumedCount > 0) {
            return 'consumed';
        }

        if ($releasedCount > 0) {
            return 'released';
        }

        return 'none';
    }

    /**
     * @param  array<string, mixed>  $transfer
     * @param  array<string, mixed>  $reservationSummary
     * @param  array<string, mixed>  $receiptVarianceSummary
     * @param  array<string, mixed>  $varianceReview
     * @return array<int, array<string, mixed>>
     */
    private static function attentionSignals(
        array $transfer,
        array $reservationSummary,
        array $receiptVarianceSummary,
        array $varianceReview,
        \Illuminate\Support\Carbon $now
    ): array
    {
        $signals = [];
        $status = (string) ($transfer['status'] ?? '');

        if (($reservationSummary['dispatchRequiresRevalidation'] ?? false) === true && $status === 'approved') {
            $signals[] = [
                'key' => 'hold_refresh_required',
                'label' => 'Hold refresh needed',
                'severity' => 'high',
                'since' => $reservationSummary['refreshRequiredSince'] ?? $reservationSummary['staleSince'] ?? null,
                'detail' => 'Expired stock hold must be refreshed before dispatch.',
            ];
        }

        if ($status === 'pending_approval') {
            $createdAt = self::timestampOrNull($transfer['created_at'] ?? null);
            if ($createdAt !== null && $createdAt->lte($now->copy()->subHours(self::APPROVAL_STALE_HOURS))) {
                $signals[] = [
                    'key' => 'approval_stale',
                    'label' => 'Approval stale',
                    'severity' => 'medium',
                    'since' => $createdAt->toISOString(),
                    'detail' => sprintf('Waiting for approval longer than %d hours.', self::APPROVAL_STALE_HOURS),
                ];
            }
        }

        if ($status === 'approved') {
            $approvedAt = self::timestampOrNull($transfer['approved_at'] ?? $transfer['updated_at'] ?? null);
            if ($approvedAt !== null && $approvedAt->lte($now->copy()->subHours(self::PICK_OVERDUE_HOURS))) {
                $signals[] = [
                    'key' => 'pick_overdue',
                    'label' => 'Pick overdue',
                    'severity' => 'medium',
                    'since' => $approvedAt->toISOString(),
                    'detail' => sprintf('Approved transfer has not been picked within %d hours.', self::PICK_OVERDUE_HOURS),
                ];
            }
        }

        if ($status === 'in_transit') {
            $dispatchedAt = self::timestampOrNull($transfer['dispatched_at'] ?? $transfer['updated_at'] ?? null);
            if ($dispatchedAt !== null && $dispatchedAt->lte($now->copy()->subHours(self::RECEIVE_OVERDUE_HOURS))) {
                $signals[] = [
                    'key' => 'receive_overdue',
                    'label' => 'Receive overdue',
                    'severity' => 'medium',
                    'since' => $dispatchedAt->toISOString(),
                    'detail' => sprintf('Dispatched transfer has not been received within %d hours.', self::RECEIVE_OVERDUE_HOURS),
                ];
            }
        }

        if (
            $status === 'received'
            && (int) ($receiptVarianceSummary['lineCount'] ?? 0) > 0
            && ($varianceReview['needsReview'] ?? false) === true
        ) {
            $varianceTypes = array_keys($receiptVarianceSummary['types'] ?? []);
            $highSeverityTypes = ['damaged', 'wrong_batch', 'excess'];

            $signals[] = [
                'key' => 'variance_review_pending',
                'label' => 'Variance review',
                'severity' => count(array_intersect($varianceTypes, $highSeverityTypes)) > 0 ? 'high' : 'medium',
                'since' => self::timestampOrNull($transfer['received_at'] ?? $transfer['updated_at'] ?? null)?->toISOString(),
                'detail' => sprintf(
                    '%d line%s need receipt variance review.',
                    (int) ($receiptVarianceSummary['lineCount'] ?? 0),
                    (int) ($receiptVarianceSummary['lineCount'] ?? 0) === 1 ? '' : 's',
                ),
            ];
        }

        usort($signals, static function (array $left, array $right): int {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];

            return ($order[$left['severity'] ?? 'low'] ?? 9) <=> ($order[$right['severity'] ?? 'low'] ?? 9);
        });

        return array_values($signals);
    }

    private static function timestampOrNull(mixed $value): ?\Illuminate\Support\Carbon
    {
        if ($value instanceof \Illuminate\Support\Carbon) {
            return $value->copy();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return \Illuminate\Support\Carbon::parse((string) $value);
    }

    private static function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }
}
