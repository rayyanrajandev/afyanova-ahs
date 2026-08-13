<?php

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardCarePlanAuditLogModel;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardDischargeChecklistAuditLogModel;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardTaskAuditLogModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function inpatientWardApiGivePermissions(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }
}

function inpatientWardApiMakePatient(array $overrides = []): PatientModel
{
    return PatientModel::query()->create(array_merge([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Rehema',
        'middle_name' => null,
        'last_name' => 'Moshi',
        'gender' => 'female',
        'date_of_birth' => '1992-08-16',
        'phone' => '+255700004001',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ], $overrides));
}

function inpatientWardApiMakeAdmission(string $patientId, array $overrides = []): AdmissionModel
{
    return AdmissionModel::query()->create(array_merge([
        'admission_number' => 'ADM'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => null,
        'facility_id' => null,
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => 'Ward A',
        'bed' => 'A-12',
        'admitted_at' => now()->subHours(2)->toDateTimeString(),
        'discharged_at' => null,
        'admission_reason' => 'Inpatient monitoring',
        'notes' => null,
        'status' => 'admitted',
        'status_reason' => null,
    ], $overrides));
}

it('requires authentication for inpatient ward task list', function (): void {
    $this->getJson('/api/v1/inpatient-ward/tasks')
        ->assertUnauthorized();
});

it('forbids inpatient ward task list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/inpatient-ward/tasks')
        ->assertForbidden();
});

it('forbids discharge checklist creation without manage discharge checklist permission', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, ['inpatient.ward.read']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/discharge-checklists', [
            'admissionId' => $admission->id,
        ])
        ->assertForbidden();
});

