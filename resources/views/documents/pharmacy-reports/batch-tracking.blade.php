@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All batch records';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report"
    title="Batch Tracking"
    :subtitle="$filterDescription"
    document-number="BAT-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Batch Inventory Details</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Batch #</th>
                    <th>Received</th>
                    <th>Dispensed</th>
                    <th>Remaining</th>
                    <th>Expiry</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['itemName'] ?? $item['itemCode'] ?? '—' }}</td>
                        <td>{{ $item['batchNumber'] ?? '—' }}</td>
                        <td>{{ number_format($item['receivedQuantity'] ?? 0) }}</td>
                        <td>{{ number_format($item['dispensedQuantity'] ?? 0) }}</td>
                        <td><strong>{{ number_format($item['currentQuantity'] ?? 0) }}</strong></td>
                        <td>{{ F::date($item['expiryDate'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No batch data found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
