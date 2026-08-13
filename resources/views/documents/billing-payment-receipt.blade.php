@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $currencyCode = $invoice['currencyCode'] ?? null;
    $payerLabel = !empty($payment['payerType']) ? F::enum($payment['payerType']) : 'Self pay / direct billing';
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Billing Document"
    title="Payment Receipt"
    :subtitle="$invoice['invoiceNumber'] ? 'Invoice '.$invoice['invoiceNumber'] : 'Draft invoice'"
    :document-number="$payment['id']"
    :status-label="null"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Receipt Summary</p>
        <p class="section-subtitle">Patient and payment context</p>
        <table class="two-col">
            <tr>
                <td>
                    <p class="card-title">{{ $patient['fullName'] ?? 'Unknown patient' }}</p>
                    <table class="kv">
                        <tr><td class="k">Patient No.</td><td class="v">{{ $patient['patientNumber'] ?? 'N/A' }}</td></tr>
                        <tr><td class="k">Invoice No.</td><td class="v">{{ $invoice['invoiceNumber'] ?? 'Draft invoice' }}</td></tr>
                        <tr><td class="k">Payment Date</td><td class="v">{{ F::dateTime($payment['paymentAt'] ?? null) }}</td></tr>
                        <tr><td class="k">Payer</td><td class="v">{{ $payerLabel }}</td></tr>
                        <tr><td class="k">Received By</td><td class="v">{{ $recordedBy['name'] ?? 'N/A' }}</td></tr>
                    </table>
                </td>
                <td>
                    <p class="card-title">Payment</p>
                    <table class="kv">
                        <tr><td class="k">Amount Paid</td><td class="v">{{ F::money($payment['amount'] ?? null, $currencyCode) }}</td></tr>
                        <tr><td class="k">Method</td><td class="v">{{ F::enum($payment['paymentMethod'] ?? null) }}</td></tr>
                        <tr><td class="k">Reference</td><td class="v">{{ $payment['paymentReference'] ?? 'N/A' }}</td></tr>
                        <tr><td class="k">Running Paid Total</td><td class="v">{{ F::money($payment['cumulativePaidAmount'] ?? null, $currencyCode) }}</td></tr>
                        <tr><td class="k">Invoice Balance</td><td class="v">{{ F::money($invoice['balanceAmount'] ?? null, $currencyCode) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        @if(!empty($payment['note']))
            <div style="margin-top: 8px; border-top: 1px solid #d8dee8; padding-top: 8px;">
                <p class="card-title">Note</p>
                <div>{{ $payment['note'] }}</div>
            </div>
        @endif
    </div>

    <div class="section">
        <p class="small muted">This receipt confirms the payment above was received against the invoice noted. It does not itself constitute a tax invoice — see the invoice document for the full tax breakdown.</p>
    </div>
</x-documents.pdf-layout>
