@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $subtitle = implode(' | ', array_values(array_filter([
        ($appointment['appointmentNumber'] ?? null) ? 'Appointment '.$appointment['appointmentNumber'] : null,
        ($admission['admissionNumber'] ?? null) ? 'Admission '.$admission['admissionNumber'] : null,
    ])));
    $lineItems = is_array($invoice['lineItems'] ?? null) ? $invoice['lineItems'] : [];
    $currencyCode = $invoice['currencyCode'] ?? null;
    $payerLabel = $payer['payerName'] ?? (!empty($payer['payerType']) ? F::enum($payer['payerType']) : 'Self pay / direct billing');
@endphp

@php
    $tin = config('billing-integrations.tra_vfd.totalvfd.tin', '');
    $vrn = config('billing-integrations.tra_vfd.totalvfd.vrn', '');
    $businessName = config('billing-integrations.tra_vfd.totalvfd.business_name', '');
    $businessMobile = config('billing-integrations.tra_vfd.totalvfd.business_mobile', '');
    $taxOffice = config('billing-integrations.tra_vfd.totalvfd.tax_office', '');
    $fiscalReceipt = $invoice['fiscalReceipt'] ?? null;
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Billing Document"
    title="Tax Invoice"
    :subtitle="$subtitle !== '' ? $subtitle : 'No linked encounter'"
    :document-number="$invoice['invoiceNumber'] ?? $invoice['id']"
    :status-label="F::enum($invoice['status'] ?? 'draft')"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Invoice Summary</p>
        <p class="section-subtitle">Patient and billing context</p>
        <table class="two-col">
            <tr>
                <td>
                    <p class="card-title">{{ $patient['fullName'] ?? 'Unknown patient' }}</p>
                    <table class="kv">
                        <tr><td class="k">Patient No.</td><td class="v">{{ $patient['patientNumber'] ?? 'N/A' }}</td></tr>
                        <tr><td class="k">Invoice Date</td><td class="v">{{ F::date($invoice['invoiceDate'] ?? null) }}</td></tr>
                        <tr><td class="k">Due Date</td><td class="v">{{ F::date($invoice['paymentDueAt'] ?? null) }}</td></tr>
                        <tr><td class="k">Payer</td><td class="v">{{ $payerLabel }}</td></tr>
                        <tr><td class="k">Encounter</td><td class="v">{{ $subtitle !== '' ? $subtitle : 'No linked encounter' }}</td></tr>
                    </table>
                </td>
                <td>
                    <p class="card-title">Totals</p>
                    <table class="kv">
                        <tr><td class="k">Subtotal</td><td class="v">{{ F::money($invoice['subtotalAmount'] ?? null, $currencyCode) }}</td></tr>
                        <tr><td class="k">Discount</td><td class="v">{{ F::money($invoice['discountAmount'] ?? null, $currencyCode) }}</td></tr>
                        <tr><td class="k">Tax (VAT)</td><td class="v">{{ F::money($invoice['taxAmount'] ?? null, $currencyCode) }}</td></tr>
                        <tr><td class="k">Grand Total</td><td class="v">{{ F::money($invoice['totalAmount'] ?? null, $currencyCode) }}</td></tr>
                        <tr><td class="k">Collected</td><td class="v">{{ F::money($invoice['paidAmount'] ?? null, $currencyCode) }}</td></tr>
                        <tr><td class="k">Outstanding</td><td class="v">{{ F::money($invoice['balanceAmount'] ?? null, $currencyCode) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        @if($tin || $businessName)
            <div style="margin-top: 8px; border-top: 1px solid #d8dee8; padding-top: 8px; font-size: 9px;">
                @if($businessName)<span><strong>{{ $businessName }}</strong></span><br>@endif
                @if($tin)<span>TIN: {{ $tin }}@if($vrn) | VRN: {{ $vrn }}@endif</span><br>@endif
                @if($taxOffice)<span>Tax Office: {{ $taxOffice }}</span>@endif
                @if($businessMobile)<span> | Mobile: {{ $businessMobile }}</span>@endif
            </div>
        @endif

        @if(!empty($invoice['statusReason']) || !empty($invoice['lastPaymentReference']))
            <div style="margin-top: 8px; border-top: 1px solid #d8dee8; padding-top: 8px;">
                @if(!empty($invoice['statusReason']))
                    <p class="card-title">Status note</p>
                    <div>{{ $invoice['statusReason'] }}</div>
                @endif
                @if(!empty($invoice['lastPaymentReference']))
                    <p class="card-title" style="margin-top: 12px;">Last payment ref</p>
                    <div>{{ $invoice['lastPaymentReference'] }}</div>
                @endif
            </div>
        @endif
    </div>

    <div class="section">
        <p class="section-title">Invoice Lines</p>
        <p class="section-subtitle">Charged services</p>
        @if($lineItems !== [])
            <table class="table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lineItems as $lineItem)
                        @php
                            $quantity = (float) ($lineItem['quantity'] ?? 0);
                            $unitPrice = (float) ($lineItem['unitPrice'] ?? 0);
                            $lineTotal = $lineItem['lineTotal'] ?? ($quantity * $unitPrice);
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $lineItem['description'] ?? 'Line item' }}</strong>
                                @if(!empty($lineItem['serviceCode']) || !empty($lineItem['unit']))
                                    <div class="small muted">
                                        @if(!empty($lineItem['serviceCode']))
                                            Code: {{ $lineItem['serviceCode'] }}
                                        @endif
                                        @if(!empty($lineItem['serviceCode']) && !empty($lineItem['unit']))
                                            |
                                        @endif
                                        @if(!empty($lineItem['unit']))
                                            Unit: {{ $lineItem['unit'] }}
                                        @endif
                                    </div>
                                @endif
                                @if(!empty($lineItem['nhifCode']))
                                    <div class="small muted">NHIF: {{ $lineItem['nhifCode'] }}</div>
                                @endif
                            </td>
                            <td>{{ rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.') }}</td>
                            <td>{{ F::money($unitPrice, $currencyCode) }}</td>
                            <td>{{ F::money($lineTotal, $currencyCode) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="muted">No invoice line items were recorded.</div>
        @endif
    </div>

    @if($fiscalReceipt)
        <div class="section">
            <p class="section-title">Fiscal Receipt (TRA)</p>
            <p class="section-subtitle">Electronic Fiscal Device receipt details</p>
            <table class="kv">
                <tr><td class="k">Receipt No.</td><td class="v">{{ $fiscalReceipt['rctvnum'] ?? 'N/A' }}</td></tr>
                <tr><td class="k">Z Number</td><td class="v">{{ $fiscalReceipt['zNumber'] ?? 'N/A' }}</td></tr>
                <tr><td class="k">Date</td><td class="v">{{ $fiscalReceipt['localDate'] ?? '' }} {{ $fiscalReceipt['localTime'] ?? '' }}</td></tr>
                <tr><td class="k">GC / DC</td><td class="v">{{ $fiscalReceipt['gc'] ?? '' }} / {{ $fiscalReceipt['dc'] ?? '' }}</td></tr>
            </table>
            @if(!empty($fiscalReceipt['verificationLink']))
                <div style="margin-top: 8px; text-align: center;">
                    <p class="small muted">Verify: {{ $fiscalReceipt['verificationLink'] }}</p>
                </div>
            @endif
        </div>
    @endif
</x-documents.pdf-layout>
