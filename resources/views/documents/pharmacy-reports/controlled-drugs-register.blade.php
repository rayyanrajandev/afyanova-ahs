@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All controlled drug transactions';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report — Compliance"
    title="Controlled Drugs Register"
    :subtitle="$filterDescription"
    document-number="CDR-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Register of Controlled Substances</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Patient ID</th>
                    <th>Medicine</th>
                    <th>Schedule</th>
                    <th>Batch</th>
                    <th>Qty</th>
                    <th>Prescriber</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ F::dateTime($item['dispensedAt'] ?? null) }}</td>
                        <td>{{ $item['patientId'] ?? '—' }}</td>
                        <td>{{ $item['medicineName'] ?? '—' }} {{ $item['strength'] ?? '' }}</td>
                        <td><span class="badge warn">{{ $item['schedule'] ?? 'N/A' }}</span></td>
                        <td>{{ $item['batchNumber'] ?? '—' }}</td>
                        <td>{{ number_format($item['quantityDispensed'] ?? 0) }} {{ $item['unit'] ?? '' }}</td>
                        <td>{{ $item['prescriberUserId'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No controlled drug dispenses recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
