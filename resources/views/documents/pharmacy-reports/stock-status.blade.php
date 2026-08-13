@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All items';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report"
    title="Stock Status"
    :subtitle="$filterDescription"
    document-number="STK-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Stock Status Overview</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Item Name</th>
                    <th>Current Stock</th>
                    <th>Available</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['itemCode'] ?? '—' }}</td>
                        <td>{{ $item['itemName'] ?? '—' }}</td>
                        <td>{{ number_format($item['currentStock'] ?? 0) }}</td>
                        <td>{{ number_format($item['availableStock'] ?? 0) }}</td>
                        <td>{{ number_format($item['reorderLevel'] ?? 0) }}</td>
                        <td><span class="badge {{ ($item['stockState'] ?? '') === 'low_stock' ? 'warn' : (($item['stockState'] ?? '') === 'out_of_stock' ? 'blocked' : 'good') }}">{{ str_replace('_', ' ', $item['stockState'] ?? 'healthy') }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No stock status data available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
