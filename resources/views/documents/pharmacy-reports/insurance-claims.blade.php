@php
    use App\Support\Documents\DocumentViewFormatter as F;
    $items = $request['data'] ?? [];
    $summary = $request['summary'] ?? [];
    $generatedAt = $request['generatedAt'] ?? now();
    $filterDescription = $request['filterDescription'] ?? 'All insurance claims';
@endphp
<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Pharmacy Report — Compliance"
    title="Insurance Claims"
    :subtitle="$filterDescription"
    document-number="CLM-{{ $generatedAt->format('Ymd-His') }}"
    :generated-at="F::dateTime($generatedAt)"
>
    @if(!empty($summary))
        <div class="section">
            <p class="section-title">Claims Summary</p>
            <table class="stats-grid">
                <tr>
                    <td><div class="card"><strong>{{ $summary['totalClaims'] ?? 0 }}</strong> Total</div></td>
                    <td><div class="card"><strong>{{ $summary['pendingClaims'] ?? 0 }}</strong> Pending</div></td>
                    <td><div class="card"><strong>{{ $summary['approvedClaims'] ?? 0 }}</strong> Approved</div></td>
                    <td><div class="card"><span class="badge blocked">{{ $summary['rejectedClaims'] ?? 0 }}</span> Rejected</div></td>
                </tr>
                <tr>
                    <td colspan="2"><div class="card">Approved Amount: <strong>{{ number_format($summary['totalApprovedAmount'] ?? 0, 2) }}</strong></div></td>
                    <td colspan="2"><div class="card">Rejected Amount: <strong>{{ number_format($summary['totalRejectedAmount'] ?? 0, 2) }}</strong></div></td>
                </tr>
            </table>
        </div>
    @endif
    <div class="section">
        <p class="section-title">Claim Details</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Patient ID</th>
                    <th>Item</th>
                    <th>Payer</th>
                    <th>Status</th>
                    <th>Approved</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item['patientId'] ?? '—' }}</td>
                        <td>{{ $item['itemName'] ?? '—' }}</td>
                        <td>{{ $item['payerName'] ?? '—' }}</td>
                        <td><span class="badge {{ ($item['claimStatus'] ?? '') === 'rejected' ? 'blocked' : (($item['claimStatus'] ?? '') === 'approved' ? 'good' : 'warn') }}">{{ $item['claimStatus'] ?? '—' }}</span></td>
                        <td>{{ $item['approvedAmount'] ? number_format($item['approvedAmount'], 2) : '—' }}</td>
                        <td>{{ F::dateTime($item['submittedAt'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No insurance claims found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
