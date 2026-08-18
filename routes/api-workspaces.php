<?php

use App\Modules\Admission\Presentation\Http\Controllers\NursingAdmissionController;
use App\Modules\Appointment\Presentation\Http\Controllers\AppointmentController;
use App\Modules\Billing\Presentation\Http\Controllers\PatientInsuranceController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterClinicalAttachmentController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterDiagnosisController;
use App\Modules\Laboratory\Presentation\Http\Controllers\LaboratoryOrderController;
use App\Modules\MedicalRecord\Presentation\Http\Controllers\MedicalRecordController;
use App\Modules\Patient\Presentation\Http\Controllers\PatientController;
use App\Modules\PatientFlow\Presentation\Http\Controllers\PatientFlowController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformConfigurationController;
use App\Modules\PatientVitals\Presentation\Http\Controllers\PatientVitalSetController;
use App\Modules\Pharmacy\Presentation\Http\Controllers\PharmacyOrderController;
use App\Modules\Radiology\Presentation\Http\Controllers\RadiologyOrderController;
use App\Modules\Reception\Presentation\Http\Controllers\ReceptionController;
use App\Modules\ServiceRequest\Presentation\Http\Controllers\NurseQueueController;
use App\Modules\ServiceRequest\Presentation\Http\Controllers\ServiceRequestController;
use App\Modules\Staff\Presentation\Http\Controllers\StaffProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Fresh workspace-scoped API routes
|--------------------------------------------------------------------------
|
| Extracted from routes/api.php on 2026-08-10 per explicit instruction: old
| (generic/shared) API routes stay in routes/api.php, marked legacy there;
| new, workspace-scoped routes live in this file going forward. Nothing was
| deleted or renamed — every route below kept its exact URI, name, and
| middleware, so this is a pure reorganization, not a behavior change (see
| the route:list diff run immediately after this split for proof).
|
| Every route here still reuses an existing controller/use-case internally
| ("Reuse existing controllers — no business-logic duplication" — the same
| rule each block below already carried before the move). New reception/
| clinician/nursing/etc. frontend code must call routes FROM THIS FILE,
| never the legacy ones in routes/api.php directly, even when they're
| functionally identical — see the memory note this rule is pinned under
| ("reception-frontend-must-use-reception-scoped-api").
|
| Loaded by App\Providers\WorkspaceRouteServiceProvider. Shares the
| 'api.platform' middleware group with routes/api.php (defined once in
| bootstrap/app.php), but spells out the 'api/v1' prefix itself since
| loadRoutesFrom() doesn't inherit withRouting()'s automatic 'api' prefix.
|
*/
Route::middleware('api.platform')
    ->prefix('api/v1')
    ->group(function (): void {
        // ============================================================
        // RECEPTION WORKSPACE ROUTES (Volume 2.1 §12.2)
        // Reuse existing controllers — no business-logic duplication.
        // ============================================================
        // Added 2026-08-12 (Patient Registration UX direction §2, Region/
        // District) — reuses PlatformConfigurationController::countryProfile
        // + GetCountryProfileUseCase as-is. This is now the only route
        // exposing that use case; routes/api.php's generic
        // `platform/country-profile` was removed in the 2026-08 API surface
        // consolidation. No permission gate: reference/config data, not
        // patient PHI, so the group's baseline middleware is the whole gate.
        // Region/District comboboxes read `data.profile.patientLocations`.
        Route::get('reception/location-options', [PlatformConfigurationController::class, 'countryProfile'])
            ->name('reception.location-options');
        Route::get('reception/patients', [PatientController::class, 'index'])
            ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
            ->name('reception.patients');
        Route::post('reception/patients', [PatientController::class, 'store'])
            ->middleware(['can:patients.create', 'facility.entitlement:patients.registration'])
            ->name('reception.patients.store');
        // Reception twin of patients/duplicate-check. Same controller, same
        // middleware as the legacy route — added so registration can stop
        // reaching into the generic API mid-flow (2026-08-17).
        Route::post('reception/patients/duplicate-check', [PatientController::class, 'checkDuplicates'])
            ->middleware(['can:patients.create', 'facility.entitlement:patients.registration'])
            ->name('reception.patients.duplicate-check');
        Route::get('reception/patients/search', [PatientController::class, 'index'])
            ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
            ->name('reception.patients.search');
        Route::get('reception/patients/{mrn}', [PatientController::class, 'show'])
            ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
            ->name('reception.patients.show');
        Route::patch('reception/patients/{mrn}', [PatientController::class, 'update'])
            ->middleware(['can:patient.demographics.update', 'facility.entitlement:patients.demographics'])
            ->name('reception.patients.update');
        // Added (2026-08-10): reusing PatientController::summary/activityFeed
        // as-is (same GetPatientSummaryUseCase / ListPatientAuditLogsUseCase,
        // no logic duplication) — found still called directly at the generic
        // `/api/v1/patients/{id}/summary` and `/activity-feed` routes from the
        // Patient Profile cards (§8.1), which is exactly the old-frontend/
        // old-API coupling this workspace redesign left behind. `{id}` (UUID),
        // not `{mrn}` like the two routes above — summary/activityFeed's use
        // cases key on the patient UUID, matching what the frontend already
        // holds for a selected patient.
        Route::get('reception/patients/{id}/summary', [PatientController::class, 'summary'])
            ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
            ->name('reception.patients.summary');
        Route::get('reception/patients/{id}/activity-feed', [PatientController::class, 'activityFeed'])
            ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
            ->name('reception.patients.activity-feed');
        // Added 2026-08-11 (§16 #10, Insurance add/verify UI) — reception-
        // scoped, reusing PatientInsuranceController directly (no logic
        // duplication), same pattern as summary/activity-feed above and
        // reception/patients/{mrn} reusing PatientController::show/update.
        // Insurance *display* already worked through the summary endpoint;
        // these three close the add/edit/verify gap. Same middleware the
        // generic (non-reception) insurance routes already use — this
        // isn't a new authorization rule, just a second, reception-scoped
        // path to the same one.
        Route::post('reception/patients/{id}/insurance', [PatientInsuranceController::class, 'store'])
            ->middleware(['can:patients.insurance.manage', 'facility.entitlement:patients.demographics'])
            ->name('reception.patients.insurance.store');
        Route::patch('reception/patients/{id}/insurance/{recordId}', [PatientInsuranceController::class, 'update'])
            ->middleware(['can:patients.insurance.manage', 'facility.entitlement:patients.demographics'])
            ->name('reception.patients.insurance.update');
        Route::patch('reception/patients/{id}/insurance/{recordId}/verify', [PatientInsuranceController::class, 'verify'])
            ->middleware(['can:patients.insurance.verify', 'facility.entitlement:patients.demographics'])
            ->name('reception.patients.insurance.verify');
        // Added (2026-08-10, Volume 3.7 audit): reception-scoped list, reusing
        // ListAppointmentsUseCase + AppointmentResponseTransformer internally
        // (no logic duplication) — the frontend previously called the generic
        // `/api/v1/appointments` GET directly for the profile's "Upcoming
        // appointments" card, which is exactly the old-frontend/old-API
        // coupling this workspace redesign is meant to avoid. Powers both that
        // card (?patientId=) and the day/week Appointment Scheduling view
        // (?from=&to=) on the same reception-scoped contract. Repointed
        // (2026-08-10, Volume 2.1 §9.1) from AppointmentController::index to
        // ReceptionController::listAppointments, which adds the
        // reception-only patientName/patientNumber fields the Schedule view
        // needs — see that method's docblock for why that's presentation-layer
        // enrichment, not new business logic.
        Route::get('reception/appointments', [ReceptionController::class, 'listAppointments'])
            ->middleware('can:appointments.read')
            ->name('reception.appointments.index');
        Route::post('reception/appointments', [AppointmentController::class, 'store'])
            ->middleware('can:appointments.create')
            ->name('reception.appointments.store');
        // Added (2026-08-10, Volume 2.1 §9.2): the Appointment Scheduling form's
        // Department select (required only when Clinician is left blank) reuses
        // AppointmentController::departmentOptions — same options the generic
        // `/appointments/department-options` route already serves — kept on the
        // reception-scoped contract rather than pointed at that route directly.
        Route::get('reception/appointments/department-options', [AppointmentController::class, 'departmentOptions'])
            ->middleware('can:appointments.read')
            ->name('reception.appointments.department-options');
        // Added (2026-08-10, Volume 2.1 §9.2): the Appointment Scheduling form's
        // Clinician select reuses StaffProfileController::clinicalDirectory
        // (already filters to active clinical staff server-side) instead of the
        // frontend calling the generic `/staff/clinical-directory` route.
        Route::get('reception/clinicians', [StaffProfileController::class, 'clinicalDirectory'])
            ->middleware('can:staff.clinical-directory.read')
            ->name('reception.clinicians');
        // Bug fix (2026-08-10, Volume 3.7 audit): this route referenced a
        // nonexistent ReceptionController::checkInAppointment() method — any
        // request would have thrown a fatal "call to undefined method" 500. The
        // real, tested check-in logic is ReceptionController::checkIn() (already
        // used by the working `appointments.check-in` route above); pointed here
        // instead so the reception-workspace URL actually works.
        Route::post('reception/queue/{id}/check-in', [ReceptionController::class, 'checkIn'])
            ->middleware('can:appointment.check-in')
            ->name('reception.queue.check-in');
        // Added (2026-08-10, Volume 3.7 audit): the reception workspace frontend
        // needs a Cancel action (§10.2) scoped to this block's own `reception/*`
        // contract — not a direct call into the generic `/appointments/{id}/status`
        // endpoint, which would recreate exactly the old-frontend/old-API coupling
        // this workspace redesign was meant to leave behind.
        Route::post('reception/queue/{id}/cancel', [ReceptionController::class, 'cancelQueueItem'])
            ->middleware('can:appointment.check-in')
            ->name('reception.queue.cancel');
        // Decided and wired 2026-08-11 (§16 #3): ephemeral broadcast-only
        // notification (AppointmentCalled), not a persisted AppointmentStatus
        // case — see that event's own docblock for the full reasoning.
        // `appointments.read`, not `appointment.check-in` like check-in/
        // cancel above: this doesn't change the appointment, it's the same
        // permission Reception's own queue view already requires to see
        // this row at all.
        Route::post('reception/queue/{id}/call', [ReceptionController::class, 'callAppointment'])
            ->middleware('can:appointments.read')
            ->name('reception.queue.call');
        // Added 2026-08-10 (Volume 3.7 T5.5): persists a drag-to-reorder as
        // appointments.queue_position — see ReorderReceptionQueueUseCase's
        // docblock for why a hard tier floor is enforced server-side.
        Route::post('reception/queue/reorder', [ReceptionController::class, 'reorderQueue'])
            ->middleware('can:appointment.check-in')
            ->name('reception.queue.reorder');
        Route::get('reception/patients/{patientId}/flow-timeline', [PatientFlowController::class, 'patientTimeline'])
            ->middleware('can:patients.read')
            ->name('reception.patients.flow-timeline');

        // ============================================================
        // CLINICIAN WORKSPACE ROUTES (Volume 2.2 §13.2)
        // Reuse existing controllers — no business-logic duplication.
        // ============================================================
        Route::get('clinician/patients', [PatientController::class, 'index'])
            ->middleware('can:patients.read')
            ->name('clinician.patients');
        Route::get('clinician/patients/{mrn}', [PatientController::class, 'show'])
            ->middleware('can:patients.read')
            ->name('clinician.patients.show');
        Route::get('clinician/patients/{id}/summary', [PatientController::class, 'summary'])
            ->middleware('can:patients.read')
            ->name('clinician.patients.summary');
        Route::get('clinician/encounters', [EncounterController::class, 'index'])
            ->middleware('can:medical.records.read')
            ->name('clinician.encounters');
        // Tab totals for the queue. Same ability as the list it labels, and
        // declared before `encounters/{id}` so "queue-stage-counts" is not
        // swallowed as an encounter id.
        Route::get('clinician/encounters/queue-stage-counts', [EncounterController::class, 'queueStageCounts'])
            ->middleware('can:medical.records.read')
            ->name('clinician.encounters.queue-stage-counts');
        Route::get('clinician/encounters/by-appointment/{appointmentId}', [EncounterController::class, 'resolveForAppointment'])
            ->middleware('can:medical.records.read')
            ->name('clinician.encounters.by-appointment');
        Route::get('clinician/encounters/{id}', [EncounterController::class, 'show'])
            ->middleware('can:medical.records.read')
            ->name('clinician.encounters.show');
        // `{id}` (2026-08-13, found & fixed while wiring Nursing's own notes
        // route below): was missing entirely — `store()` requires `string $id`
        // positionally, and with no route segment to bind it, Laravel's
        // dependency resolution misaligns the remaining class-typed
        // parameters, throwing a TypeError on every real call (confirmed
        // live: a 500, not a validation error). The correctly-parameterized
        // sibling route (`encounters/{id}/clinical-documents`, routes/api.php)
        // already proves the right shape; this workspace-scoped route just
        // never got it when it was added.
        Route::post('clinician/notes/{id}', [EncounterClinicalAttachmentController::class, 'store'])
            ->middleware('can:medical.records.create')
            ->name('clinician.notes.store');
        Route::post('clinician/notes/{id}/sign', [EncounterController::class, 'updateStatus'])
            ->middleware('can:medical.records.finalize')
            ->name('clinician.notes.sign');
        Route::post('clinician/medical-records', [MedicalRecordController::class, 'store'])
            ->middleware('can:medical.records.create')
            ->name('clinician.medical-records.store');
        Route::get('clinician/medical-records/{id}', [MedicalRecordController::class, 'show'])
            ->middleware('can:medical.records.read')
            ->name('clinician.medical-records.show');
        // Guarded exactly as its legacy twin (medical-records/{id}) is: the
        // ability is `medical.records.draft.update` and the record id is passed
        // so the gate can enforce authorship. It previously demanded
        // `medical.records.update`, which no role in config/roles.php grants —
        // so a physician could create a note and then got 403 on every save
        // after the first (2026-08-17, api-surface-consolidation-plan.md §2.1).
        Route::patch('clinician/medical-records/{id}', [MedicalRecordController::class, 'update'])
            ->middleware('can:medical.records.draft.update,id')
            ->name('clinician.medical-records.update');
        Route::patch('clinician/medical-records/{id}/status', [MedicalRecordController::class, 'updateStatus'])
            ->middleware('can:medical.records.update-status')
            ->name('clinician.medical-records.update-status');
        Route::get('clinician/orders/lab', [LaboratoryOrderController::class, 'index'])
            ->middleware('can:lab.order')
            ->name('clinician.orders.lab.index');
        Route::post('clinician/orders/lab', [LaboratoryOrderController::class, 'store'])
            ->middleware('can:lab.order')
            ->name('clinician.orders.lab');
        Route::post('clinician/orders/lab/{id}/cancel', [LaboratoryOrderController::class, 'applyLifecycleAction'])
            ->middleware('can:lab.order')
            ->name('clinician.orders.lab.cancel');
        Route::get('clinician/orders/imaging', [RadiologyOrderController::class, 'index'])
            ->middleware('can:imaging.order')
            ->name('clinician.orders.imaging.index');
        Route::post('clinician/orders/imaging', [RadiologyOrderController::class, 'store'])
            ->middleware('can:imaging.order')
            ->name('clinician.orders.imaging');
        Route::post('clinician/orders/imaging/{id}/cancel', [RadiologyOrderController::class, 'applyLifecycleAction'])
            ->middleware('can:imaging.order')
            ->name('clinician.orders.imaging.cancel');
        Route::get('clinician/orders/medication', [PharmacyOrderController::class, 'index'])
            ->middleware('can:medication.prescribe')
            ->name('clinician.orders.medication.index');
        Route::post('clinician/orders/medication', [PharmacyOrderController::class, 'store'])
            ->middleware('can:medication.prescribe')
            ->name('clinician.orders.medication');
        Route::post('clinician/orders/medication/{id}/cancel', [PharmacyOrderController::class, 'applyLifecycleAction'])
            ->middleware('can:medication.prescribe')
            ->name('clinician.orders.medication.cancel');
        Route::get('clinician/catalog/medications', [PharmacyOrderController::class, 'approvedMedicinesCatalog'])
            ->middleware('can:medication.prescribe')
            ->name('clinician.catalog.medications');

        // Patient-flow transitions (2026-08-16 flow audit, finding 05).
        // Until now the clinician prefix had encounters, notes, orders, results
        // and catalog — and no way at all to move a visit, which is why the
        // workspace faked "start consultation" in local component state and the
        // patient stayed on every other screen as "Waiting for Doctor".
        //
        // Delegates to the existing AppointmentController actions rather than
        // reimplementing them: consultation ownership, forced takeover with a
        // reason, the displaced-owner notification, the transition guard and the
        // audit row are all already correct there. This is the missing door, not
        // new logic.
        Route::patch('clinician/visits/{id}/start-consultation', [AppointmentController::class, 'startConsultation'])
            ->middleware('can:appointments.start-consultation')
            ->name('clinician.visits.start-consultation');
        Route::patch('clinician/visits/{id}/provider-workflow', [AppointmentController::class, 'updateProviderWorkflow'])
            ->middleware('can:appointments.manage-provider-session')
            ->name('clinician.visits.provider-workflow');
        Route::get('clinician/patients/{patientId}/flow-timeline', [PatientFlowController::class, 'patientTimeline'])
            ->middleware('can:patients.read')
            ->name('clinician.patients.flow-timeline');
        Route::get('clinician/visit-timeline', [PatientFlowController::class, 'visitTimeline'])
            ->middleware('can:patients.read')
            ->name('clinician.visit-timeline');
        Route::post('clinician/orders/referral', [ServiceRequestController::class, 'store'])
            ->middleware('can:service.requests.create')
            ->name('clinician.orders.referral');
        Route::post('clinician/diagnoses', [EncounterDiagnosisController::class, 'store'])
            ->middleware('can:medical.records.create')
            ->name('clinician.diagnoses.store');
        // `laboratory.orders.read`, not `lab.results.read` (2026-08-16 RBAC
        // audit): that permission has never existed in the catalog, so this
        // route denied every non-super-admin user with "This action is
        // unauthorized" — indistinguishable from a legitimate denial, which is
        // why it survived. The canonical name is the one the equivalent
        // `laboratory-orders` route in routes/api.php already uses.
        Route::get('clinician/results', [LaboratoryOrderController::class, 'index'])
            ->middleware('can:laboratory.orders.read')
            ->name('clinician.results');
        // `clinician/results/{id}/acknowledge` removed (2026-08-16 RBAC audit):
        // LaboratoryOrderController has no `acknowledge()` method and no
        // result-acknowledgement use case exists anywhere in the Laboratory
        // module, so this route could only ever have 403'd (bad permission) or
        // 500'd ("Call to undefined method"). Same shape as the `nursing/complete`
        // route removed on 2026-08-13. Result acknowledgement is unbuilt, not
        // broken configuration — useClinicianResults.ts still calls it and needs
        // a real endpoint before that button can work.

        // ============================================================
        // NURSING WORKSPACE ROUTES (Volume 2.3 §12.2)
        // Reuse existing controllers — no business-logic duplication.
        // ============================================================
        Route::get('nursing/patients', [PatientController::class, 'index'])
            ->middleware('can:patients.read')
            ->name('nursing.patients');
        Route::post('nursing/vitals', [PatientVitalSetController::class, 'store'])
            ->middleware('can:patient.vitals.record')
            ->name('nursing.vitals.store');
        // Added 2026-08-13 (Volume 3.8 Phase 2 follow-up) — reported
        // directly by the user: vitals were saving successfully but never
        // showing up anywhere, because nothing ever fetched them back. The
        // "Recent Vitals" card was reading from a local, component-only
        // ref that resets on every navigation/reload — the data was safely
        // persisted server-side the whole time, just never re-read.
        // Reuses `PatientVitalSetController::latestForPatient` (already
        // real, already used by the generic `patient-vitals/patient/{id}`
        // route) rather than duplicating it — same "reuse existing
        // controllers" rule as every route in this file, and keeps
        // nursing's frontend calling its own `/nursing/*` contract instead
        // of the generic path.
        Route::get('nursing/vitals/{patientId}', [PatientVitalSetController::class, 'latestForPatient'])
            ->middleware('can:patients.read')
            ->name('nursing.vitals.latest');
        // `{encounterId}`/`{id}` (2026-08-13, found while wiring Volume 3.8
        // Phase 3/4): both routes were missing their URI parameter entirely
        // — `assess()`/`store()` each require it positionally (`string
        // $encounterId`/`string $id`), and with no route segment to bind it,
        // Laravel's dependency resolution misaligns the remaining
        // class-typed parameters, throwing a TypeError on every real call
        // (confirmed live: both 500'd, not a validation error — neither
        // endpoint could ever have worked in this shape). The
        // correctly-parameterized legacy siblings (`nurse-queue/{encounterId}
        // /assess`, `encounters/{id}/clinical-documents`, both routes/api.php)
        // already prove the right shape; these workspace-scoped routes just
        // never got it when added.
        Route::post('nursing/assessments/{encounterId}', [NurseQueueController::class, 'assess'])
            ->middleware('can:service.requests.create')
            ->name('nursing.assessments.store');
        Route::post('nursing/notes/{id}', [EncounterClinicalAttachmentController::class, 'store'])
            ->middleware('can:medical.records.create')
            ->name('nursing.notes.store');
        Route::get('nursing/mar', [PharmacyOrderController::class, 'index'])
            ->middleware('can:pharmacy.orders.read')
            ->name('nursing.mar');
        // `nursing/mar/{id}/administer` removed (2026-08-16 RBAC audit):
        // PharmacyOrderController has no `administer()` method and
        // `pharmacy.orders.administer` is not a permission that exists, so this
        // route was doubly dead. The frontend had already given up on it —
        // medicationStore.ts records removing its `administerMedication` call on
        // 2026-08-13 for exactly this reason — leaving the route orphaned
        // server-side. Recording medication administration is unbuilt work, not
        // a misconfiguration.
        // Routing targets for triage completion (2026-08-16). Walk-ins are
        // registered with no department at all, and recording vitals is the
        // moment a nurse knows which clinic the patient belongs to — so the
        // nursing workspace needs its own scoped copy of the options list
        // reception already has for scheduling.
        Route::get('nursing/department-options', [AppointmentController::class, 'departmentOptions'])
            ->middleware('can:appointments.read')
            ->name('nursing.department-options');
        Route::get('nursing/tasks', [NurseQueueController::class, 'index'])
            ->middleware('can:service.requests.read')
            ->name('nursing.tasks');
        Route::get('nursing/active-visit/{patientId}', [NurseQueueController::class, 'activeVisit'])
            ->middleware('can:patients.read')
            ->name('nursing.active-visit');
        Route::post('nursing/admissions', [NursingAdmissionController::class, 'store'])
            ->middleware('can:service.requests.create')
            ->name('nursing.admissions.store');
        Route::post('nursing/return-to-reception/{appointmentId}', [NurseQueueController::class, 'returnToReception'])
            ->middleware('can:service.requests.create')
            ->name('nursing.return-to-reception');

        // Explicit nursing pickup/handback (2026-08-16 flow audit, finding 04).
        // Triage has had a claim since Phase 2; nursing had none, so a nurse
        // actively working with a patient was indistinguishable from a patient
        // sitting untouched in a queue. These change no appointment status —
        // nursing happens inside an existing status — they record the step.
        Route::post('nursing/visits/{encounterId}/claim', [PatientFlowController::class, 'claimForNursing'])
            ->middleware('can:service.requests.create')
            ->name('nursing.visits.claim');
        Route::post('nursing/visits/{encounterId}/release', [PatientFlowController::class, 'releaseFromNursing'])
            ->middleware('can:service.requests.create')
            ->name('nursing.visits.release');
        Route::get('nursing/patients/{patientId}/flow-timeline', [PatientFlowController::class, 'patientTimeline'])
            ->middleware('can:patients.read')
            ->name('nursing.patients.flow-timeline');
        Route::get('nursing/visit-timeline', [PatientFlowController::class, 'visitTimeline'])
            ->middleware('can:patients.read')
            ->name('nursing.visit-timeline');
        Route::post('nursing/visit-notes/{appointmentId}', [NurseQueueController::class, 'addVisitNote'])
            ->middleware('can:patients.read')
            ->name('nursing.add-visit-note');
        Route::get('nursing/visit-notes/{appointmentId}', [NurseQueueController::class, 'getVisitNotes'])
            ->middleware('can:patients.read')
            ->name('nursing.get-visit-notes');
        Route::put('nursing/visit-notes/{appointmentId}', [NurseQueueController::class, 'updateVisitNotes'])
            ->middleware('can:patients.read')
            ->name('nursing.update-visit-notes');
        // Restored 2026-08-16: this route went missing from the working tree
        // while the laboratory block was being added. NurseQueueController::
        // deleteVisitNote() still exists and NurseVisitNotesApiTest still covers
        // it, so the only symptom was a 405 on delete — the method was fine, the
        // door had gone.
        Route::delete('nursing/visit-notes/{appointmentId}', [NurseQueueController::class, 'deleteVisitNote'])
            ->middleware('can:patients.read')
            ->name('nursing.delete-visit-note');
        // ============================================================
        // LABORATORY WORKSPACE ROUTES (Volume 2.4)
        // ============================================================
        Route::get('laboratory/orders', [LaboratoryOrderController::class, 'index'])
            ->middleware('can:laboratory.orders.read')
            ->name('laboratory.orders.index');
        Route::get('laboratory/orders/status-counts', [LaboratoryOrderController::class, 'statusCounts'])
            ->middleware('can:laboratory.orders.read')
            ->name('laboratory.orders.status-counts');
        Route::get('laboratory/orders/{id}', [LaboratoryOrderController::class, 'show'])
            ->middleware('can:laboratory.orders.read')
            ->name('laboratory.orders.show');
        Route::patch('laboratory/orders/{id}/status', [LaboratoryOrderController::class, 'updateStatus'])
            ->middleware('can:lab.sample.collect')
            ->name('laboratory.orders.update-status');
        Route::patch('laboratory/orders/{id}/verify', [LaboratoryOrderController::class, 'verifyResult'])
            ->middleware('can:lab.result.verify')
            ->name('laboratory.orders.verify');
        // Matches the legacy twin's ability. Guarding this with plain
        // `laboratory.orders.read` let LAB.STAFF read audit logs through the
        // workspace door but not the legacy one, quietly bypassing the
        // dedicated audit permission — the same seniority split that keeps
        // result verification with supervisors.
        Route::get('laboratory/orders/{id}/audit-logs', [LaboratoryOrderController::class, 'auditLogs'])
            ->middleware('can:laboratory.orders.audit-logs.view')
            ->name('laboratory.orders.audit-logs');
        Route::get('laboratory/patients/{patientId}/flow-timeline', [PatientFlowController::class, 'patientTimeline'])
            ->middleware('can:laboratory.orders.read')
            ->name('laboratory.patients.flow-timeline');

        // ============================================================
        // RADIOLOGY WORKSPACE ROUTES (Volume 2.5)
        // ============================================================
        // Mirrors the laboratory workspace door, action for action, and reuses
        // RadiologyOrderController unchanged. The imaging workspace *receives*
        // orders: placing, amending, signing and cancelling stay on the
        // clinician's side, so `store`/`update`/`sign`/`discardDraft`/
        // `applyLifecycleAction` are deliberately not exposed here — exactly as
        // the laboratory block above omits them.
        //
        // Abilities match each action's legacy twin in routes/api.php, which is
        // what RouteAuthorizationContractTest enforces: reads under
        // `radiology.orders.read`, moving a study under `imaging.perform`, and
        // audit logs under the dedicated `radiology.orders.audit-logs.view`
        // rather than plain read, keeping the same seniority split laboratory
        // uses.
        //
        Route::get('radiology/orders', [RadiologyOrderController::class, 'index'])
            ->middleware('can:radiology.orders.read')
            ->name('radiology.orders.index');
        // Declared before `orders/{id}` so "status-counts" is not swallowed as
        // an order id, matching the laboratory ordering above.
        Route::get('radiology/orders/status-counts', [RadiologyOrderController::class, 'statusCounts'])
            ->middleware('can:radiology.orders.read')
            ->name('radiology.orders.status-counts');
        Route::get('radiology/orders/{id}', [RadiologyOrderController::class, 'show'])
            ->middleware('can:radiology.orders.read')
            ->name('radiology.orders.show');
        Route::patch('radiology/orders/{id}/status', [RadiologyOrderController::class, 'updateStatus'])
            ->middleware('can:imaging.perform')
            ->name('radiology.orders.update-status');
        // Step 4 of the imaging bench, and the only path to the patient chart.
        // Separate from update-status on purpose: entering a report and
        // releasing it must never be one click, which is the defect the
        // laboratory workspace shipped with.
        Route::patch('radiology/orders/{id}/verify', [RadiologyOrderController::class, 'verifyResult'])
            ->middleware('can:imaging.result.verify')
            ->name('radiology.orders.verify');
        Route::get('radiology/orders/{id}/audit-logs', [RadiologyOrderController::class, 'auditLogs'])
            ->middleware('can:radiology.orders.audit-logs.view')
            ->name('radiology.orders.audit-logs');
        Route::get('radiology/patients/{patientId}/flow-timeline', [PatientFlowController::class, 'patientTimeline'])
            ->middleware('can:radiology.orders.read')
            ->name('radiology.patients.flow-timeline');

        // ============================================================
        // PHARMACY WORKSPACE ROUTES (Volume 2.6)
        // ============================================================
        // The dispensing bench. Reuses PharmacyOrderController unchanged, and
        // every ability matches that action's legacy twin in routes/api.php —
        // what RouteAuthorizationContractTest enforces.
        //
        // Prescribing stays on the clinician's side: store, update, sign,
        // discardDraft and applyLifecycleAction are deliberately absent, exactly
        // as the laboratory and radiology blocks omit them. Pharmacy receives
        // orders; it does not write them.
        //
        // Pharmacy carries four things the diagnostic workspaces do not, and all
        // four belong to the bench rather than the prescriber: a safety review
        // the dispenser reads before handing anything over, live stock
        // availability, medication reconciliation, and formulary policy review.
        // The last two are supervisor work and keep their own abilities.
        Route::get('pharmacy/orders', [PharmacyOrderController::class, 'index'])
            ->middleware('can:pharmacy.orders.read')
            ->name('pharmacy.orders.index');
        // Declared before `orders/{id}` so these are not swallowed as an order id.
        Route::get('pharmacy/orders/status-counts', [PharmacyOrderController::class, 'statusCounts'])
            ->middleware('can:pharmacy.orders.read')
            ->name('pharmacy.orders.status-counts');
        // Stock on hand for the medicines on the worklist. A dispenser needs to
        // know what can actually be handed over before promising it.
        Route::get('pharmacy/availability', [PharmacyOrderController::class, 'availability'])
            ->middleware('can:pharmacy.orders.read')
            ->name('pharmacy.availability');
        Route::get('pharmacy/approved-medicines-catalog', [PharmacyOrderController::class, 'approvedMedicinesCatalog'])
            ->middleware('can:pharmacy.orders.read')
            ->name('pharmacy.approved-medicines-catalog');
        Route::get('pharmacy/orders/{id}', [PharmacyOrderController::class, 'show'])
            ->middleware('can:pharmacy.orders.read')
            ->name('pharmacy.orders.show');
        // Interactions, allergies and blockers for this order, read at the
        // counter before the medicine changes hands.
        Route::get('pharmacy/orders/{id}/safety-review', [PharmacyOrderController::class, 'safetyReview'])
            ->middleware('can:pharmacy.orders.read')
            ->name('pharmacy.orders.safety-review');
        // Moving an order through preparation and dispensing, including a
        // partial fill — the state neither diagnostic workspace has.
        Route::patch('pharmacy/orders/{id}/status', [PharmacyOrderController::class, 'updateStatus'])
            ->middleware('can:medication.dispense')
            ->name('pharmacy.orders.update-status');
        // Supervisor sign-off on a completed dispense, the seniority split that
        // mirrors laboratory result verification.
        Route::patch('pharmacy/orders/{id}/verify', [PharmacyOrderController::class, 'verifyDispense'])
            ->middleware('can:pharmacy.orders.verify-dispense')
            ->name('pharmacy.orders.verify');
        Route::patch('pharmacy/orders/{id}/reconciliation', [PharmacyOrderController::class, 'reconcile'])
            ->middleware('can:pharmacy.orders.reconcile')
            ->name('pharmacy.orders.reconciliation');
        Route::patch('pharmacy/orders/{id}/policy', [PharmacyOrderController::class, 'updatePolicy'])
            ->middleware('can:pharmacy.orders.manage-policy')
            ->name('pharmacy.orders.policy');
        Route::get('pharmacy/orders/{id}/audit-logs', [PharmacyOrderController::class, 'auditLogs'])
            ->middleware('can:pharmacy.orders.audit-logs.view')
            ->name('pharmacy.orders.audit-logs');
        Route::get('pharmacy/patients/{patientId}/flow-timeline', [PatientFlowController::class, 'patientTimeline'])
            ->middleware('can:pharmacy.orders.read')
            ->name('pharmacy.patients.flow-timeline');
    });
