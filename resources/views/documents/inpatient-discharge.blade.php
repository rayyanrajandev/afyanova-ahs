@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $subtitle = implode(' | ', array_values(array_filter([
        $patient['fullName'] ?? null,
        ($patient['patientNumber'] ?? null) ? 'Patient '.$patient['patientNumber'] : null,
        ($admission['admissionNumber'] ?? null) ? 'Admission '.$admission['admissionNumber'] : null,
    ])));
    $readinessItems = [
        'Clinical Summary' => $checklist['clinicalSummaryCompleted'] ?? false,
        'Medication Reconciliation' => $checklist['medicationReconciliationCompleted'] ?? false,
        'Follow-up Plan' => $checklist['followUpPlanCompleted'] ?? false,
        'Patient Education' => $checklist['patientEducationCompleted'] ?? false,
        'Transport' => $checklist['transportArranged'] ?? false,
        'Billing Clearance' => $checklist['billingCleared'] ?? false,
        'Documentation' => $checklist['documentationCompleted'] ?? false,
    ];
    $followUpSections = [
        'Laboratory' => $followUpRail['modules']['laboratory'] ?? ['items' => [], 'statusCounts' => [], 'followUpCount' => 0],
        'Pharmacy' => $followUpRail['modules']['pharmacy'] ?? ['items' => [], 'statusCounts' => [], 'followUpCount' => 0],
        'Radiology' => $followUpRail['modules']['radiology'] ?? ['items' => [], 'statusCounts' => [], 'followUpCount' => 0],
        'Billing' => $followUpRail['modules']['billing'] ?? ['items' => [], 'statusCounts' => [], 'followUpCount' => 0],
    ];
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Clinical Discharge"
    title="Discharge Summary"
    :subtitle="$subtitle !== '' ? $subtitle : 'Ward discharge readiness, handoff, and follow-up summary'"
    :document-number="$admission['admissionNumber'] ?? $checklist['id']"
    :status-label="F::enum($checklist['status'] ?? 'draft')"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Discharge Context</p>
        <p class="section-subtitle">Patient, admission, and workflow review</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">{{ $patient['fullName'] ?? 'Unknown patient' }}</p>
                        <table class="kv">
                            <tr><td class="k">Patient No.</td><td class="v">{{ $patient['patientNumber'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Gender</td><td class="v">{{ F::enum($patient['gender'] ?? null) }}</td></tr>
                            <tr><td class="k">Date of Birth</td><td class="v">{{ F::date($patient['dateOfBirth'] ?? null) }}</td></tr>
                            <tr><td class="k">Phone</td><td class="v">{{ $patient['phone'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Email</td><td class="v">{{ $patient['email'] ?? 'N/A' }}</td></tr>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">Admission and review</p>
                        <table class="kv">
                            <tr><td class="k">Admission</td><td class="v">{{ $admission['admissionNumber'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Ward / Bed</td><td class="v">{{ ($admission['ward'] ?? 'N/A').' / '.($admission['bed'] ?? 'N/A') }}</td></tr>
                            <tr><td class="k">Destination</td><td class="v">{{ $admission['dischargeDestination'] ?? 'Not recorded' }}</td></tr>
                            <tr><td class="k">Reviewed By</td><td class="v">{{ $reviewer['name'] ?? 'Not recorded' }}</td></tr>
                            <tr><td class="k">Reviewed At</td><td class="v">{{ F::dateTime($checklist['reviewedAt'] ?? null) }}</td></tr>
                            <tr><td class="k">Ready for Discharge</td><td class="v">{{ !empty($checklist['isReadyForDischarge']) ? 'Yes' : 'No' }}</td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Readiness Gates</p>
        <p class="section-subtitle">Core bedside discharge checklist</p>
        <table class="grid-table">
            @foreach($readinessItems as $label => $complete)
                <tr>
                    <td>
                        <div class="card">
                            <p class="card-title">{{ $label }}</p>
                            <span class="badge {{ $complete ? 'good' : 'warn' }}">{{ $complete ? 'Complete' : 'Pending' }}</span>
                        </div>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="section">
        <p class="section-title">Disposition</p>
        <p class="section-subtitle">Notes, blockers, and plan</p>
        <table class="two-col">
            <tr>
                <td><div class="card"><p class="card-title">Workflow note</p><div>{{ $checklist['statusReason'] ?? 'No workflow note or blocker comment was recorded.' }}</div></div></td>
                <td><div class="card"><p class="card-title">Checklist notes</p><div>{{ $checklist['notes'] ?? 'No discharge readiness notes were recorded for this admission.' }}</div></div></td>
            </tr>
            <tr>
                <td><div class="card"><p class="card-title">Admission reason</p><div>{{ $admission['admissionReason'] ?? 'Not recorded' }}</div></div></td>
                <td><div class="card"><p class="card-title">Follow-up plan</p><div>{{ $admission['followUpPlan'] ?? 'No admission follow-up plan has been documented yet.' }}</div></div></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Ward Continuity</p>
        <p class="section-subtitle">Recent round notes and care plans</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">Recent round notes</p>
                        @if(!empty($roundNotes))
                            @foreach($roundNotes as $roundNote)
                                <div style="margin-bottom: 10px;">
                                    <strong>{{ !empty($roundNote['shiftLabel']) ? F::enum($roundNote['shiftLabel']).' shift' : 'Ward round note' }}</strong>
                                    <div class="small muted">{{ $roundNote['author']['name'] ?? 'Unknown author' }} | {{ F::dateTime($roundNote['roundedAt'] ?? null) }}</div>
                                    <div>{{ $roundNote['roundNote'] ?? 'No round note narrative was captured.' }}</div>
                                    @if(!empty($roundNote['handoffNotes']))
                                        <div class="small muted">Handoff: {{ $roundNote['handoffNotes'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            <div class="muted">No ward round notes were recorded for this admission.</div>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">Care plans</p>
                        @if(!empty($carePlans))
                            @foreach($carePlans as $carePlan)
                                <div style="margin-bottom: 10px;">
                                    <strong>{{ $carePlan['title'] ?? 'Care plan' }}</strong>
                                    <div class="small muted">{{ $carePlan['carePlanNumber'] ?? 'Plan number pending' }} | {{ $carePlan['author']['name'] ?? 'Unknown author' }}</div>
                                    <div>{{ $carePlan['planText'] ?? 'No care-plan narrative was recorded.' }}</div>
                                    @if(!empty($carePlan['statusReason']))
                                        <div class="small muted">Workflow note: {{ $carePlan['statusReason'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            <div class="muted">No inpatient care plans were recorded for this admission.</div>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">Cross-module Follow-up</p>
        <p class="section-subtitle">Downstream work that still matters after discharge</p>
        @foreach($followUpSections as $sectionTitle => $section)
            <div class="card" style="margin-top: 10px;">
                <p class="card-title">{{ $sectionTitle }} <span class="small muted">({{ $section['followUpCount'] ?? 0 }} open)</span></p>
                <div class="small muted">{{ F::statusCounts($section['statusCounts'] ?? []) }}</div>
                @if(!empty($section['items']))
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($section['items'] as $item)
                                <tr>
                                    <td>{{ $item['number'] ?? 'N/A' }}</td>
                                    <td>{{ $item['title'] ?? 'Work item' }}</td>
                                    <td>{{ F::enum($item['status'] ?? null) }}</td>
                                    <td>{{ $item['detail'] ?? 'No detail recorded.' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="muted" style="margin-top: 8px;">No open {{ strtolower($sectionTitle) }} follow-up items were found for this admission.</div>
                @endif
            </div>
        @endforeach
    </div>
</x-documents.pdf-layout>
