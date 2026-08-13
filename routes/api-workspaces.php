<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Http\Middleware\ResolvePlatformScopeContext;
use App\Modules\Appointment\Presentation\Http\Controllers\AppointmentController;
use App\Modules\Billing\Presentation\Http\Controllers\PatientInsuranceController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterClinicalAttachmentController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterDiagnosisController;
use App\Modules\Laboratory\Presentation\Http\Controllers\LaboratoryOrderController;
use App\Modules\Patient\Presentation\Http\Controllers\PatientController;
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
| Loaded by App\Providers\WorkspaceRouteServiceProvider (mirrors how
| routes/billing-phase1.php is loaded by BillingServiceProvider — same
| middleware stack and 'api/v1' prefix as routes/api.php's main group,
| replicated explicitly here since loadRoutesFrom() doesn't inherit that
| group's context the way nesting inside the same closure would.
|
*/
Route::middleware(['web', 'auth', ResolvePlatformScopeContext::class, EnforceTenantIsolationWhenEnabled::class, 'session.limits', EnsureMappedFacilitySubscriptionEntitlement::class])
    ->prefix('api/v1')
    ->group(function (): void {
        // ============================================================
        // RECEPTION WORKSPACE ROUTES (Volume 2.1 §12.2)
        // Reuse existing controllers — no business-logic duplication.
        // ============================================================
        // Added 2026-08-12 (Patient Registration UX direction §2, Region/
        // District) — reuses PlatformConfigurationController::countryProfile
        // + GetCountryProfileUseCase as-is, the same route/use-case
        // routes/api.php's own `platform/country-profile` already exposes.
        // No new permission gate: the generic route carries none either
        // (this is reference/config data, not patient PHI), matching the
        // group's own baseline middleware. Region/District comboboxes read
        // `data.profile.patientLocations` from this response.
        Route::get('reception/location-options', [PlatformConfigurationController::class, 'countryProfile'])
            ->name('reception.location-options');
        Route::get('reception/patients', [PatientController::class, 'index'])
            ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
            ->name('reception.patients');
        Route::post('reception/patients', [PatientController::class, 'store'])
            ->middleware(['can:patients.create', 'facility.entitlement:patients.registration'])
            ->name('reception.patients.store');
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

        // ============================================================
        // CLINICIAN WORKSPACE ROUTES (Volume 2.2 §13.2)
        // Reuse existing controllers — no business-logic duplication.
        // ============================================================
        Route::get('clinician/patients', [PatientController::class, 'index'])
            ->middleware('can:patients.read')
            ->name('clinician.patients');
        Route::get('clinician/encounters', [EncounterController::class, 'index'])
            ->middleware('can:medical.records.read')
            ->name('clinician.encounters');
        Route::post('clinician/encounters', [EncounterController::class, 'store'])
            ->middleware('can:medical.records.create')
            ->name('clinician.encounters.store');
        Route::get('clinician/encounters/{id}', [EncounterController::class, 'show'])
            ->middleware('can:medical.records.read')
            ->name('clinician.encounters.show');
        Route::post('clinician/notes', [EncounterClinicalAttachmentController::class, 'store'])
            ->middleware('can:medical.records.create')
            ->name('clinician.notes.store');
        Route::post('clinician/notes/{id}/sign', [EncounterController::class, 'updateStatus'])
            ->middleware('can:medical.records.update')
            ->name('clinician.notes.sign');
        Route::post('clinician/orders/lab', [LaboratoryOrderController::class, 'store'])
            ->middleware('can:lab.order')
            ->name('clinician.orders.lab');
        Route::post('clinician/orders/imaging', [RadiologyOrderController::class, 'store'])
            ->middleware('can:radiology.orders.create')
            ->name('clinician.orders.imaging');
        Route::post('clinician/orders/medication', [PharmacyOrderController::class, 'store'])
            ->middleware('can:pharmacy.orders.create')
            ->name('clinician.orders.medication');
        Route::post('clinician/orders/referral', [ServiceRequestController::class, 'store'])
            ->middleware('can:service.requests.create')
            ->name('clinician.orders.referral');
        Route::post('clinician/diagnoses', [EncounterDiagnosisController::class, 'store'])
            ->middleware('can:medical.records.create')
            ->name('clinician.diagnoses.store');
        Route::get('clinician/results', [LaboratoryOrderController::class, 'index'])
            ->middleware('can:lab.results.read')
            ->name('clinician.results');
        Route::post('clinician/results/{id}/acknowledge', [LaboratoryOrderController::class, 'acknowledge'])
            ->middleware('can:lab.results.read')
            ->name('clinician.results.acknowledge');

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
        Route::post('nursing/assessments', [NurseQueueController::class, 'assess'])
            ->middleware('can:service.requests.create')
            ->name('nursing.assessments.store');
        Route::post('nursing/notes', [EncounterClinicalAttachmentController::class, 'store'])
            ->middleware('can:medical.records.create')
            ->name('nursing.notes.store');
        Route::get('nursing/mar', [PharmacyOrderController::class, 'index'])
            ->middleware('can:pharmacy.orders.read')
            ->name('nursing.mar');
        Route::post('nursing/mar/{id}/administer', [PharmacyOrderController::class, 'administer'])
            ->middleware('can:pharmacy.orders.administer')
            ->name('nursing.mar.administer');
        Route::get('nursing/tasks', [NurseQueueController::class, 'index'])
            ->middleware('can:service.requests.read')
            ->name('nursing.tasks');
        Route::post('nursing/tasks/{id}/complete', [NurseQueueController::class, 'complete'])
            ->middleware('can:service.requests.update')
            ->name('nursing.tasks.complete');
    });
