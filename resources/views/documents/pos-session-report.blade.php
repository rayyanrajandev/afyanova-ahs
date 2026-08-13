@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $currencyCode = $session['register']['defaultCurrencyCode'] ?? 'TZS';
    $metrics = is_array($session['closeoutPreview'] ?? null) ? $session['closeoutPreview'] : $session;
    $sales = is_array($sales ?? null) ? $sales : [];
    $adjustments = is_array($adjustments ?? null) ? $adjustments : [];
    $channelBreakdown = is_array($channelBreakdown ?? null) ? $channelBreakdown : [];
    $paymentBreakdown = is_array($paymentBreakdown ?? null) ? $paymentBreakdown : [];
    $subtitle = implode(' | ', array_values(array_filter([
        $session['register']['registerName'] ?? null,
        $session['register']['location'] ?? null,
        !empty($session['status']) ? F::enum($session['status']) : null,
    ])));
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="POS Session Report"
    title="Cashier Shift Report"
    :subtitle="$subtitle !== '' ? $subtitle : 'Cashier shift activity and reconciliation report'"
    :document-number="$session['sessionNumber'] ?? $session['id']"
    :status-label="F::enum($session['status'] ?? null)"
    :generated-at="F::dateTime($generatedAt ?? null)"
>
    <div class="section">
        <p class="section-title">Session Overview</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">Register and shift</p>
                        <table class="kv">
                            <tr><td class="k">Register</td><td class="v">{{ $session['register']['registerName'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Register Code</td><td class="v">{{ $session['register']['registerCode'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Location</td><td class="v">{{ $session['register']['location'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Opened At</td><td class="v">{{ F::dateTime($session['openedAt'] ?? null) }}</td></tr>
                            <tr><td class="k">Closed At</td><td class="v">{{ F::dateTime($session['closedAt'] ?? null) }}</td></tr>
                            <tr><td class="k">Opened By</td><td class="v">{{ $openedBy['name'] ?? 'Not recorded' }}</td></tr>
                            <tr><td class="k">Closed By</td><td class="v">{{ $closedBy['name'] ?? ($session['closedAt'] ? 'Not recorded' : 'Session still open') }}</td></tr>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">Settlement totals</p>
                        <table class="kv">
                            <tr><td class="k">Opening Cash</td><td class="v">{{ F::money($session['openingCashAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Expected Cash</td><td class="v">{{ F::money($metrics['expectedCashAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Counted Cash</td><td class="v">{{ F::money($session['closingCashAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Variance</td><td class="v">{{ F::money($session['discrepancyAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Gross Sales</td><td class="v">{{ F::money($metrics['grossSalesAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Cash Sales</td><td class="v">{{ F::money($metrics['cashNetSalesAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Non-cash Sales</td><td class="v">{{ F::money($metrics['nonCashSalesAmount'] ?? null, $currencyCode) }}</td></tr>
                            <tr><td class="k">Adjustments</td><td class="v">{{ F::money($metrics['adjustmentAmount'] ?? null, $currencyCode) }}</td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if(!empty($session['openingNote']) || !empty($session['closingNote']))
        <div class="section">
            <p class="section-title">Shift Notes</p>
            <table class="two-col">
                <tr>
                    @if(!empty($session['openingNote']))
                        <td>
                            <div class="card">
                                <p class="card-title">Opening note</p>
                                <div>{{ $session['openingNote'] }}</div>
                            </div>
                        </td>
                    @endif
                    @if(!empty($session['closingNote']))
                        <td>
                            <div class="card">
                                <p class="card-title">Closing note</p>
                                <div>{{ $session['closingNote'] }}</div>
                            </div>
                        </td>
                    @endif
                </tr>
            </table>
        </div>
    @endif

    <div class="section">
        <p class="section-title">Channel Breakdown</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Channel</th>
                    <th>Sales</th>
                    <th>Total</th>
                    <th>Paid</th>
                </tr>
            </thead>
            <tbody>
                @forelse($channelBreakdown as $row)
                    <tr>
                        <td>{{ F::enum($row['saleChannel'] ?? null) }}</td>
                        <td>{{ $row['saleCount'] ?? 0 }}</td>
                        <td>{{ F::money($row['totalAmount'] ?? null, $currencyCode) }}</td>
                        <td>{{ F::money($row['paidAmount'] ?? null, $currencyCode) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No sale rows were recorded for this session.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Payment Breakdown</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Entries</th>
                    <th>Received</th>
                    <th>Applied</th>
                </tr>
            </thead>
            <tbody>
                @forelse($paymentBreakdown as $row)
                    <tr>
                        <td>{{ F::enum($row['paymentMethod'] ?? null) }}</td>
                        <td>{{ $row['paymentCount'] ?? 0 }}</td>
                        <td>{{ F::money($row['amountReceived'] ?? null, $currencyCode) }}</td>
                        <td>{{ F::money($row['amountApplied'] ?? null, $currencyCode) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No payment rows were recorded for this session.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Sales Ledger</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Sale</th>
                    <th>Channel</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>Sold At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                    <tr>
                        <td>
                            <div><strong>{{ $sale['receiptNumber'] ?? ($sale['saleNumber'] ?? 'Sale row') }}</strong></div>
                            <div class="muted small">{{ $sale['saleNumber'] ?? 'No sale number' }}</div>
                        </td>
                        <td>{{ F::enum($sale['saleChannel'] ?? null) }}</td>
                        <td>{{ $sale['customerName'] ?? F::enum($sale['customerType'] ?? null) }}</td>
                        <td>{{ F::enum($sale['status'] ?? null) }}</td>
                        <td>{{ F::money($sale['totalAmount'] ?? null, $currencyCode) }}</td>
                        <td>{{ F::dateTime($sale['soldAt'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No sales were recorded in this session.</td></tr>
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
                    <tr><td colspan="5">No session adjustments were recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
