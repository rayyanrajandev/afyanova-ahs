@php
    use App\Support\Documents\DocumentViewFormatter as F;

    $subtitle = implode(' | ', array_values(array_filter([
        $patient['fullName'] ?? null,
        ($patient['patientNumber'] ?? null) ? 'Patient '.$patient['patientNumber'] : null,
        ($record['encounterAt'] ?? null) ? 'Encounter '.F::dateTime($record['encounterAt']) : null,
    ])));
    $referralDestination = implode(' | ', array_values(array_filter([
        $appointmentReferral['targetDepartment'] ?? null,
        $appointmentReferral['targetFacilityName'] ?? null,
    ])));
    $sourceAdmission = $appointment['sourceAdmission'] ?? null;
    $recordType = strtolower(trim((string) ($record['recordType'] ?? '')));
    $narrativeHeadingMap = [
        'consultation_note' => [
            'title' => 'Consultation Narrative',
            'subtitle' => 'Current clinical story, examination, impression, and plan for this visit.',
        ],
        'admission_note' => [
            'title' => 'Admission Narrative',
            'subtitle' => 'Opening inpatient history, findings, admitting impression, and initial ward plan.',
        ],
        'progress_note' => [
            'title' => 'Progress Narrative',
            'subtitle' => 'Interval change, current findings, updated assessment, and ongoing care plan.',
        ],
        'discharge_note' => [
            'title' => 'Discharge Narrative',
            'subtitle' => 'Completed care, discharge condition, medicines, and follow-up instructions.',
        ],
        'referral_note' => [
            'title' => 'Referral Handoff',
            'subtitle' => 'Transfer reason, current clinical summary, receiving context, and next actions.',
        ],
        'nursing_note' => [
            'title' => 'Nursing Narrative',
            'subtitle' => 'Bedside observations, nursing assessment, interventions, and handoff context.',
        ],
        'procedure_note' => [
            'title' => 'Procedure Narrative',
            'subtitle' => 'Procedure indication, key findings, intra-procedure summary, and the immediate post-procedure plan.',
        ],
    ];
    $narrativeHeading = $narrativeHeadingMap[$recordType] ?? [
        'title' => 'Clinical Narrative',
        'subtitle' => 'Documented narrative sections',
    ];
    $noteSectionTitleMap = $recordType === 'procedure_note'
        ? [
            'subjective' => 'Indication',
            'objective' => 'Procedure Details',
            'assessment' => 'Outcome',
            'plan' => 'Recovery Plan',
        ]
        : [
            'subjective' => 'Subjective',
            'objective' => 'Objective',
            'assessment' => 'Assessment',
            'plan' => 'Plan',
        ];
    $noteSections = [
        $noteSectionTitleMap['subjective'] => F::textBlocks($record['subjective'] ?? null),
        $noteSectionTitleMap['objective'] => F::textBlocks($record['objective'] ?? null),
        $noteSectionTitleMap['assessment'] => F::textBlocks($record['assessment'] ?? null),
        $noteSectionTitleMap['plan'] => F::textBlocks($record['plan'] ?? null),
    ];
    $resourceSections = [
        'Laboratory' => $encounterResources['laboratory'] ?? [],
        'Pharmacy' => $encounterResources['pharmacy'] ?? [],
        'Radiology' => $encounterResources['radiology'] ?? [],
        'Theatre' => $encounterResources['theatre'] ?? [],
    ];
@endphp

<x-documents.pdf-layout
    :branding="$documentBranding"
    eyebrow="Clinical Record"
    :title="F::enum($record['recordType'] ?? 'clinical_note')"
    :subtitle="$subtitle !== '' ? $subtitle : 'Clinical record, signoff, and encounter context'"
    :document-number="$record['recordNumber'] ?? $record['id']"
    :status-label="F::enum($record['status'] ?? 'draft')"
    :generated-at="F::dateTime($generatedAt)"
