@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $subtitle = implode(' | ', array_values(array_filter([
        $claim['payerName'] ?? null,
        ($invoice['invoiceNumber'] ?? null) ? 'Invoice '.$invoice['invoiceNumber'] : null,
        ($patient['patientNumber'] ?? null) ? 'Patient '.$patient['patientNumber'] : null,
    ])));
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Claims and Insurance"
    title="Claim Dossier"
    :subtitle="$subtitle !== '' ? $subtitle : 'Claim lifecycle, adjudication, settlement, and recovery context'"
    :document-number="$claim['claimNumber'] ?? $claim['id']"
    :status-label="trim(F::enum($claim['status'] ?? null).' / '.F::enum($claim['reconciliationStatus'] ?? null), ' /')"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Case Context</p>
        <p class="section-subtitle">Patient, encounter, and payer handoff</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">{{ $patient['fullName'] ?? 'Unknown patient' }}</p>
                        <table class="kv">
                            <tr><td class="k">Patient No.</td><td class="v">{{ $patient['patientNumber'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Gender</td><td class="v">{{ F::enum($patient['gender'] ?? null) }}</td></tr>
                            <tr><td class="k">Phone</td><td class="v">{{ $patient['phone'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Appointment</td><td class="v">{{ $appointment['appointmentNumber'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Admission</td><td class="v">{{ $admission['admissionNumber'] ?? 'N/A' }}</td></tr>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">{{ $claim['payerName'] ?? 'Unassigned payer' }}</p>
                        <table class="kv">
                            <tr><td class="k">Payer Type</td><td class="v">{{ F::enum($claim['payerType'] ?? null) }}</td></tr>
                            <tr><td class="k">Payer Ref.</td><td class="v">{{ $claim['payerReference'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Invoice Link</td><td class="v">{{ $invoice['invoiceNumber'] ?? ($claim['invoiceId'] ?? 'N/A') }}</td></tr>
                            <tr><td class="k">Submitted</td><td class="v">{{ F::dateTime($claim['submittedAt'] ?? null) }}</td></tr>
                            <tr><td class="k">Follow-up Owner</td><td class="v">{{ $followUpOwner['name'] ?? 'Not assigned' }}</td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Settlement Position</p>
        <p class="section-subtitle">Adjudication and recovery snapshot</p>
        <table class="stats-grid">
            <tr>
                <td><div class="card"><p class="card-title">Claimed</p><div>{{ F::money($claim['claimAmount'] ?? null, $claim['currencyCode'] ?? null) }}</div></div></td>
                <td><div class="card"><p class="card-title">Approved</p><div>{{ F::money($claim['approvedAmount'] ?? null, $claim['currencyCode'] ?? null) }}</div></div></td>
            </tr>
            <tr>
                <td><div class="card"><p class="card-title">Rejected</p><div>{{ F::money($claim['rejectedAmount'] ?? null, $claim['currencyCode'] ?? null) }}</div></div></td>
                <td><div class="card"><p class="card-title">Settled</p><div>{{ F::money($claim['settledAmount'] ?? null, $claim['currencyCode'] ?? null) }}</div></div></td>
            </tr>
            <tr>
                <td><div class="card"><p class="card-title">Shortfall</p><div>{{ F::money($claim['reconciliationShortfallAmount'] ?? null, $claim['currencyCode'] ?? null) }}</div></div></td>
                <td><div class="card"><p class="card-title">Invoice Balance</p><div>{{ F::money($invoice['balanceAmount'] ?? null, $invoice['currencyCode'] ?? ($claim['currencyCode'] ?? null)) }}</div></div></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Workflow Notes</p>
        <p class="section-subtitle">Operational commentary and follow-up</p>
        <table class="grid-table">
            <tr>
                <td><div class="card"><p class="card-title">Decision rationale</p><div>{{ $claim['decisionReason'] ?? ($claim['statusReason'] ?? 'No decision rationale recorded.') }}</div></div></td>
                <td><div class="card"><p class="card-title">Reconciliation notes</p><div>{{ $claim['reconciliationNotes'] ?? 'No reconciliation notes recorded.' }}</div></div></td>
            </tr>
            <tr>
                <td><div class="card"><p class="card-title">Follow-up note</p><div>{{ $claim['reconciliationFollowUpNote'] ?? 'No follow-up note recorded.' }}</div></div></td>
                <td><div class="card"><p class="card-title">General notes</p><div>{{ $claim['notes'] ?? ($invoice['statusReason'] ?? 'No general operational notes recorded.') }}</div></div></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Timeline</p>
        <p class="section-subtitle">Case milestones</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Milestone</th>
                    <th>When</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Claim created</td><td>{{ F::dateTime($claim['createdAt'] ?? null) }}</td><td>Case was opened from billing context and queued for review.</td></tr>
                <tr><td>Submitted to payer</td><td>{{ F::dateTime($claim['submittedAt'] ?? null) }}</td><td>Submission moved the claim into payer-facing processing.</td></tr>
                <tr><td>Payer decision recorded</td><td>{{ F::dateTime($claim['adjudicatedAt'] ?? null) }}</td><td>Approval, denial, or partial decision was captured.</td></tr>
                <tr><td>Settlement reconciled</td><td>{{ F::dateTime($claim['settledAt'] ?? null) }}</td><td>Recovered value was posted against the claim settlement workflow.</td></tr>
                <tr><td>Follow-up checkpoint</td><td>{{ F::dateTime($claim['reconciliationFollowUpDueAt'] ?? null) }}</td><td>{{ ($claim['reconciliationExceptionStatus'] ?? null) === 'open' ? 'Recovery exception remains active and needs follow-up.' : 'No open exception currently blocks the claim.' }}</td></tr>
            </tbody>
        </table>
    </div>
</x-documents.pdf-layout>
