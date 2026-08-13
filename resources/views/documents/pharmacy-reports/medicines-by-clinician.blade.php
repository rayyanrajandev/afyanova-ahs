@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All clinicians';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report"
    title="Medicines by Clinician"
    :subtitle="$filterDescription"
    document-number="CLN-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Clinician Prescribing Summary</p>
        <table class="table">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Orders</th>
                    <th>Total Qty</th>
                    <th>Patients</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['userId'] ?? '—' }}</td>
                        <td>{{ $item['orderCount'] ?? 0 }}</td>
                        <td>{{ number_format($item['totalQuantity'] ?? 0) }}</td>
                        <td>{{ $item['patientCount'] ?? 0 }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No clinician prescribing data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