>
    <div class="section">
        <p class="section-title">Clinical Context</p>
        <p class="section-subtitle">Patient, encounter, and document control</p>
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
                        <p class="card-title">Document control</p>
                        <table class="kv">
                            <tr><td class="k">Encounter At</td><td class="v">{{ F::dateTime($record['encounterAt'] ?? null) }}</td></tr>
                            <tr><td class="k">Appointment</td><td class="v">{{ $appointment['appointmentNumber'] ?? 'N/A' }}</td></tr>
                            @if(!empty($sourceAdmission) || !empty($appointment['sourceAdmissionId']))
                                <tr>
                                    <td class="k">Source Admission</td>
                                    <td class="v">
                                        {{ $sourceAdmission['admissionNumber'] ?? (!empty($appointment['sourceAdmissionId']) ? 'Admission '.substr((string) $appointment['sourceAdmissionId'], 0, 8) : 'N/A') }}
                                    </td>
                                </tr>
                                @if(!empty($sourceAdmission['status']))
                                    <tr><td class="k">Source Admission Status</td><td class="v">{{ F::enum($sourceAdmission['status']) }}</td></tr>
                                @endif
                                @if(!empty($sourceAdmission['dischargedAt']))
                                    <tr><td class="k">Discharged At</td><td class="v">{{ F::dateTime($sourceAdmission['dischargedAt']) }}</td></tr>
                                @endif
                                @if(!empty($sourceAdmission['dischargeDestination']))
                                    <tr><td class="k">Discharge Destination</td><td class="v">{{ $sourceAdmission['dischargeDestination'] }}</td></tr>
                                @endif
                                @if(!empty($sourceAdmission['followUpPlan']))
                                    <tr><td class="k">Follow-up Plan</td><td class="v">{{ $sourceAdmission['followUpPlan'] }}</td></tr>
                                @endif
                            @endif
                            <tr><td class="k">Referral</td><td class="v">{{ $appointmentReferral['referralNumber'] ?? 'N/A' }}</td></tr>
                            @if(!empty($appointmentReferral['status']))
                                <tr><td class="k">Referral Status</td><td class="v">{{ F::enum($appointmentReferral['status']) }}</td></tr>
                            @endif
                            @if(!empty($appointmentReferral['priority']))
                                <tr><td class="k">Referral Priority</td><td class="v">{{ F::enum($appointmentReferral['priority']) }}</td></tr>
                            @endif
                            @if(!empty($appointmentReferral['referralType']))
                                <tr><td class="k">Referral Type</td><td class="v">{{ F::enum($appointmentReferral['referralType']) }}</td></tr>
                            @endif
                            @if($referralDestination !== '')
                                <tr><td class="k">Receiving Team</td><td class="v">{{ $referralDestination }}</td></tr>
                            @endif
                            @if(!empty($appointmentReferral['requestedAt']))
                                <tr><td class="k">Referral Requested</td><td class="v">{{ F::dateTime($appointmentReferral['requestedAt']) }}</td></tr>
                            @endif
                            @if(!empty($appointmentReferral['acceptedAt']))
                                <tr><td class="k">Referral Accepted</td><td class="v">{{ F::dateTime($appointmentReferral['acceptedAt']) }}</td></tr>
                            @endif
                            @if(!empty($appointmentReferral['handedOffAt']))
                                <tr><td class="k">Referral Handed Off</td><td class="v">{{ F::dateTime($appointmentReferral['handedOffAt']) }}</td></tr>
                            @endif
                            @if(!empty($appointmentReferral['completedAt']))
                                <tr><td class="k">Referral Completed</td><td class="v">{{ F::dateTime($appointmentReferral['completedAt']) }}</td></tr>
                            @endif
                            <tr><td class="k">Procedure</td><td class="v">{{ $theatreProcedure['procedureNumber'] ?? ($theatreProcedure['procedureName'] ?? 'N/A') }}</td></tr>
                            @if(!empty($theatreProcedure['status']))
                                <tr><td class="k">Procedure Status</td><td class="v">{{ F::enum($theatreProcedure['status']) }}</td></tr>
                            @endif
                            @if(!empty($theatreProcedure['scheduledAt']))
                                <tr><td class="k">Procedure Schedule</td><td class="v">{{ F::dateTime($theatreProcedure['scheduledAt']) }}</td></tr>
                            @endif
                            @if(!empty($theatreProcedure['theatreRoomName']))
                                <tr><td class="k">Theatre Room</td><td class="v">{{ $theatreProcedure['theatreRoomName'] }}</td></tr>
                            @endif
                            <tr><td class="k">Admission</td><td class="v">{{ $admission['admissionNumber'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Author</td><td class="v">{{ $author['name'] ?? 'Not recorded' }}</td></tr>
                            <tr><td class="k">Signer</td><td class="v">{{ $signer['name'] ?? 'Not signed' }}</td></tr>
                            <tr><td class="k">Signed At</td><td class="v">{{ F::dateTime($record['signedAt'] ?? null) }}</td></tr>
                            <tr><td class="k">Diagnosis</td><td class="v">{{ $diagnosis ? (($diagnosis['code'] ?? 'N/A').' '.($diagnosis['name'] ?? '')) : ($record['diagnosisCode'] ?? 'N/A') }}</td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <p class="section-title">{{ $narrativeHeading['title'] }}</p>
        <p class="section-subtitle">{{ $narrativeHeading['subtitle'] }}</p>
        <table class="grid-table">
            @foreach($noteSections as $sectionTitle => $blocks)
                <tr>
                    <td>
                        <div class="card">
                            <p class="card-title">{{ $sectionTitle }}</p>
                            @if($blocks !== [])
                                @foreach($blocks as $block)
                                    <div style="margin-top: 6px;">{{ $block }}</div>
                                @endforeach
                            @else
                                <div class="muted">No {{ strtolower($sectionTitle) }} content was recorded.</div>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="section">
        <p class="section-title">Encounter-linked Care</p>
        <p class="section-subtitle">Orders and procedures referenced by this note</p>
        @foreach($resourceSections as $sectionTitle => $items)
            <div class="card" style="margin-top: 10px;">
                <p class="card-title">{{ $sectionTitle }}</p>
                @if($items !== [])
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
                            @foreach($items as $item)
                                <tr>
                                    <td>{{ $item['orderNumber'] ?? ($item['procedureNumber'] ?? 'N/A') }}</td>
                                    <td>{{ $item['testName'] ?? ($item['medicationName'] ?? ($item['studyDescription'] ?? ($item['procedureName'] ?? ($item['procedureType'] ?? 'N/A')))) }}</td>
                                    <td>{{ F::enum($item['status'] ?? null) }}</td>
                                    <td>{{ $item['resultSummary'] ?? ($item['dosageInstruction'] ?? ($item['reportSummary'] ?? ($item['theatreRoomName'] ?? ($item['notes'] ?? 'N/A')))) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="muted">No {{ strtolower($sectionTitle) }} items were linked to this encounter.</div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="section">
        <p class="section-title">Signoff Trail</p>
        <p class="section-subtitle">Attestations and versioning</p>
        <table class="two-col">
            <tr>
                <td>
                    <div class="card">
                        <p class="card-title">Version summary</p>
                        <table class="kv">
                            <tr><td class="k">Snapshots</td><td class="v">{{ $versionSummary['count'] ?? 0 }}</td></tr>
                            <tr><td class="k">Latest Version</td><td class="v">{{ isset($versionSummary['latestVersionNumber']) ? 'v'.$versionSummary['latestVersionNumber'] : 'N/A' }}</td></tr>
                            <tr><td class="k">Updated At</td><td class="v">{{ F::dateTime($versionSummary['latestVersionCreatedAt'] ?? null) }}</td></tr>
                            <tr><td class="k">Updated By</td><td class="v">{{ $versionSummary['latestVersionCreatedBy']['name'] ?? 'N/A' }}</td></tr>
                            <tr><td class="k">Changed Fields</td><td class="v">{{ $versionSummary['latestChangedFieldCount'] ?? 0 }}</td></tr>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="card">
                        <p class="card-title">Attestations</p>
                        @if(!empty($attestations))
                            @foreach($attestations as $attestation)
                                <div style="margin-bottom: 10px;">
                                    <strong>{{ $attestation['attestedBy']['name'] ?? 'Clinical user' }}</strong>
                                    <div class="small muted">{{ F::dateTime($attestation['attestedAt'] ?? null) }}</div>
                                    <div>{{ $attestation['attestationNote'] ?? 'No attestation note recorded.' }}</div>
                                </div>
                            @endforeach
                        @else
                            <div class="muted">No signer attestations have been recorded for this note yet.</div>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>
</x-documents.pdf-layout>
