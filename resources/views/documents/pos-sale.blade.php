@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $subtitle = implode(' | ', array_values(array_filter([
        $patient['fullName'] ?? ($sale['customerName'] ?? null),
        ($patient['patientNumber'] ?? null) ? 'Patient '.$patient['patientNumber'] : null,
        ($sale['saleChannel'] ?? null) ? F::enum($sale['saleChannel']) : null,
    ])));
    $currencyCode = $sale['currencyCode'] ?? 'TZS';
    $lineItems = is_array($sale['lineItems'] ?? null) ? $sale['lineItems'] : [];
    $payments = is_array($sale['payments'] ?? null) ? $sale['payments'] : [];
    $adjustments = is_array($sale['adjustments'] ?? null) ? $sale['adjustments'] : [];
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="POS Receipt"
    title="Cash Sale Receipt"
    :subtitle="$subtitle !== '' ? $subtitle : 'Cashier receipt and settlement summary'"
    :document-number="$sale['receiptNumber'] ?? ($sale['saleNumber'] ?? $sale['id'])"
    :status-label="F::enum($sale['status'] ?? 'completed')"
    :generated-at="F::dateTime($generatedAt ?? null)"
>
    <div class="section">
        <p class="section-title">Receipt Summary</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">Sale control</p>
                        <table class="kv">
                            <tr><td class="k">Sale Number</td><td class="v">{{ $sale['saleNumber'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Receipt Number</td><td class="v">{{ $sale['receiptNumber'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Sold At</td><td class="v">{{ F::dateTime($sale['soldAt'] ?? null) }}</td></tr>
                            <tr><td class="k">Channel</td><td class="v">{{ F::enum($sale['saleChannel'] ?? null) }}</td></tr>
                            <tr><td class="k">Customer Type</td><td class="v">{{ F::enum($sale['customerType'] ?? null) }}</td></tr>
                            <tr><td class="k">Cashier</td><td class="v">{{ $completedBy['name'] ?? 'Not recorded' }}</td></tr>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">Settlement totals</p>
                        <table class="kv">
                            <tr><td class="k">Subtotal</td><td class="v">{{ F::money($sale['subtotalAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Discount</td><td class="v">{{ F::money($sale['discountAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Tax</td><td class="v">{{ F::money($sale['taxAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Total</td><td class="v">{{ F::money($sale['totalAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Paid</td><td class="v">{{ F::money($sale['paidAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Change</td><td class="v">{{ F::money($sale['changeAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Balance</td><td class="v">{{ F::money($sale['balanceAmount'] ?? null, $currencyCode) }}</td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Customer And Register</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">Customer</p>
                        <table class="kv">
                            <tr><td class="k">Customer</td><td class="v">{{ $patient['fullName'] ?? ($sale['customerName'] ?? 'Walk-in customer') }}</td></tr>
                            <tr><td class="k">Patient No.</td><td class="v">{{ $patient['patientNumber'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Reference</td><td class="v">{{ $sale['customerReference'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Phone</td><td class="v">{{ $patient['phone'] ?? 'N/A' }}</td></tr>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">Register</p>
                        <table class="kv">
                            <tr><td class="k">Register</td><td class="v">{{ $sale['register']['registerName'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Register Code</td><td class="v">{{ $sale['register']['registerCode'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Location</td><td class="v">{{ $sale['register']['location'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Session</td><td class="v">{{ $sale['session']['sessionNumber'] ?? 'N/A' }}</td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Sale Lines</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Tax</th>
                    <th>Line Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lineItems as $item)
                    @php
                        $sourceParts = array_values(array_filter([
                            !empty($item['metadata']['sourceWorkflowKind']) ? F::enum($item['metadata']['sourceWorkflowKind']) : null,
                            !empty($item['metadata']['source']) ? F::enum($item['metadata']['source']) : null,
                            !empty($item['metadata']['category']) ? F::enum($item['metadata']['category']) : null,
                            !empty($item['metadata']['sourceWorkflowId']) ? 'Ref '.substr((string) $item['metadata']['sourceWorkflowId'], 0, 8) : null,
                        ]));
                    @endphp
                    <tr>
                        <td>
                            <div><strong>{{ $item['itemName'] ?? 'POS item' }}</strong></div>
                            <div class="muted small">{{ implode(' | ', array_values(array_filter([$item['itemCode'] ?? null, !empty($item['itemType']) ? F::enum($item['itemType']) : null]))) }}</div>
                            @if($sourceParts !== [])
                                <div class="muted small">{{ implode(' | ', $sourceParts) }}</div>
                            @endif
                            @if(!empty($item['notes']))
                                <div class="muted small">{{ $item['notes'] }}</div>
                            @endif
                        </td>
                        <td>{{ $item['quantity'] ?? '0' }}</td>
                        <td>{{ F::money($item['unitPrice'] ?? null, $currencyCode) }}</td>
                        <td>{{ F::money($item['taxAmount'] ?? null, $currencyCode) }}</td>
                        <td>{{ F::money($item['lineTotalAmount'] ?? null, $currencyCode) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No sale lines were recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Payments</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Received</th>
                    <th>Applied</th>
                    <th>Change</th>
                    <th>Reference</th>
                    <th>Paid At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr>
                        <td>{{ F::enum($payment['paymentMethod'] ?? null) }}</td>
                        <td>{{ F::money($payment['amountReceived'] ?? null, $currencyCode) }}</td>
                        <td>{{ F::money($payment['amountApplied'] ?? null, $currencyCode) }}</td>
                        <td>{{ F::money($payment['changeGiven'] ?? null, $currencyCode) }}</td>
                        <td>{{ $payment['paymentReference'] ?? 'N/A' }}</td>
                        <td>{{ F::dateTime($payment['paidAt'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No payment rows were recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Adjustments</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Reason</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Processed At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($adjustments as $adjustment)
                    <tr>
                        <td>{{ F::enum($adjustment['adjustmentType'] ?? null) }}</td>
                        <td>{{ implode(' | ', array_values(array_filter([!empty($adjustment['reasonCode']) ? F::enum($adjustment['reasonCode']) : null, $adjustment['adjustmentReference'] ?? null, $adjustment['notes'] ?? null]))) ?: 'Operational correction' }}</td>
                        <td>{{ F::money($adjustment['amount'] ?? null, $currencyCode) }}</td>
                        <td>{{ F::enum($adjustment['paymentMethod'] ?? null) }}</td>
                        <td>{{ F::dateTime($adjustment['processedAt'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No void or refund adjustments were recorded on this sale.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
