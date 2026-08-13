@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All low stock items';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report"
    title="Low Stock Items"
    :subtitle="$filterDescription"
    document-number="LSTK-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Items Below Reorder Level</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Item Name</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Ratio</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['itemCode'] ?? '—' }}</td>
                        <td>{{ $item['itemName'] ?? '—' }}</td>
                        <td><strong>{{ number_format($item['currentStock'] ?? 0) }}</strong></td>
                        <td>{{ number_format($item['reorderLevel'] ?? 0) }}</td>
                        <td>{{ $item['stockRatio'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No low stock items.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