it('enforces readiness requirement when creating discharge checklist as ready', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, ['inpatient.ward.manage-discharge-checklist']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/discharge-checklists', [
            'admissionId' => $admission->id,
            'status' => 'ready',
            'clinicalSummaryCompleted' => true,
            'medicationReconciliationCompleted' => false,
            'followUpPlanCompleted' => true,
            'patientEducationCompleted' => true,
            'transportArranged' => true,
            'billingCleared' => true,
            'documentationCompleted' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('enforces readiness requirement when updating discharge checklist status to ready', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, ['inpatient.ward.manage-discharge-checklist']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/discharge-checklists', [
            'admissionId' => $admission->id,
            'status' => 'draft',
            'clinicalSummaryCompleted' => true,
            'medicationReconciliationCompleted' => false,
            'followUpPlanCompleted' => true,
            'patientEducationCompleted' => true,
            'transportArranged' => true,
            'billingCleared' => true,
            'documentationCompleted' => true,
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/discharge-checklists/'.$created['id'].'/status', [
            'status' => 'ready',
            'reason' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('rejects making checklist not-ready while status is ready', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, ['inpatient.ward.manage-discharge-checklist']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/discharge-checklists', [
            'admissionId' => $admission->id,
            'status' => 'ready',
            'clinicalSummaryCompleted' => true,
            'medicationReconciliationCompleted' => true,
            'followUpPlanCompleted' => true,
            'patientEducationCompleted' => true,
            'transportArranged' => true,
            'billingCleared' => true,
            'documentationCompleted' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.isReadyForDischarge', true)
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/discharge-checklists/'.$created['id'], [
            'clinicalSummaryCompleted' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('returns discharge checklist counts including ready for discharge for the selected admission', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, ['inpatient.ward.read', 'inpatient.ward.manage-discharge-checklist']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);
    $otherPatient = inpatientWardApiMakePatient(['patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6))]);
    $otherAdmission = inpatientWardApiMakeAdmission($otherPatient->id, ['ward' => 'Ward C', 'bed' => 'C-01']);

    $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/discharge-checklists', [
            'admissionId' => $admission->id,
            'status' => 'ready',
            'clinicalSummaryCompleted' => true,
            'medicationReconciliationCompleted' => true,
            'followUpPlanCompleted' => true,
            'patientEducationCompleted' => true,
            'transportArranged' => true,
            'billingCleared' => true,
            'documentationCompleted' => true,
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/discharge-checklists', [
            'admissionId' => $otherAdmission->id,
            'status' => 'blocked',
            'statusReason' => 'Transport coordination delay',
            'clinicalSummaryCompleted' => true,
            'medicationReconciliationCompleted' => false,
            'followUpPlanCompleted' => true,
            'patientEducationCompleted' => false,
            'transportArranged' => false,
            'billingCleared' => true,
            'documentationCompleted' => false,
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/inpatient-ward/discharge-checklist-status-counts?admissionId='.$admission->id)
        ->assertOk()
        ->assertJsonPath('data.ready', 1)
        ->assertJsonPath('data.blocked', 0)
        ->assertJsonPath('data.readyForDischarge', 1)
        ->assertJsonPath('data.total', 1);
});

it('updates inpatient ward task assignment for ward ownership changes', function (): void {
    $user = User::factory()->create();
    $assignee = User::factory()->create();

    inpatientWardApiGivePermissions($user, [
        'inpatient.ward.create-task',
        'inpatient.ward.update-task-status',
    ]);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/tasks', [
            'admissionId' => $admission->id,
            'taskType' => 'vitals',
            'priority' => 'routine',
            'title' => 'Bedside reassignment test',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/tasks/'.$created['id'], [
            'assignedToUserId' => $assignee->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.assignedToUserId', $assignee->id);

    $audit = InpatientWardTaskAuditLogModel::query()
        ->where('inpatient_ward_task_id', $created['id'])
        ->where('action', 'inpatient-ward-task.updated')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit?->changes['assigned_to_user_id'] ?? [])->toMatchArray([
        'before' => null,
        'after' => $assignee->id,
    ]);
    expect($audit?->metadata ?? [])->toMatchArray([
        'assignment_changed' => true,
        'due_at_changed' => false,
    ]);
});

it('writes inpatient ward task status transition parity metadata in audit logs', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, [
        'inpatient.ward.create-task',
        'inpatient.ward.update-task-status',
        'inpatient.ward.view-audit-logs',
    ]);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/tasks', [
            'admissionId' => $admission->id,
            'taskType' => 'vitals',
            'priority' => 'urgent',
            'title' => 'Hourly vitals follow-up',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/tasks/'.$created['id'].'/status', [
            'status' => 'completed',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $statusAudit = InpatientWardTaskAuditLogModel::query()
        ->where('inpatient_ward_task_id', $created['id'])
        ->where('action', 'inpatient-ward-task.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'pending',
        'to' => 'completed',
    ]);
    expect($metadata)->toMatchArray([
        'completion_timestamp_required' => true,
        'completion_timestamp_provided' => true,
        'escalation_reason_required' => false,
        'escalation_reason_provided' => false,
        'cancellation_reason_required' => false,
        'cancellation_reason_provided' => false,
    ]);
});

it('writes inpatient ward care plan status transition parity metadata in audit logs', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, [
        'inpatient.ward.create-care-plan',
        'inpatient.ward.update-care-plan-status',
        'inpatient.ward.view-audit-logs',
    ]);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/care-plans', [
            'admissionId' => $admission->id,
            'title' => 'Respiratory stabilization plan',
            'planText' => 'Continue oxygen and monitor saturation.',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/care-plans/'.$created['id'].'/status', [
            'status' => 'completed',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $statusAudit = InpatientWardCarePlanAuditLogModel::query()
        ->where('inpatient_ward_care_plan_id', $created['id'])
        ->where('action', 'inpatient-ward-care-plan.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'active',
        'to' => 'completed',
    ]);
    expect($metadata)->toMatchArray([
        'completion_evidence_required' => true,
        'completion_evidence_provided' => true,
        'cancellation_reason_required' => false,
        'cancellation_reason_provided' => false,
    ]);
});

