@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All out-of-stock items';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report"
    title="Out of Stock Items"
    :subtitle="$filterDescription"
    document-number="OOS-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Items Currently Unavailable</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Item Name</th>
                    <th>Days Out of Stock</th>
                    <th>Last Stocked</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['itemCode'] ?? '—' }}</td>
                        <td>{{ $item['itemName'] ?? '—' }}</td>
                        <td><span class="badge blocked">{{ $item['daysOutOfStock'] ?? 'N/A' }} days</span></td>
                        <td>{{ $item['lastStockedAt'] ? F::dateTime($item['lastStockedAt']) : 'Never' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No out-of-stock items.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
