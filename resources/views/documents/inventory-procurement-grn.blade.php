@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $request = is_array($request ?? null) ? $request : [];
    $documentNumber = $request['requestNumber'] ?? ($request['purchaseOrderNumber'] ?? 'Procurement receipt');
    $subtitle = implode(' · ', array_values(array_filter([
        $request['itemName'] ?? null,
        $request['supplierName'] ?? null,
    ])));
    $receivedQty = (float) ($request['receivedQuantity'] ?? 0);
    $unitCost = (float) ($request['receivedUnitCost'] ?? $request['unitCostEstimate'] ?? 0);
    $lineTotal = $receivedQty > 0 && $unitCost > 0 ? $receivedQty * $unitCost : null;
    $overviewRows = [
        ['Item code', $request['itemCode'] ?? '—'],
        ['Category', F::enum($request['itemCategory'] ?? '—')],
        ['Supplier', $request['supplierName'] ?? '—'],
        ['Store location', $receivingWarehouseName ?? '—'],
        ['Ordered quantity', number_format((float) ($request['orderedQuantity'] ?? $request['requestedQuantity'] ?? 0), 0, '.', ',').' '.($request['itemUnit'] ?? 'units')],
        ['Received quantity', number_format((float) ($request['receivedQuantity'] ?? 0), 0, '.', ',').' '.($request['itemUnit'] ?? 'units')],
        ['Unit cost', $unitCost > 0 ? number_format($unitCost, 2) : '—'],
        ['Line total', $lineTotal !== null ? number_format($lineTotal, 2) : '—'],
    ];
    $workflowRows = [
        ['Requested by', $requestedBy['name'] ?? 'Not recorded'],
        ['Approved by', $approvedBy['name'] ?? 'Not recorded'],
        ['Received by', $receivedBy['name'] ?? 'Not recorded'],
    ];
    $timelineRows = [
        ['Needed by', F::date($request['neededBy'] ?? null)],
        ['Approved at', F::dateTime($request['approvedAt'] ?? null)],
        ['Ordered at', F::dateTime($request['orderedAt'] ?? null)],
        ['Received at', F::dateTime($request['receivedAt'] ?? null)],
    ];
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Goods Received Note"
    title="Goods Received Note"
    :subtitle="$subtitle !== '' ? $subtitle : 'Goods received into store'"
    :document-number="$documentNumber"
    status-label="Received"
    :generated-at="F::dateTime($generatedAt ?? null)"
>
    <div class="section">
        <p class="section-title">Receipt Summary</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">{{ $request['itemName'] ?? 'Store item' }}</p>
                        @if (! empty($request['purchaseOrderNumber']))
                            <p class="muted">PO {{ $request['purchaseOrderNumber'] }}</p>
                        @endif
                        <table class="kv">
                            @foreach ($overviewRows as [$label, $value])
                                <tr><td class="k">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">Workflow</p>
                        <table class="kv">
                            @foreach ($workflowRows as [$label, $value])
                                <tr><td class="k">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                    <div class="card" style="margin-top: 12px;">
                        <p class="card-title">Timeline</p>
                        <table class="kv">
                            @foreach ($timelineRows as [$label, $value])
                                <tr><td class="k">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if (! empty($request['receivingNotes']) || ! empty($request['notes']))
        <div class="section">
            <p class="section-title">Notes</p>
            <div class="card">
                @if (! empty($request['receivingNotes']))
                    <p>{{ $request['receivingNotes'] }}</p>
                @endif
                @if (! empty($request['notes']))
                    <p class="muted">{{ $request['notes'] }}</p>
                @endif
            </div>
        </div>
    @endif

    <p class="muted" style="margin-top: 16px;">
        This note confirms goods were received into facility store stock. Retain with supplier delivery documentation.
    </p>
</x-documents.pdf-layout>
