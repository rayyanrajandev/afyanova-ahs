<?php

namespace App\Modules\Revenue\Infrastructure\Services;

use App\Modules\Revenue\Domain\Services\OutstandingBalanceReaderInterface;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\RevenueDocumentSummary;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;

/**
 * Answers "does this patient owe anything?" from the prepaid ledger.
 *
 * One definition of outstanding lives here — draft or pending_payment — so the
 * patient summary, the flow board and the ward rail can no longer disagree
 * about the same patient, which they could when each ran its own invoice-status
 * query against the ledger this replaced.
 */
class LedgerOutstandingBalanceReader implements OutstandingBalanceReaderInterface
{
    /**
     * @var list<string>
     */
    private const OUTSTANDING_STATUSES = [
        ServiceChargeStatus::DRAFT->value,
        ServiceChargeStatus::PENDING_PAYMENT->value,
    ];

    public function outstandingCountForPatient(string $patientId): int
    {
        return ServiceChargeModel::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', self::OUTSTANDING_STATUSES)
            ->count();
    }

    public function patientsWithOutstanding(array $patientIds): array
    {
        $result = array_fill_keys($patientIds, false);

        if ($patientIds === []) {
            return $result;
        }

        $owing = ServiceChargeModel::query()
            ->whereIn('patient_id', $patientIds)
            ->whereIn('status', self::OUTSTANDING_STATUSES)
            ->distinct()
            ->pluck('patient_id');

        foreach ($owing as $patientId) {
            $result[(string) $patientId] = true;
        }

        return $result;
    }

    public function latestDocumentForPatient(string $patientId): ?RevenueDocumentSummary
    {
        $charge = ServiceChargeModel::query()
            ->where('patient_id', $patientId)
            ->latest('created_at')
            ->first();

        return $charge === null ? null : $this->toSummary($charge);
    }

    public function outstandingDocumentsForAdmission(string $admissionId): array
    {
        return ServiceChargeModel::query()
            ->where('admission_id', $admissionId)
            ->whereIn('status', self::OUTSTANDING_STATUSES)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ServiceChargeModel $charge): RevenueDocumentSummary => $this->toSummary($charge))
            ->values()
            ->all();
    }

    private function toSummary(ServiceChargeModel $charge): RevenueDocumentSummary
    {
        $outstanding = $charge->outstandingAmount();

        return new RevenueDocumentSummary(
            id: (string) $charge->id,
            number: (string) $charge->charge_number,
            title: (string) $charge->description,
            status: $charge->status->value,
            occurredAt: $charge->created_at?->toIso8601String(),
            dueAt: null,
            detail: $this->describe($outstanding, $charge),
        );
    }

    private function describe(Money $outstanding, ServiceChargeModel $charge): string
    {
        if ($charge->pricing_status !== null && $charge->pricing_status !== 'priced') {
            return 'Not yet priced';
        }

        return $outstanding->isPositive()
            ? sprintf('%s %s outstanding', $outstanding->currencyCode, $outstanding->toDecimalString())
            : 'Settled';
    }
}
