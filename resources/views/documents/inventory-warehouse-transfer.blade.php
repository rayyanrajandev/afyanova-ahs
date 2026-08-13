@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $isPickSlip = ($documentType ?? 'pick_slip') === 'pick_slip';
    $transferNumber = $transfer['transfer_number'] ?? 'Pending transfer';
    $dispatchNoteNumber = $transfer['dispatchNoteNumber'] ?? ($transfer['dispatch_note_number'] ?? null);
    $documentNumber = $isPickSlip ? $transferNumber : ($dispatchNoteNumber ?: $transferNumber);
    $subtitle = $transfer['routeLabel']
        ?? implode(' | ', array_values(array_filter([
            $transfer['sourceWarehouseName'] ?? null,
            $transfer['destinationWarehouseName'] ?? null,
        ])));
    $lines = is_array($transfer['lines'] ?? null) ? $transfer['lines'] : [];
    $overviewRows = [
        ['Priority', F::enum($transfer['priority'] ?? 'normal')],
        ['Hold state', F::enum($transfer['reservationSummary']['state'] ?? 'none')],
        ['Requested quantity', $transfer['pickingSummary']['requestedQuantity'] ?? '0'],
        ['Packed quantity', $transfer['pickingSummary']['packedQuantity'] ?? '0'],
        ['Dispatched quantity', $transfer['pickingSummary']['dispatchedQuantity'] ?? '0'],
        ['Received quantity', $transfer['pickingSummary']['receivedQuantity'] ?? '0'],
    ];
    $workflowRows = [
        ['Requested by', $requestedBy['name'] ?? 'Not recorded'],
        ['Approved by', $approvedBy['name'] ?? 'Not recorded'],
        ['Packed by', $packedBy['name'] ?? 'Not recorded'],
        ['Dispatched by', $dispatchedBy['name'] ?? 'Not recorded'],
        ['Received by', $receivedBy['name'] ?? 'Not recorded'],
    ];
    $timelineRows = [
        ['Approved at', F::dateTime($transfer['approved_at'] ?? null)],
        ['Packed at', F::dateTime($transfer['packed_at'] ?? null)],
        ['Dispatched at', F::dateTime($transfer['dispatched_at'] ?? null)],
        ['Received at', F::dateTime($transfer['received_at'] ?? null)],
    ];
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    :eyebrow="$isPickSlip ? 'Warehouse Pick Slip' : 'Warehouse Dispatch Note'"
    :title="$isPickSlip ? 'Pick Slip' : 'Dispatch Note'"
    :subtitle="$subtitle !== '' ? $subtitle : 'Warehouse execution document'"
    :document-number="$documentNumber"
    :status-label="F::enum($transfer['status'] ?? 'draft')"
    :generated-at="F::dateTime($generatedAt ?? null)"
>
    <div class="section">
        <p class="section-title">Transfer Summary</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">Operational context</p>
                        <table class="kv">
                            @foreach ($overviewRows as [$label, $value])
                                <tr><td class="k">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">Workflow owners</p>
                        <table class="kv">
                            @foreach ($workflowRows as [$label, $value])
                                <tr><td class="k">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Timeline</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Step</th>
                    <th>Recorded At</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($timelineRows as [$label, $value])
                    <tr>
                        <td>{{ $label }}</td>
                        <td>{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <p class="section-title">{{ $isPickSlip ? 'Pick Lines' : 'Dispatch Lines' }}</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Batch</th>
                    <th>Requested</th>
                    @if($isPickSlip)
                        <th>Held</th>
                        <th>Packed</th>
                    @else
                        <th>Dispatched</th>
                        <th>Received</th>
                    @endif
                    <th>Unit</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lines as $line)
                    <tr>
                        <td>
                            <div><strong>{{ $line['itemName'] ?? 'Transfer item' }}</strong></div>
                            @if(!empty($line['itemCode']))
                                <div class="muted small">{{ $line['itemCode'] }}</div>
                            @endif
                            @if(!empty($line['notes']))
                                <div class="muted small">{{ $line['notes'] }}</div>
                            @endif
                        </td>
                        <td>{{ $line['batchNumber'] ?? 'Untracked' }}</td>
                        <td>{{ $line['requested_quantity'] ?? '0' }}</td>
                        @if($isPickSlip)
                            <td>{{ $line['reservedQuantity'] ?? '0' }}</td>
                            <td>{{ $line['packedQuantity'] ?? '0' }}</td>
                        @else
                            <td>{{ $line['dispatched_quantity'] ?? '0' }}</td>
                            <td>{{ $line['received_quantity'] ?? '0' }}</td>
                        @endif
                        <td>{{ $line['unit'] ?? 'units' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isPickSlip ? 6 : 6 }}">No transfer lines were recorded.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(!empty($transfer['notes']) || !empty($transfer['packNotes']) || !empty($transfer['pack_notes']) || !empty($transfer['receiving_notes']))
        <div class="section">
            <p class="section-title">Notes</p>
            <table class="table">
                <tbody>
                    @if(!empty($transfer['notes']))
                        <tr><td><strong>Transfer note:</strong> {{ $transfer['notes'] }}</td></tr>
                    @endif
                    @if(!empty($transfer['packNotes']) || !empty($transfer['pack_notes']))
                        <tr><td><strong>Pack note:</strong> {{ $transfer['packNotes'] ?? $transfer['pack_notes'] }}</td></tr>
                    @endif
                    @if(!empty($transfer['receiving_notes']))
                        <tr><td><strong>Receiving note:</strong> {{ $transfer['receiving_notes'] }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endif
</x-documents.pdf-layout>