it('writes inpatient ward discharge checklist status transition parity metadata in audit logs', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, [
        'inpatient.ward.manage-discharge-checklist',
        'inpatient.ward.view-audit-logs',
    ]);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/discharge-checklists', [
            'admissionId' => $admission->id,
            'status' => 'draft',
            'clinicalSummaryCompleted' => true,
            'medicationReconciliationCompleted' => true,
            'followUpPlanCompleted' => true,
            'patientEducationCompleted' => true,
            'transportArranged' => true,
            'billingCleared' => true,
            'documentationCompleted' => true,
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/discharge-checklists/'.$created['id'].'/status', [
            'status' => 'blocked',
            'reason' => 'Transport coordination delay',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'blocked');

    $statusAudit = InpatientWardDischargeChecklistAuditLogModel::query()
        ->where('inpatient_ward_discharge_checklist_id', $created['id'])
        ->where('action', 'inpatient-ward-discharge-checklist.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();

    $metadata = $statusAudit?->metadata ?? [];
    expect($metadata['transition'] ?? [])->toMatchArray([
        'from' => 'draft',
        'to' => 'blocked',
    ]);
    expect($metadata)->toMatchArray([
        'readiness_required_for_status' => false,
        'readiness_available' => true,
        'blocked_reason_required' => true,
        'blocked_reason_provided' => true,
        'completion_readiness_required' => false,
        'completion_readiness_satisfied' => false,
    ]);
});

