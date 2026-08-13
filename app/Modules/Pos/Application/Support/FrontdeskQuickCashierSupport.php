<?php

namespace App\Modules\Pos\Application\Support;

use App\Modules\Billing\Domain\Services\ChargeResolverInterface;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use App\Modules\Platform\Domain\ValueObjects\ClinicalSourceKind;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSaleStatus;
use App\Modules\Pos\Infrastructure\Models\PosSaleLineModel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

class FrontdeskQuickCashierSupport
{
    public function __construct(
        private readonly ChargeResolverInterface $chargeResolver,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function eligibleStatuses(): array
    {
        $statuses = [];
        foreach (ClinicalSourceKind::orderKinds() as $kind) {
            foreach ($kind->posStatuses() as $status) {
                $statuses[$status] = true;
            }
        }
        return array_keys($statuses);
    }

    public function isEligibleStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), $this->eligibleStatuses(), true);
    }

    public function sourceKindConfig(string $kind): ?array
    {
        $enum = ClinicalSourceKind::fromWorkflowKind($kind);
        if ($enum === null) {
            return null;
        }

        $config = [
            'model' => $enum->modelClass(),
            'catalog_fk' => $enum->catalogFk(),
            'statuses' => $enum->posStatuses(),
            'exclude_entered_in_error' => $enum->posExcludeEnteredInError(),
        ];

        $entryState = $enum->posEntryState();
        if ($entryState !== null) {
            $config['entry_state'] = $entryState;
        }

        return $config;
    }

    public function eligibleOrderQuery(string $kind): ?EloquentBuilder
    {
        $config = $this->sourceKindConfig($kind);
        if ($config === null) {
            return null;
        }

        $modelClass = $config['model'];
        $query = $modelClass::query()
            ->whereIn('status', $config['statuses']);

        if ($config['exclude_entered_in_error'] ?? false) {
            $query->whereNull('entered_in_error_at');
        }

        if (isset($config['entry_state'])) {
            $query->where('entry_state', $config['entry_state']);
        }

        $this->applyPlatformScopeIfEnabled($query);

        return $query;
    }

    /**
     * @return array<int, string>
     */
    public function patientSearchIds(string $query, int $limit = 50): array
    {
        $normalizedQuery = trim($query);
        if ($normalizedQuery === '') {
            return [];
        }

        $like = '%'.strtolower($normalizedQuery).'%';
        $patientQuery = PatientModel::query()
            ->select('id')
            ->where(function (EloquentBuilder $builder) use ($like): void {
                $builder->whereRaw('LOWER(patient_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(middle_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like]);
            })
            ->limit(max($limit, 1));

        $this->applyPlatformScopeIfEnabled($patientQuery, 'tenant_id', null);

        return $patientQuery
            ->pluck('id')
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $orderIds
     * @return array<string, Model>
     */
    public function eligibleOrdersByKindAndIds(string $kind, array $orderIds): array
    {
        $normalizedIds = $this->normalizeIds($orderIds);
        if ($normalizedIds === []) {
            return [];
        }

        $config = $this->sourceKindConfig($kind);
        if ($config === null) {
            return [];
        }

        $modelClass = $config['model'];
        $query = $modelClass::query()
            ->whereIn('id', $normalizedIds)
            ->whereIn('status', $config['statuses']);

        if ($config['exclude_entered_in_error'] ?? false) {
            $query->whereNull('entered_in_error_at');
        }

        if (isset($config['entry_state'])) {
            $query->where('entry_state', $config['entry_state']);
        }

        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->get()
            ->mapWithKeys(static fn (Model $order): array => [
                (string) $order->id => $order,
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $patientIds
     * @return array<string, array<string, mixed>>
     */
    public function patientIndex(array $patientIds): array
    {
        $normalizedIds = $this->normalizeIds($patientIds);
        if ($normalizedIds === []) {
            return [];
        }

        $query = PatientModel::query()
            ->whereIn('id', $normalizedIds);

        $this->applyPlatformScopeIfEnabled($query, 'tenant_id', null);

        return $query
            ->get()
            ->mapWithKeys(static fn (PatientModel $patient): array => [
                (string) $patient->id => $patient->toArray(),
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $catalogItemIds
     * @return array<string, array<string, mixed>>
     */
    public function clinicalCatalogIndex(array $catalogItemIds): array
    {
        $normalizedIds = $this->normalizeIds($catalogItemIds);
        if ($normalizedIds === []) {
            return [];
        }

        $query = ClinicalCatalogItemModel::query()
            ->whereIn('id', $normalizedIds);

        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->get()
            ->mapWithKeys(static fn (ClinicalCatalogItemModel $item): array => [
                (string) $item->id => $item->toArray(),
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $patientIds
     * @return array<string, array<string, mixed>>
     */
    public function invoicedSourceIndex(array $patientIds): array
    {
        $normalizedPatientIds = $this->normalizeIds($patientIds);
        if ($normalizedPatientIds === []) {
            return [];
        }

        $query = BillingInvoiceModel::query()
            ->whereIn('patient_id', $normalizedPatientIds)
            ->whereNotIn('status', ['cancelled', 'voided']);

        $this->applyPlatformScopeIfEnabled($query);

        $index = [];

        $query->get(['id', 'invoice_number', 'status', 'line_items'])
            ->each(function (BillingInvoiceModel $invoice) use (&$index): void {
                foreach ((array) ($invoice->line_items ?? []) as $lineItem) {
                    if (! is_array($lineItem)) {
                        continue;
                    }

                    $kind = strtolower(trim((string) ($lineItem['sourceWorkflowKind'] ?? '')));
                    $sourceId = trim((string) ($lineItem['sourceWorkflowId'] ?? ''));
                    $kindEnum = ClinicalSourceKind::fromWorkflowKind($kind);
                    if ($kindEnum === null || $sourceId === '') {
                        continue;
                    }

                    $normalizedKind = $kindEnum->value;
                    $index[$normalizedKind][$sourceId] = [
                        'invoiceId' => (string) $invoice->id,
                        'invoiceNumber' => $invoice->invoice_number,
                        'invoiceStatus' => $invoice->status,
                    ];
                }
            });

        return $index;
    }

    /**
     * PricingEngine_Migration_Plan.md Phase 5: prices via chargeable_item_id
     * (price_book_entries), not the legacy service-catalog string match --
     * every order-domain's own catalog FK doubles as the chargeable item id
     * under the Phase 1 ID-reuse convention, the same FK
     * ListBillingChargeCaptureCandidatesUseCase resolves through for the
     * charge-capture flow.
     *
     * @param  \Illuminate\Support\Collection<int, Model>  $orders
     * @param  string  $kind
     * @param  string  $catalogFk
     * @param  array<string, array<string, mixed>>  $catalogIndex
     * @return array<string, array<string, mixed>|null>  Map of order_id => resolved price or null
     */
    public function batchPricingIndex($orders, string $kind, string $catalogFk, array $catalogIndex, string $currencyCode): array
    {
        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        $result = [];
        foreach ($orders as $order) {
            $chargeableItemId = $this->nullableTrimmedValue($order->{$catalogFk} ?? null);
            if ($chargeableItemId === null) {
                $result[(string) $order->id] = null;

                continue;
            }

            $resolved = $this->chargeResolver->resolvePrice(
                chargeableItemId: $chargeableItemId,
                quantityOrDuration: 1.0,
                asOfDate: $this->performedAt($order),
                tenantId: $tenantId,
                facilityId: $facilityId,
                payerContractId: null,
                currencyCode: $currencyCode,
            );

            $result[(string) $order->id] = $resolved['pricingStatus'] === 'priced' ? $resolved : null;
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $orderIds
     * @return array<string, array<string, mixed>>
     */
    public function posSettledSourceIndex(array $orderIds): array
    {
        $normalizedOrderIds = $this->normalizeIds($orderIds);
        if ($normalizedOrderIds === []) {
            return [];
        }

        $query = PosSaleLineModel::query()
            ->select([
                'pos_sale_lines.item_reference',
                'pos_sales.id as sale_id',
                'pos_sales.sale_number',
                'pos_sales.receipt_number',
                'pos_sales.sold_at',
            ])
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.pos_sale_id')
            ->where('pos_sale_lines.item_type', 'service')
            ->whereIn('pos_sale_lines.item_reference', $normalizedOrderIds)
            ->where('pos_sales.sale_channel', PosSaleChannel::FRONTDESK_QUICK->value)
            ->where('pos_sales.status', PosSaleStatus::COMPLETED->value)
            ->orderByDesc('pos_sales.sold_at')
            ->orderByDesc('pos_sales.created_at');

        $this->applyPlatformScopeIfEnabled($query, 'pos_sales.tenant_id', 'pos_sales.facility_id');

        $index = [];

        foreach ($query->get() as $row) {
            $sourceId = trim((string) ($row->item_reference ?? ''));
            if ($sourceId === '' || array_key_exists($sourceId, $index)) {
                continue;
            }

            $index[$sourceId] = [
                'saleId' => (string) ($row->sale_id ?? ''),
                'saleNumber' => $row->sale_number,
                'receiptNumber' => $row->receipt_number,
                'soldAt' => $this->dateTimeString($row->sold_at ?? null),
            ];
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>|null  $patient
     * @param  array<string, mixed>|null  $catalogItem
     * @param  array<string, array<string, mixed>>  $invoicedIndex
     * @param  array<string, array<string, mixed>>  $settledIndex
     * @param  array<string, mixed>|null  $pricing  Pre-resolved ChargeResolver result (skips DB when provided)
     * @return array<string, mixed>
     */
    public function candidateFromOrder(
        Model $order,
        string $kind,
        ?array $patient,
        ?array $catalogItem,
        string $currencyCode,
        string $catalogFk,
        array $invoicedIndex,
        array $settledIndex,
        ?array $pricing = null,
    ): array {
        $chargeableItemId = $this->nullableTrimmedValue($order->{$catalogFk} ?? null);

        if ($pricing === null && $chargeableItemId !== null) {
            $resolved = $this->chargeResolver->resolvePrice(
                chargeableItemId: $chargeableItemId,
                quantityOrDuration: 1.0,
                asOfDate: $this->performedAt($order),
                tenantId: $this->platformScopeContext->tenantId(),
                facilityId: $this->platformScopeContext->facilityId(),
                payerContractId: null,
                currencyCode: $currencyCode,
            );
            $pricing = $resolved['pricingStatus'] === 'priced' ? $resolved : null;
        }

        $resolvedServiceCode = strtoupper(trim((string) $this->resolveServiceCode($order, $catalogItem, $kind)));
        $unitPrice = round(max((float) ($pricing['unitPrice'] ?? 0), 0), 2);
        $pricingStatus = $pricing !== null
            ? 'priced'
            : ($chargeableItemId !== null ? 'missing_catalog_price' : 'missing_service_code');

        $kindInvoicedIndex = $invoicedIndex[$kind] ?? [];
        $invoiceLink = $kindInvoicedIndex[(string) $order->id] ?? null;
        $settledSale = $settledIndex[(string) $order->id] ?? null;

        return [
            'id' => (string) $order->id,
            'source_kind' => $kind,
            'order_number' => $this->orderNumber($order, $kind),
            'patient_id' => (string) $order->patient_id,
            'patient_number' => $patient['patient_number'] ?? null,
            'patient_name' => $this->patientLabel($patient),
            'appointment_id' => $this->nullableTrimmedValue($order->appointment_id ?? null),
            'admission_id' => $this->nullableTrimmedValue($order->admission_id ?? null),
            'service_code' => $resolvedServiceCode !== '' ? $resolvedServiceCode : null,
            'service_name' => $this->resolveServiceName($order, $catalogItem),
            'unit' => $this->resolveUnit($catalogItem),
            'source_status' => $order->status,
            'ordered_at' => $this->dateTimeString($order->ordered_at ?? $order->created_at ?? null),
            'performed_at' => $this->performedAt($order),
            'currency_code' => $currencyCode,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice,
            'pricing_status' => $pricingStatus,
            'pricing_source' => $pricing !== null ? 'chargeable_item' : null,
            'pricing_source_id' => $pricing !== null ? $chargeableItemId : null,
            'already_invoiced' => $invoiceLink !== null,
            'invoice_id' => $invoiceLink['invoiceId'] ?? null,
            'invoice_number' => $invoiceLink['invoiceNumber'] ?? null,
            'invoice_status' => $invoiceLink['invoiceStatus'] ?? null,
            'already_settled' => $settledSale !== null,
            'settled_sale_id' => $settledSale['saleId'] ?? null,
            'settled_sale_number' => $settledSale['saleNumber'] ?? null,
            'settled_receipt_number' => $settledSale['receiptNumber'] ?? null,
            'settled_sold_at' => $settledSale['soldAt'] ?? null,
            'metadata' => [
                'catalogItemId' => $this->nullableTrimmedValue($order->{$catalogFk} ?? null),
                'billingServiceCode' => $resolvedServiceCode !== '' ? $resolvedServiceCode : null,
                'sourceWorkflowKind' => $kind,
                'sourceWorkflowId' => (string) $order->id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $patient
     */
    public function patientLabel(?array $patient): string
    {
        if ($patient === null) {
            return 'Patient';
        }

        $parts = array_values(array_filter([
            trim((string) ($patient['first_name'] ?? '')),
            trim((string) ($patient['middle_name'] ?? '')),
            trim((string) ($patient['last_name'] ?? '')),
        ]));

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        $patientNumber = trim((string) ($patient['patient_number'] ?? ''));

        return $patientNumber !== '' ? $patientNumber : 'Patient';
    }

    public function applyPlatformScopeIfEnabled(
        EloquentBuilder|QueryBuilder $query,
        ?string $tenantColumn = 'tenant_id',
        ?string $facilityColumn = 'facility_id',
    ): void {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query, $tenantColumn, $facilityColumn);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $ids,
        ), static fn (string $value): bool => $value !== '')));
    }

    public function resolveServiceCode(Model $order, ?array $catalogItem, string $kind): ?string
    {
        $codeFields = [
            match (ClinicalSourceKind::fromWorkflowKind($kind)) {
                ClinicalSourceKind::LABORATORY_ORDER => $order->test_code ?? null,
                ClinicalSourceKind::PHARMACY_ORDER => $order->medication_code ?? null,
                ClinicalSourceKind::RADIOLOGY_ORDER => $order->procedure_code ?? null,
                ClinicalSourceKind::CLINICAL_PROCEDURE_ORDER => $order->procedure_code ?? null,
                default => null,
            },
        ];

        $metadata = is_array($catalogItem['metadata'] ?? null)
            ? $catalogItem['metadata']
            : [];

        foreach ([...$codeFields, $metadata['billingServiceCode'] ?? null, $metadata['billing_service_code'] ?? null, $catalogItem['code'] ?? null] as $value) {
            $normalized = strtoupper(trim((string) $value));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     */
    private function resolveServiceName(Model $order, ?array $catalogItem): string
    {
        $fallbacks = [$catalogItem['name'] ?? null, ...$this->orderNameFallbacks($order)];

        foreach ($fallbacks as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'Service';
    }

    private function orderNameFallbacks(Model $order): array
    {
        if ($order instanceof LaboratoryOrderModel) {
            return [$order->test_name, $order->test_code];
        }
        if ($order instanceof PharmacyOrderModel) {
            return [$order->medication_name, $order->medication_code];
        }
        if ($order instanceof RadiologyOrderModel) {
            return [$order->study_description ?? $order->procedure_code];
        }
        if ($order instanceof ClinicalProcedureOrderModel) {
            return [$order->procedure_description, $order->procedure_code];
        }
        if ($order instanceof TheatreProcedureModel) {
            return [$order->procedure_name, $order->procedure_type];
        }
        return [];
    }

    /**
     * @param  array<string, mixed>|null  $catalogItem
     */
    private function resolveUnit(?array $catalogItem): string
    {
        $normalized = trim((string) ($catalogItem['unit'] ?? ''));

        return $normalized !== '' ? $normalized : 'unit';
    }

    private function orderNumber(Model $order, string $kind): string
    {
        return $order->order_number
            ?? $order->procedure_number
            ?? $order->claim_number
            ?? (string) $order->id;
    }

    private function performedAt(Model $order): ?string
    {
        return $this->dateTimeString(
            $order->resulted_at
            ?? $order->updated_at
            ?? $order->ordered_at
            ?? $order->created_at,
        );
    }

    private function dateTimeString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
