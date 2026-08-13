<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Application\Support\LabQuickCashierSupport;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSaleLineType;
use Illuminate\Support\Facades\DB;

class CreateLabQuickPosSaleUseCase
{
    public function __construct(
        private readonly LabQuickCashierSupport $labQuickCashierSupport,
        private readonly CreatePosSaleUseCase $createPosSaleUseCase,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
    ) {}

    public function execute(array $payload, ?int $actorId = null): ?array
    {
        return DB::transaction(function () use ($payload, $actorId): ?array {
            $normalizedItems = $this->normalizeItems(
                items: $payload['items'] ?? [],
                currencyCode: $this->resolveCurrencyCode($payload['currency_code'] ?? null),
            );

            $patient = $normalizedItems[0]['patient'];

            return $this->createPosSaleUseCase->execute(
                payload: [
                    'pos_register_id' => $payload['pos_register_id'],
                    'patient_id' => $normalizedItems[0]['order']->patient_id,
                    'customer_type' => 'patient',
                    'customer_name' => $this->labQuickCashierSupport->patientLabel($patient),
                    'customer_reference' => $patient['patient_number'] ?? null,
                    'currency_code' => $normalizedItems[0]['currency_code'],
                    'sale_channel' => PosSaleChannel::LAB_QUICK->value,
                    'sold_at' => $payload['sold_at'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'metadata' => array_merge(
                        is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                        [
                            'source' => 'lab_quick',
                            'laboratoryOrderCount' => count($normalizedItems),
                            'patientNumber' => $patient['patient_number'] ?? null,
                            'orderIds' => array_map(
                                static fn (array $item): string => (string) $item['order']->id,
                                $normalizedItems,
                            ),
                        ],
                    ),
                    'line_items' => array_map(
                        static function (array $item): array {
                            return [
                                'item_type' => PosSaleLineType::SERVICE->value,
                                'item_reference' => (string) $item['order']->id,
                                'item_code' => $item['candidate']['test_code'] ?? null,
                                'item_name' => $item['candidate']['service_name'],
                                'quantity' => 1,
                                'unit_price' => $item['candidate']['unit_price'],
                                'discount_amount' => 0,
                                'tax_amount' => 0,
                                'notes' => $item['note'],
                                'metadata' => [
                                    'source' => 'lab_quick',
                                    'sourceWorkflowKind' => 'laboratory_order',
                                    'sourceWorkflowId' => (string) $item['order']->id,
                                    'orderNumber' => $item['candidate']['order_number'] ?? null,
                                    'testCode' => $item['candidate']['test_code'] ?? null,
                                    'testName' => $item['candidate']['test_name'] ?? null,
                                    'billingServiceCode' => $item['candidate']['service_code'] ?? null,
                                    'pricingSource' => $item['candidate']['pricing_source'] ?? null,
                                    'pricingSourceId' => $item['candidate']['pricing_source_id'] ?? null,
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
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items, string $currencyCode): array
    {
        if ($items === []) {
            throw new PosOperationException('At least one laboratory order is required.', 'items');
        }

        $orderIds = array_map(
            static fn (array $item): string => trim((string) ($item['order_id'] ?? '')),
            array_values($items),
        );
        $orders = $this->labQuickCashierSupport->eligibleOrdersByIds($orderIds);

        $patientId = null;
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $orderId = trim((string) ($item['order_id'] ?? ''));
            if ($orderId === '') {
                throw new PosOperationException(
                    'Select one laboratory order for each quick cashier line.',
                    "items.$index.orderId",
                );
            }

            $order = $orders[$orderId] ?? null;
            if ($order === null) {
                throw new PosOperationException(
                    'Select an active payable laboratory order before checkout.',
                    "items.$index.orderId",
                );
            }

            if ($patientId === null) {
                $patientId = (string) $order->patient_id;
            } elseif ($patientId !== (string) $order->patient_id) {
                throw new PosOperationException(
                    'Lab quick checkout can only settle orders for one patient at a time.',
                    "items.$index.orderId",
                );
            }

            $normalized[] = [
                'order' => $order,
                'note' => $this->nullableTrimmedValue($item['note'] ?? null),
            ];
        }

        $patientIndex = $this->labQuickCashierSupport->patientIndex([$patientId]);
        $catalogIndex = $this->labQuickCashierSupport->clinicalCatalogIndex(array_map(
            static fn (array $item): mixed => $item['order']->lab_test_catalog_item_id,
            $normalized,
        ));
        $invoicedIndex = $this->labQuickCashierSupport->invoicedSourceIndex([$patientId]);
        $settledIndex = $this->labQuickCashierSupport->posSettledSourceIndex(array_map(
            static fn (array $item): mixed => $item['order']->id,
            $normalized,
        ));
        $orders = collect(array_map(static fn (array $item) => $item['order'], $normalized));
        $pricingMap = $this->labQuickCashierSupport->batchPricingIndex($orders, $catalogIndex, $currencyCode);

        foreach ($normalized as $index => $item) {
            $order = $item['order'];
            $candidate = $this->labQuickCashierSupport->candidateFromOrder(
                order: $order,
                patient: $patientIndex[(string) $order->patient_id] ?? null,
                catalogItem: $catalogIndex[(string) $order->lab_test_catalog_item_id] ?? null,
                currencyCode: $currencyCode,
                invoicedIndex: $invoicedIndex,
                settledIndex: $settledIndex,
                pricing: $pricingMap[(string) $order->id] ?? null,
            );

            if (($candidate['pricing_status'] ?? null) !== 'priced') {
                throw new PosOperationException(
                    'This laboratory order has no active billing price in the current currency.',
                    "items.$index.orderId",
                );
            }

            if ((bool) ($candidate['already_invoiced'] ?? false)) {
                throw new PosOperationException(
                    'This laboratory order is already linked to a billing invoice.',
                    "items.$index.orderId",
                );
            }

            if ((bool) ($candidate['already_settled'] ?? false)) {
                throw new PosOperationException(
                    'This laboratory order was already settled through lab quick cashier.',
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
