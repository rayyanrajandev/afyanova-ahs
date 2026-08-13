@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'Medicine consumption';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report — Analytics"
    title="Medicine Consumption"
    :subtitle="$filterDescription"
    document-number="CONS-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Consumption Data</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Total Consumed</th>
                    <th>Movements</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['period'] ?? '—' }}</td>
                        <td>{{ number_format($item['totalConsumed'] ?? 0) }}</td>
                        <td>{{ $item['movementCount'] ?? 0 }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No consumption data available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