it('allows inpatient ward audit log list and csv export when authorized', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, [
        'inpatient.ward.read',
        'inpatient.ward.create-task',
        'inpatient.ward.update-task-status',
        'inpatient.ward.create-care-plan',
        'inpatient.ward.update-care-plan-status',
        'inpatient.ward.manage-discharge-checklist',
        'inpatient.ward.view-audit-logs',
    ]);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $task = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/tasks', [
            'admissionId' => $admission->id,
            'taskType' => 'medication',
            'priority' => 'routine',
            'title' => 'Administer scheduled medication',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/tasks/'.$task['id'].'/status', [
            'status' => 'in_progress',
            'reason' => null,
        ])
        ->assertOk();

    $carePlan = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/care-plans', [
            'admissionId' => $admission->id,
            'title' => 'Discharge planning pathway',
            'planText' => 'Prepare discharge summary and medication counseling.',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/care-plans/'.$carePlan['id'].'/status', [
            'status' => 'completed',
            'reason' => null,
        ])
        ->assertOk();

    $checklist = $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/discharge-checklists', [
            'admissionId' => $admission->id,
            'status' => 'draft',
            'clinicalSummaryCompleted' => true,
            'medicationReconciliationCompleted' => true,
            'followUpPlanCompleted' => true,
            'patientEducationCompleted' => true,
            'transportArranged' => true,
            'billingCleared' => true,
            'documentationCompleted' => true,
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/inpatient-ward/discharge-checklists/'.$checklist['id'].'/status', [
            'status' => 'blocked',
            'reason' => 'Pending final ambulance booking',
        ])
        ->assertOk();

    InpatientWardDischargeChecklistAuditLogModel::query()->create([
        'inpatient_ward_discharge_checklist_id' => $checklist['id'],
        'action' => 'inpatient-ward-discharge-checklist.document.pdf.downloaded',
        'actor_id' => $user->id,
        'changes' => [],
        'metadata' => [
            'document_filename' => 'afyanova_discharge.pdf',
            'request_ip' => '203.0.113.13',
        ],
        'created_at' => now()->addSecond(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inpatient-ward/tasks/'.$task['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'inpatient-ward-task.status.updated');

    $this->actingAs($user)
        ->getJson('/api/v1/inpatient-ward/care-plans/'.$carePlan['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.action', 'inpatient-ward-care-plan.status.updated');

    $this->actingAs($user)
        ->getJson('/api/v1/inpatient-ward/discharge-checklists/'.$checklist['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.action', 'inpatient-ward-discharge-checklist.document.pdf.downloaded')
        ->assertJsonPath('data.0.actionLabel', 'PDF Downloaded')
        ->assertJsonPath('data.0.actor.id', $user->id)
        ->assertJsonPath('data.1.action', 'inpatient-ward-discharge-checklist.status.updated');

    $taskExport = $this->actingAs($user)
        ->get('/api/v1/inpatient-ward/tasks/'.$task['id'].'/audit-logs/export');
    $taskExport->assertOk();
    $taskExport->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $taskExport->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    $carePlanExport = $this->actingAs($user)
        ->get('/api/v1/inpatient-ward/care-plans/'.$carePlan['id'].'/audit-logs/export');
    $carePlanExport->assertOk();
    $carePlanExport->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $carePlanExport->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    $checklistExport = $this->actingAs($user)
        ->get('/api/v1/inpatient-ward/discharge-checklists/'.$checklist['id'].'/audit-logs/export');
    $checklistExport->assertOk();
    $checklistExport->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $checklistExport->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
});

it('lists inpatient ward round notes for the selected admission', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, ['inpatient.ward.read', 'inpatient.ward.create-round-note']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/inpatient-ward/round-notes', [
            'admissionId' => $admission->id,
            'roundedAt' => now()->subHour()->toDateTimeString(),
            'shiftLabel' => 'night',
            'roundNote' => 'Patient reviewed on ward round. Stable and comfortable.',
            'carePlan' => 'Continue current plan.',
            'handoffNotes' => 'Night shift to repeat observations.',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/inpatient-ward/round-notes?admissionId='.$admission->id.'&perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.admissionId', $admission->id)
        ->assertJsonPath('data.0.patientId', $patient->id)
        ->assertJsonPath('data.0.shiftLabel', 'night')
        ->assertJsonPath('data.0.acknowledgedByUserId', null)
        ->assertJsonPath('data.0.roundNote', 'Patient reviewed on ward round. Stable and comfortable.');
});

it('allows a ward user to acknowledge handoff guidance on a round note', function (): void {
    $author = User::factory()->create();
    $receiver = User::factory()->create();
    inpatientWardApiGivePermissions($author, ['inpatient.ward.create-round-note']);
    inpatientWardApiGivePermissions($receiver, ['inpatient.ward.read']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $created = $this->actingAs($author)
        ->postJson('/api/v1/inpatient-ward/round-notes', [
            'admissionId' => $admission->id,
            'shiftLabel' => 'evening',
            'roundNote' => 'Patient reviewed before shift change.',
            'carePlan' => 'Continue monitoring overnight.',
            'handoffNotes' => 'Night shift to monitor urine output and pain score.',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($receiver)
        ->patchJson('/api/v1/inpatient-ward/round-notes/'.$created['id'].'/acknowledge')
        ->assertOk()
        ->assertJsonPath('data.id', $created['id'])
        ->assertJsonPath('data.shiftLabel', 'evening')
        ->assertJsonPath('data.acknowledgedByUserId', $receiver->id)
        ->assertJson(fn ($json) => $json->whereType('data.acknowledgedAt', 'string')->etc());
});

it('rejects acknowledging a round note that has no handoff guidance', function (): void {
    $author = User::factory()->create();
    $receiver = User::factory()->create();
    inpatientWardApiGivePermissions($author, ['inpatient.ward.create-round-note']);
    inpatientWardApiGivePermissions($receiver, ['inpatient.ward.read']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);

    $created = $this->actingAs($author)
        ->postJson('/api/v1/inpatient-ward/round-notes', [
            'admissionId' => $admission->id,
            'shiftLabel' => 'day',
            'roundNote' => 'Day round completed and patient remains stable.',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($receiver)
        ->patchJson('/api/v1/inpatient-ward/round-notes/'.$created['id'].'/acknowledge')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['handoffNotes']);
});



it('returns the inpatient ward cross-module follow-up rail for the selected admission', function (): void {
    $user = User::factory()->create();
    inpatientWardApiGivePermissions($user, ['inpatient.ward.read']);

    $patient = inpatientWardApiMakePatient();
    $admission = inpatientWardApiMakeAdmission($patient->id);
    $otherPatient = inpatientWardApiMakePatient(['patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6))]);
    $otherAdmission = inpatientWardApiMakeAdmission($otherPatient->id, ['ward' => 'Ward D', 'bed' => 'D-04']);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'ordered_at' => now()->subHours(3),
        'test_code' => 'CBC',
        'test_name' => 'Full blood count',
        'priority' => 'urgent',
        'status' => 'ordered',
    ]);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'ordered_at' => now()->subDay(),
        'test_code' => 'UREA',
        'test_name' => 'Urea and electrolytes',
        'priority' => 'routine',
        'status' => 'completed',
    ]);

    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.strtoupper(Str::random(8)),
        'patient_id' => $otherPatient->id,
        'admission_id' => $otherAdmission->id,
        'ordered_at' => now()->subHour(),
        'test_code' => 'MPS',
        'test_name' => 'Malaria parasite smear',
        'priority' => 'stat',
        'status' => 'ordered',
    ]);

    PharmacyOrderModel::query()->create([
        'order_number' => 'PHR'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'ordered_at' => now()->subHours(2),
        'medication_code' => 'AMOX500',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => '500mg orally three times daily',
        'quantity_prescribed' => 21,
        'quantity_dispensed' => 7,
        'status' => 'partially_dispensed',
    ]);

    RadiologyOrderModel::query()->create([
        'order_number' => 'RAD'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'ordered_at' => now()->subHours(4),
        'modality' => 'xray',
        'study_description' => 'Chest X-ray',
        'scheduled_for' => now()->addHours(2),
        'status' => 'scheduled',
    ]);

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'admission_id' => $admission->id,
        'invoice_date' => now()->subHours(6),
        'currency_code' => 'TZS',
        'subtotal_amount' => 50000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 50000,
        'paid_amount' => 20000,
        'balance_amount' => 30000,
        'payment_due_at' => now()->addDay(),
        'status' => 'partially_paid',
    ]);

    BillingInvoiceModel::query()->create([
        'invoice_number' => 'INV'.strtoupper(Str::random(8)),
        'patient_id' => $otherPatient->id,
        'admission_id' => $otherAdmission->id,
        'invoice_date' => now()->subHours(12),
        'currency_code' => 'TZS',
        'subtotal_amount' => 120000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 120000,
        'paid_amount' => 0,
        'balance_amount' => 120000,
        'payment_due_at' => now()->addDays(2),
        'status' => 'issued',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inpatient-ward/follow-up-rail?admissionId='.$admission->id.'&itemLimit=2')
        ->assertOk()
        ->assertJsonPath('data.admissionId', $admission->id)
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.modules.laboratory.followUpCount', 1)
        ->assertJsonPath('data.modules.laboratory.statusCounts.ordered', 1)
        ->assertJsonPath('data.modules.laboratory.statusCounts.completed', 1)
        ->assertJsonPath('data.modules.pharmacy.followUpCount', 1)
        ->assertJsonPath('data.modules.pharmacy.statusCounts.partially_dispensed', 1)
        ->assertJsonPath('data.modules.radiology.followUpCount', 1)
        ->assertJsonPath('data.modules.radiology.statusCounts.scheduled', 1)
        ->assertJsonPath('data.modules.billing.followUpCount', 1)
        ->assertJsonPath('data.modules.billing.statusCounts.partially_paid', 1)
        ->assertJsonPath('data.modules.laboratory.items.0.title', 'Full blood count')
        ->assertJsonPath('data.modules.pharmacy.items.0.title', 'Amoxicillin 500mg')
        ->assertJsonPath('data.modules.radiology.items.0.title', 'Chest X-ray')
        ->assertJsonPath('data.modules.billing.items.0.status', 'partially_paid');
});
