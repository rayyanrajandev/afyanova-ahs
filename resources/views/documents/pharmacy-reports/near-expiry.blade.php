@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $summary = $request['summary'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All near-expiry batches';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report"
    title="Near-Expiry Items"
    :subtitle="$filterDescription"
    document-number="EXP-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    @if(!empty($summary))
        <div class="section">
            <p class="section-title">Summary</p>
            <table class="stats-grid">
                <tr>
                    <td><div class="card"><span class="badge blocked">{{ $summary['criticalCount'] ?? 0 }}</span> Critical (≤30 days)</div></td>
                    <td><div class="card"><span class="badge warn">{{ $summary['warningCount'] ?? 0 }}</span> Warning (≤90 days)</div></td>
                </tr>
            </table>
        </div>
    @endif
    <div class="section">
        <p class="section-title">Expiring Batches</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Batch</th>
                    <th>Stock</th>
                    <th>Expiry Date</th>
                    <th>Days Left</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['itemName'] ?? $item['itemCode'] ?? '—' }}</td>
                        <td>{{ $item['batchNumber'] ?? '—' }}</td>
                        <td>{{ number_format($item['quantity'] ?? 0) }}</td>
                        <td>{{ F::date($item['expiryDate'] ?? null) }}</td>
                        <td><span class="badge {{ ($item['urgency'] ?? '') === 'critical' ? 'blocked' : 'warn' }}">{{ $item['daysUntilExpiry'] ?? '—' }} days</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No near-expiry items.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
