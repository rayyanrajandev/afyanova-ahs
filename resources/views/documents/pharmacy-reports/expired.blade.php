@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $summary = $request['summary'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All expired batches';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report"
    title="Expired Items"
    :subtitle="$filterDescription"
    document-number="EXPD-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    @if(!empty($summary))
        <div class="section">
            <p class="section-title">Loss Summary</p>
            <table class="stats-grid">
                <tr>
                    <td><div class="card"><span class="meta-value">{{ $summary['totalCount'] ?? 0 }}</span> <span class="muted">expired batches</span></div></td>
                    <td><div class="card"><span class="meta-value">{{ number_format($summary['totalValue'] ?? 0, 2) }}</span> <span class="muted">estimated loss value</span></div></td>
                </tr>
            </table>
        </div>
    @endif
    <div class="section">
        <p class="section-title">Expired Stock Details</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Batch</th>
                    <th>Qty</th>
                    <th>Expired On</th>
                    <th>Days Since</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['itemName'] ?? $item['itemCode'] ?? '—' }}</td>
                        <td>{{ $item['batchNumber'] ?? '—' }}</td>
                        <td>{{ number_format($item['quantity'] ?? 0) }}</td>
                        <td>{{ F::date($item['expiryDate'] ?? null) }}</td>
                        <td><span class="badge blocked">{{ $item['daysSinceExpiry'] ?? '—' }} days</span></td>
                        <td>{{ $item['estimatedValue'] ? number_format($item['estimatedValue'], 2) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No expired items.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
