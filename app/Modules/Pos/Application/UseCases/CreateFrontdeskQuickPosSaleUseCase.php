<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Billing\Application\UseCases\CreateBillingInvoiceUseCase;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Application\Support\FrontdeskQuickCashierSupport;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSaleLineType;
use Illuminate\Support\Facades\DB;

class CreateFrontdeskQuickPosSaleUseCase
{
    private const INVOICE_SOURCE_KIND_MAP = [
        'laboratory_order' => 'laboratory_order',
        'pharmacy_prescription' => 'pharmacy_order',
        'radiology_order' => 'radiology_order',
        'procedure' => 'theatre_procedure',
    ];

    public function __construct(
        private readonly FrontdeskQuickCashierSupport $frontdeskQuickCashierSupport,
        private readonly CreatePosSaleUseCase $createPosSaleUseCase,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly CreateBillingInvoiceUseCase $createBillingInvoiceUseCase,
    ) {}

    public function execute(array $payload, ?int $actorId = null): ?array
    {
        return DB::transaction(function () use ($payload, $actorId): ?array {
            $normalizedItems = $this->normalizeItems(
                items: $payload['items'] ?? [],
                currencyCode: $this->resolveCurrencyCode($payload['currency_code'] ?? null),
            );

            $firstItem = $normalizedItems[0];

            $sale = $this->createPosSaleUseCase->execute(
                payload: [
                    'pos_register_id' => $payload['pos_register_id'],
                    'patient_id' => $firstItem['order']->patient_id,
                    'customer_type' => 'patient',
                    'customer_name' => $this->frontdeskQuickCashierSupport->patientLabel($firstItem['patient']),
                    'customer_reference' => $firstItem['patient']['patient_number'] ?? null,
                    'currency_code' => $firstItem['currency_code'],
                    'sale_channel' => PosSaleChannel::FRONTDESK_QUICK->value,
                    'sold_at' => $payload['sold_at'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'metadata' => array_merge(
                        is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                        [
                            'source' => 'frontdesk_quick',
                            'orderCount' => count($normalizedItems),
                            'patientNumber' => $firstItem['patient']['patient_number'] ?? null,
                            'sourceKindBreakdown' => collect($normalizedItems)
                                ->groupBy(static fn (array $item): string => $item['candidate']['source_kind'])
                                ->map(static fn ($items): int => count($items))
                                ->all(),
                        ],
                    ),
                    'line_items' => array_map(
                        static function (array $item): array {
                            $candidate = $item['candidate'];
                            return [
                                'item_type' => PosSaleLineType::SERVICE->value,
                                'item_reference' => (string) $item['order']->id,
                                'item_code' => $candidate['service_code'],
                                'item_name' => $candidate['service_name'],
                                'quantity' => 1,
                                'unit_price' => $candidate['unit_price'],
                                'discount_amount' => 0,
                                'tax_amount' => 0,
                                'notes' => $item['note'],
                                'metadata' => [
                                    'source' => 'frontdesk_quick',
                                    'sourceWorkflowKind' => $candidate['source_kind'],
                                    'sourceWorkflowId' => (string) $item['order']->id,
                                    'orderNumber' => $candidate['order_number'],
                                    'billingServiceCode' => $candidate['service_code'],
                                    'pricingSource' => $candidate['pricing_source'],
                                    'pricingSourceId' => $candidate['pricing_source_id'],
                                    'patientNumber' => $item['patient']['patient_number'] ?? null,
                                ],
                            ];
                        },
                        $normalizedItems,
                    ),
                    'payments' => $payload['payments'] ?? [],
                ],
                actorId: $actorId,
            );

            if ((bool) ($payload['create_invoice'] ?? false)) {
                $this->createInvoiceFromSale(
                    sale: $sale,
                    normalizedItems: $normalizedItems,
                    payload: $payload,
                    actorId: $actorId,
                );
            }

            return $sale;
        });
    }

    /**
     * @param  array<string, mixed>  $sale
     * @param  array<int, array<string, mixed>>  $normalizedItems
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createInvoiceFromSale(array $sale, array $normalizedItems, array $payload, ?int $actorId): array
    {
        $firstCandidate = $normalizedItems[0]['candidate'];
        $currencyCode = $normalizedItems[0]['currency_code'];

        $lineItems = [];
        $totalAmount = 0.0;

        foreach ($normalizedItems as $item) {
            $candidate = $item['candidate'];
            $kind = $candidate['source_kind'];
            $mappedKind = self::INVOICE_SOURCE_KIND_MAP[$kind] ?? $kind;
            $unitPrice = round(max((float) ($candidate['unit_price'] ?? 0), 0), 2);
            $lineTotal = round(max((float) ($candidate['line_total'] ?? $unitPrice), 0), 2);

            $lineItems[] = [
                'description' => $candidate['service_name'] ?? 'Service',
                'quantity' => 1,
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal,
                'serviceCode' => $candidate['service_code'] ?? null,
                'sourceWorkflowKind' => $mappedKind,
                'sourceWorkflowId' => $candidate['id'],
                'sourceWorkflowLabel' => $candidate['order_number'] ?? null,
                'sourcePerformedAt' => $candidate['ordered_at'] ?? null,
            ];

            $totalAmount += $lineTotal;
        }

        $totalAmount = round($totalAmount, 2);

        $invoicePayload = [
            'patient_id' => $sale['patient_id'] ?? $normalizedItems[0]['order']->patient_id,
            'appointment_id' => $this->nullableTrimmedValue($firstCandidate['appointment_id'] ?? null),
            'admission_id' => $this->nullableTrimmedValue($firstCandidate['admission_id'] ?? null),
            'currency_code' => $currencyCode,
            'subtotal_amount' => $totalAmount,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $totalAmount,
            'paid_amount' => $totalAmount,
            'balance_amount' => 0,
            'invoice_date' => now()->format('Y-m-d H:i:s'),
            'auto_price_line_items' => false,
            'line_items' => $lineItems,
            'notes' => 'Generated from Frontdesk Quick POS sale #' . ($sale['sale_number'] ?? ''),
        ];

        return $this->createBillingInvoiceUseCase->execute(
            payload: $invoicePayload,
            actorId: $actorId,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items, string $currencyCode): array
    {
        if ($items === []) {
            throw new PosOperationException('At least one service order is required.', 'items');
        }

        $grouped = [];
        foreach (array_values($items) as $index => $item) {
            $kind = trim((string) ($item['kind'] ?? ''));
            $orderId = trim((string) ($item['order_id'] ?? ''));

            if ($kind === '' || $orderId === '') {
                throw new PosOperationException(
                    'Each line must specify a source kind and order ID.',
                    "items.$index.kind",
                );
            }

            $grouped[$kind][] = ['order_id' => $orderId, 'index' => $index, 'note' => $item['note'] ?? null];
        }

        $patientId = null;
        $normalized = [];

        foreach ($grouped as $kind => $kindItems) {
            $config = $this->frontdeskQuickCashierSupport->sourceKindConfig($kind);
            if ($config === null) {
                throw new PosOperationException(
                    "Invalid source kind: {$kind}.",
                    "items.{$kindItems[0]['index']}.kind",
                );
            }

            $orderIds = array_map(static fn (array $ki): string => $ki['order_id'], $kindItems);
            $orders = $this->frontdeskQuickCashierSupport->eligibleOrdersByKindAndIds($kind, $orderIds);

            foreach ($kindItems as $ki) {
                $order = $orders[$ki['order_id']] ?? null;
                if ($order === null) {
                    throw new PosOperationException(
                        'Select an active payable order before checkout.',
                        "items.{$ki['index']}.orderId",
                    );
                }

                if ($patientId === null) {
                    $patientId = (string) $order->patient_id;
                } elseif ($patientId !== (string) $order->patient_id) {
                    throw new PosOperationException(
                        'Frontdesk quick checkout can only settle orders for one patient at a time.',
                        "items.{$ki['index']}.orderId",
                    );
                }

                $normalized[] = [
                    'order' => $order,
                    'kind' => $kind,
                    'note' => $this->nullableTrimmedValue($ki['note']),
                ];
            }
        }

        $patientIndex = $this->frontdeskQuickCashierSupport->patientIndex([$patientId]);
        $allOrderIds = array_map(static fn (array $item): string => (string) $item['order']->id, $normalized);
        $settledIndex = $this->frontdeskQuickCashierSupport->posSettledSourceIndex($allOrderIds);

        $invoicedIndex = $this->frontdeskQuickCashierSupport->invoicedSourceIndex([$patientId]);

        foreach ($normalized as $index => $item) {
            $order = $item['order'];
            $kind = $item['kind'];
            $config = $this->frontdeskQuickCashierSupport->sourceKindConfig($kind);
            $catalogFk = $config['catalog_fk'] ?? null;

            $catalogIndex = $this->frontdeskQuickCashierSupport->clinicalCatalogIndex(
                [$order->{$catalogFk} ?? null],
            );

            $candidate = $this->frontdeskQuickCashierSupport->candidateFromOrder(
                order: $order,
                kind: $kind,
                patient: $patientIndex[(string) $order->patient_id] ?? null,
                catalogItem: $catalogIndex[(string) $order->{$catalogFk}] ?? null,
                currencyCode: $currencyCode,
                catalogFk: $catalogFk,
                invoicedIndex: $invoicedIndex,
                settledIndex: $settledIndex,
            );

            if (($candidate['pricing_status'] ?? null) !== 'priced') {
                throw new PosOperationException(
                    'This order has no active billing price in the current currency.',
                    "items.$index.orderId",
                );
            }

            if ((bool) ($candidate['already_invoiced'] ?? false)) {
                throw new PosOperationException(
                    'This order is already linked to a billing invoice.',
                    "items.$index.orderId",
                );
            }

            if ((bool) ($candidate['already_settled'] ?? false)) {
                throw new PosOperationException(
                    'This order was already settled through frontdesk quick cashier.',
                    "items.$index.orderId",
                );
            }

            $normalized[$index]['patient'] = $patientIndex[(string) $order->patient_id] ?? null;
            $normalized[$index]['candidate'] = $candidate;
            $normalized[$index]['currency_code'] = $currencyCode;
        }

        return $normalized;
    }

    private function resolveCurrencyCode(mixed $value): string
    {
        $currencyCode = strtoupper(trim((string) $value));

        return $currencyCode !== '' ? $currencyCode : $this->defaultCurrencyResolver->resolve();
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
