@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All dispensed medicines';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report"
    title="Dispensed Medicines"
    :subtitle="$filterDescription"
    document-number="DSP-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Dispensing Log</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Patient ID</th>
                    <th>Item</th>
                    <th>Qty Dispensed</th>
                    <th>Batch</th>
                    <th>Total Cost</th>
                    <th>Dispensed At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['patientId'] ?? '—' }}</td>
                        <td>{{ $item['itemName'] ?? $item['itemCode'] ?? '—' }}</td>
                        <td>{{ number_format($item['quantityDispensed'] ?? 0) }}</td>
                        <td>{{ $item['batchNumber'] ?? '—' }}</td>
                        <td>{{ $item['totalCost'] ? number_format($item['totalCost'], 2) : '—' }}</td>
                        <td>{{ F::dateTime($item['dispensedAt'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No dispensed medicines found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
