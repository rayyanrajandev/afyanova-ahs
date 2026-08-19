<?php

namespace App\Modules\InpatientWard\Infrastructure\Repositories;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardFollowUpRailRepositoryInterface;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\Revenue\Domain\Services\OutstandingBalanceReaderInterface;
use App\Modules\Revenue\Domain\ValueObjects\RevenueDocumentSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentInpatientWardFollowUpRailRepository implements InpatientWardFollowUpRailRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly OutstandingBalanceReaderInterface $outstandingBalanceReader,
    ) {}

    public function summarizeForAdmission(string $admissionId, int $itemLimit = 3): array
    {
        $itemLimit = max(1, min($itemLimit, 5));

        $laboratoryRows = $this->admissionScopedQuery(LaboratoryOrderModel::query(), $admissionId)
            ->orderByDesc('ordered_at')
            ->get();

        $pharmacyRows = $this->admissionScopedQuery(PharmacyOrderModel::query(), $admissionId)
            ->orderByDesc('ordered_at')
            ->get();

        $radiologyRows = $this->admissionScopedQuery(RadiologyOrderModel::query(), $admissionId)
            ->orderByDesc('ordered_at')
            ->get();

        $revenueDocuments = $this->outstandingBalanceReader->outstandingDocumentsForAdmission($admissionId);

        return [
            'laboratory' => $this->summarizeLaboratory($laboratoryRows, $itemLimit),
            'pharmacy' => $this->summarizePharmacy($pharmacyRows, $itemLimit),
            'radiology' => $this->summarizeRadiology($radiologyRows, $itemLimit),
            'billing' => $this->summarizeBilling($revenueDocuments, $itemLimit),
        ];
    }

    private function summarizeLaboratory(Collection $rows, int $itemLimit): array
    {
        $counts = $this->countStatuses($rows, ['ordered', 'collected', 'in_progress', 'completed', 'cancelled']);
        $followUpStatuses = ['ordered', 'collected', 'in_progress'];

        return [
            'follow_up_count' => $this->countKnownStatuses($counts, $followUpStatuses),
            'status_counts' => $counts,
            'items' => $rows
                ->filter(fn (LaboratoryOrderModel $row): bool => in_array($this->normalizeStatus($row->status), $followUpStatuses, true))
                ->take($itemLimit)
                ->map(fn (LaboratoryOrderModel $row): array => [
                    'id' => $row->id,
                    'number' => $row->order_number,
                    'title' => $row->test_name ?: $row->test_code ?: 'Laboratory order',
                    'status' => $row->status,
                    'timestamp' => $row->ordered_at?->toIso8601String(),
                    'detail' => $row->priority ? 'Priority '.ucfirst((string) $row->priority) : null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function summarizePharmacy(Collection $rows, int $itemLimit): array
    {
        $counts = $this->countStatuses($rows, [
            'pending',
            'in_preparation',
            'partially_dispensed',
            'dispensed',
            'cancelled',
            'reconciliation_pending',
            'reconciliation_completed',
            'reconciliation_exception',
        ]);
        $followUpStatuses = ['pending', 'in_preparation', 'partially_dispensed', 'reconciliation_pending', 'reconciliation_exception'];

        return [
            'follow_up_count' => $this->countKnownStatuses($counts, $followUpStatuses),
            'status_counts' => $counts,
            'items' => $rows
                ->filter(fn (PharmacyOrderModel $row): bool => in_array($this->normalizeStatus($row->status), $followUpStatuses, true))
                ->take($itemLimit)
                ->map(fn (PharmacyOrderModel $row): array => [
                    'id' => $row->id,
                    'number' => $row->order_number,
                    'title' => $row->medication_name ?: $row->medication_code ?: 'Medication order',
                    'status' => $row->status,
                    'timestamp' => $row->ordered_at?->toIso8601String(),
                    'detail' => $row->dosage_instruction ?: null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function summarizeRadiology(Collection $rows, int $itemLimit): array
    {
        $counts = $this->countStatuses($rows, ['ordered', 'scheduled', 'in_progress', 'completed', 'cancelled']);
        $followUpStatuses = ['ordered', 'scheduled', 'in_progress'];

        return [
            'follow_up_count' => $this->countKnownStatuses($counts, $followUpStatuses),
            'status_counts' => $counts,
            'items' => $rows
                ->filter(fn (RadiologyOrderModel $row): bool => in_array($this->normalizeStatus($row->status), $followUpStatuses, true))
                ->take($itemLimit)
                ->map(fn (RadiologyOrderModel $row): array => [
                    'id' => $row->id,
                    'number' => $row->order_number,
                    'title' => $row->study_description ?: $row->procedure_code ?: 'Imaging order',
                    'status' => $row->status,
                    'timestamp' => ($row->scheduled_for ?? $row->ordered_at)?->toIso8601String(),
                    'detail' => $row->modality ? ucfirst((string) $row->modality) : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Unlike the three clinical rails above, this one no longer filters rows
     * by status: the revenue ledger decides what "outstanding" means and
     * returns only those documents (OutstandingBalanceReaderInterface). The
     * rail's job is to render them, not to re-derive the definition — that
     * split is what previously let this rail and the patient-flow board
     * disagree about the same patient.
     *
     * @param  list<RevenueDocumentSummary>  $documents
     * @return array<string, mixed>
     */
    private function summarizeBilling(array $documents, int $itemLimit): array
    {
        $counts = [];
        foreach ($documents as $document) {
            $status = $this->normalizeStatus($document->status);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'follow_up_count' => count($documents),
            'status_counts' => $counts,
            'items' => array_map(
                static fn (RevenueDocumentSummary $document): array => [
                    'id' => $document->id,
                    'number' => $document->number,
                    'title' => $document->title,
                    'status' => $document->status,
                    'timestamp' => $document->dueAt ?? $document->occurredAt,
                    'detail' => $document->detail ?? 'Outstanding balance pending',
                ],
                array_slice($documents, 0, $itemLimit),
            ),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<int, string>  $knownStatuses
     * @return array<string, int>
     */
    private function countStatuses(Collection $rows, array $knownStatuses): array
    {
        $counts = ['other' => 0, 'total' => 0];
        foreach ($knownStatuses as $status) {
            $counts[$status] = 0;
        }

        foreach ($rows as $row) {
            $normalizedStatus = $this->normalizeStatus($row->status ?? null);
            $counts['total']++;

            if ($normalizedStatus !== '' && array_key_exists($normalizedStatus, $counts)) {
                $counts[$normalizedStatus]++;
                continue;
            }

            $counts['other']++;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $statuses
     */
    private function countKnownStatuses(array $counts, array $statuses): int
    {
        $total = 0;
        foreach ($statuses as $status) {
            $total += (int) ($counts[$status] ?? 0);
        }

        return $total;
    }

    private function admissionScopedQuery(Builder $query, string $admissionId): Builder
    {
        $this->applyPlatformScopeIfEnabled($query);

        return $query->where('admission_id', $admissionId);
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    private function normalizeStatus(mixed $value): string
    {
        return strtolower(trim((string) ($value ?? '')));
    }
}
