<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Collection;

/**
 * Who is waiting to pay, and for how much.
 *
 * One row per patient rather than per charge: a patient standing at the
 * counter with a consultation and two lab tests is one person to serve, and
 * showing them three times would make the queue length a lie.
 */
class ListCashierQueueUseCase
{
    private const PER_PAGE_DEFAULT = 25;

    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(array $filters = []): array
    {
        $status = $this->resolveStatus($filters);
        $search = trim((string) ($filters['q'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? self::PER_PAGE_DEFAULT)), self::PER_PAGE_MAX);

        $query = ServiceChargeModel::query()
            ->whereIn('status', $status)
            ->when(
                $this->scopeContext->facilityId(),
                fn ($q, $facilityId) => $q->where('facility_id', $facilityId),
            );

        $charges = $query->orderBy('created_at')->get();

        $patientsById = $this->patientsFor($charges);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $charges = $charges->filter(function (ServiceChargeModel $charge) use ($patientsById, $needle): bool {
                $patient = $patientsById->get($charge->patient_id);

                return $patient !== null && str_contains(
                    mb_strtolower(trim(sprintf(
                        '%s %s %s',
                        $patient->first_name,
                        $patient->last_name,
                        $patient->patient_number,
                    ))),
                    $needle,
                );
            });
        }

        $rows = $charges
            ->groupBy('patient_id')
            ->map(function (Collection $patientCharges, string $patientId) use ($patientsById): array {
                /** @var ServiceChargeModel $first */
                $first = $patientCharges->first();
                $currency = (string) $first->currency_code;

                $total = $patientCharges->reduce(
                    fn (Money $carry, ServiceChargeModel $charge): Money => $carry->plus($charge->outstandingAmount()),
                    Money::zero($currency),
                );

                $patient = $patientsById->get($patientId);

                return [
                    'patientId' => $patientId,
                    'patientName' => $patient === null ? null : trim(sprintf(
                        '%s %s',
                        $patient->first_name,
                        $patient->last_name,
                    )),
                    'patientNumber' => $patient?->patient_number,
                    'chargeCount' => $patientCharges->count(),
                    'unpricedCount' => $patientCharges
                        ->filter(fn (ServiceChargeModel $c): bool => $c->pricing_status !== 'priced')
                        ->count(),
                    'amountDue' => $total->toDecimalString(),
                    'currencyCode' => $currency,
                    'oldestChargeAt' => $patientCharges->min('created_at')?->toIso8601String(),
                ];
            })
            ->sortBy('oldestChargeAt')
            ->values();

        $total = $rows->count();

        return [
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $facilityId = $this->scopeContext->facilityId();

        $base = fn () => ServiceChargeModel::query()
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId));

        return [
            'awaiting_payment' => (clone $base())
                ->whereIn('status', [
                    ServiceChargeStatus::DRAFT->value,
                    ServiceChargeStatus::PENDING_PAYMENT->value,
                ])
                ->distinct()
                ->count('patient_id'),
            'paid_today' => (clone $base())
                ->where('status', ServiceChargeStatus::AUTHORIZED->value)
                ->whereDate('authorized_at', now()->toDateString())
                ->distinct()
                ->count('patient_id'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveStatus(array $filters): array
    {
        return match ((string) ($filters['status'] ?? 'awaiting_payment')) {
            'paid_today' => [ServiceChargeStatus::AUTHORIZED->value],
            default => [
                ServiceChargeStatus::DRAFT->value,
                ServiceChargeStatus::PENDING_PAYMENT->value,
            ],
        };
    }

    /**
     * @param  Collection<int, ServiceChargeModel>  $charges
     * @return Collection<string, PatientModel>
     */
    private function patientsFor(Collection $charges): Collection
    {
        return PatientModel::query()
            ->whereIn('id', $charges->pluck('patient_id')->unique())
            ->get(['id', 'patient_number', 'first_name', 'last_name'])
            ->keyBy('id');
    }
}
