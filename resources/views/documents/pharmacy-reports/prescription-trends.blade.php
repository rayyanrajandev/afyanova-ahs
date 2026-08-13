@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'Prescription trends';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report — Analytics"
    title="Prescription Trends"
    :subtitle="$filterDescription"
    document-number="TRD-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Orders Over Time</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Orders</th>
                    <th>Dispensed</th>
                    <th>Total Prescribed</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['period'] ?? '—' }}</td>
                        <td>{{ $item['orderCount'] ?? 0 }}</td>
                        <td>{{ $item['dispensedCount'] ?? 0 }}</td>
                        <td>{{ number_format($item['totalPrescribed'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No trend data available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
