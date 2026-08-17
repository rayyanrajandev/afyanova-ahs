<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use App\Modules\Platform\Infrastructure\Models\AuditExportRetryResumeTelemetryEventModel;
use App\Modules\Platform\Infrastructure\Models\CrossTenantAdminAuditLogHoldModel;
use App\Modules\Platform\Infrastructure\Models\CrossTenantAdminAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use App\Support\CatalogGovernance\CatalogPlacementAuditor;
use App\Support\CatalogGovernance\EmptyDiagnosisCatalogAuditor;
use Database\Seeders\Support\BaselineDepartmentCatalog;
use Database\Seeders\Support\FacilitySubscriptionBootstrap;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('catalog:audit-placement {--fix : Repair safe inventory placement issues} {--json : Output machine-readable JSON}', function (CatalogPlacementAuditor $auditor): int {
    $result = $this->option('fix')
        ? $auditor->repairInventoryPlacement()
        : ['findings' => $auditor->auditInventoryItems()];

    if (! $this->option('fix')) {
        $auditor->writeAuditFindings($result['findings'] ?? []);
    }

    if ($this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    $findings = $this->option('fix')
        ? ($result['after'] ?? [])
        : ($result['findings'] ?? []);

    $this->info(sprintf(
        'Catalog placement audit completed: %d finding(s)%s.',
        count($findings),
        $this->option('fix') ? sprintf(', %d repair action(s)', count($result['repairs'] ?? [])) : '',
    ));

    if ($findings !== []) {
        $this->table(
            ['Issue', 'Severity', 'Module', 'Source', 'Summary'],
            array_map(
                static fn (array $finding): array => [
                    $finding['issueCode'] ?? '',
                    $finding['severity'] ?? '',
                    $finding['module'] ?? '',
                    trim((string) ($finding['sourceTable'] ?? '').':'.(string) ($finding['sourceId'] ?? ''), ':'),
                    $finding['summary'] ?? '',
                ],
                $findings,
            ),
        );
    }

    return self::SUCCESS;
})->purpose('Audit catalog, price list, and inventory placement integrity');

// C-10 (reports/clinical-note-audit/15-critical-system-integrity-review.md),
// Option C (decided, reports/clinical-note-audit/16-remediation-options-c8-c9-c10-c12.md):
// flag any facility whose diagnosis-terminology catalog is empty, so the
// misconfiguration that makes diagnosis validation silently permissive is
// caught at its source rather than only visible per-request (Option B).
Artisan::command('medical-records:audit-empty-diagnosis-catalogs {--json : Output machine-readable JSON}', function (EmptyDiagnosisCatalogAuditor $auditor): int {
    $findings = $auditor->audit();
    $auditor->writeAuditFindings($findings);

    if ($this->option('json')) {
        $this->line(json_encode(['findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    $this->info(sprintf('Empty diagnosis catalog audit completed: %d facility/facilities flagged.', count($findings)));

    if ($findings !== []) {
        $this->table(
            ['Facility', 'Summary'],
            array_map(
                static fn (array $finding): array => [
                    trim((string) ($finding['payload']['facilityCode'] ?? '').' '.(string) ($finding['payload']['facilityName'] ?? '')),
                    $finding['summary'] ?? '',
                ],
                $findings,
            ),
        );
    }

    return self::SUCCESS;
})->purpose('Flag any facility whose diagnosis-terminology catalog has zero active entries');

// --- Inventory Scheduled Commands ---
Schedule::command('inventory:check-expiring-batches --quarantine-expired')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/inventory-expiry-check.log'));

Schedule::command('inventory:auto-reorder')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/inventory-auto-reorder.log'));

Schedule::command('inventory:expire-warehouse-transfer-reservations --json')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/inventory-transfer-hold-expiry.log'));

// Phase 1: Department-Level RBAC - Temporal Access Expiry
Schedule::command('inventory:revoke-expired-access-roles')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/inventory-access-expiry.log'));

if (! function_exists('billingPermissionProfile')) {
    /**
     * @return array<int, string>
     */
    function billingPermissionProfile(string $profile): array
    {
        $profiles = (array) config('billing_permissions.profiles', []);
        $resolved = $profiles[$profile] ?? null;

        if (! is_array($resolved)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $resolved
        ))));
    }
}

if (! function_exists('defaultHospitalRoleDisplayNames')) {
    /**
     * @return array<string, string>
     */
    function defaultHospitalRoleDisplayNames(): array
    {
        return [
            'PLATFORM.SUPER.ADMIN' => 'System Super Admin',
            'PLATFORM.USER.ADMIN' => 'Platform User Administrator',
            'PLATFORM.RBAC.ADMIN' => 'Platform RBAC Administrator',
            'PLATFORM.SUBSCRIPTION.ADMIN' => 'Platform Subscription Administrator',
            'ADMIN.FACILITY' => 'Facility Administrator',
            'ADMIN.HR' => 'Human Resources Administrator',
            'ADMIN.REGISTRATION' => 'Registration Clerk',
            'ADMIN.MEDICAL.RECORDS' => 'Medical Records Officer',
            'CLINICAL.PHYSICIAN' => 'Physician',
            'CLINICAL.NURSE' => 'Nurse',
            'CLINICAL.EMERGENCY' => 'Emergency & Triage',
            'CLINICAL.GENERAL' => 'Clinical User',
            'LAB.STAFF' => 'Laboratory Staff',
            'LAB.SUPERVISOR' => 'Laboratory Supervisor',
            'LAB.MANAGER' => 'Laboratory Manager',
            'RADIOLOGY.STAFF' => 'Radiology Staff',
            'RADIOLOGY.SUPERVISOR' => 'Radiology Supervisor',
            'RADIOLOGY.MANAGER' => 'Radiology Manager',
            'PHARMACY.STAFF' => 'Pharmacy Staff',
            'PHARMACY.SUPERVISOR' => 'Pharmacy Supervisor',
            'PHARMACY.MANAGER' => 'Pharmacy Manager',
            'THEATRE.STAFF' => 'Theatre Staff',
            'THEATRE.SUPERVISOR' => 'Theatre Supervisor',
            'THEATRE.MANAGER' => 'Theatre Manager',
            'INVENTORY.STAFF' => 'Inventory Storekeeper',
            'INVENTORY.SUPERVISOR' => 'Inventory Supervisor',
            'INVENTORY.MANAGER' => 'Inventory Manager',
            'FINANCE.CASHIER' => 'Cashier',
            'FINANCE.OFFICER' => 'Billing Officer',
            'FINANCE.CONTROLLER' => 'Finance Controller',
            'FINANCE.CLAIMS' => 'Claims & Insurance User',
        ];
    }
}

if (! function_exists('defaultHospitalRolePermissionProfiles')) {
    /**
     * @return array<string, array<int, string>>
     */
    function defaultHospitalRolePermissionProfiles(): array
    {
        return [
            'PLATFORM.USER.ADMIN' => [
                'platform.users.read',
                'platform.users.create',
                'platform.users.update',
                'platform.users.update-status',
                'platform.users.manage-facilities',
                'platform.users.reset-password',
                'platform.users.view-audit-logs',
                'platform.users.approval-cases.read',
                'platform.users.approval-cases.create',
                'platform.users.approval-cases.manage',
                'platform.users.approval-cases.review',
                'platform.users.approval-cases.view-audit-logs',
                'platform.facilities.read',
                'platform.facilities.create',
                'platform.facilities.update',
                'platform.facilities.update-status',
                'platform.facilities.manage-owners',
                'platform.facilities.manage-subscriptions',
                'platform.facilities.view-audit-logs',
                'platform.subscription-plans.read',
                'platform.subscription-plans.manage',
                'platform.subscription-plans.view-audit-logs',
            ],
            'PLATFORM.RBAC.ADMIN' => [
                'platform.rbac.read',
                'platform.rbac.manage-roles',
                'platform.rbac.manage-user-roles',
                'platform.rbac.view-audit-logs',
                'platform.settings.manage-branding',
            ],
            'PLATFORM.SUBSCRIPTION.ADMIN' => [
                'platform.facilities.read',
                'platform.facilities.manage-subscriptions',
                'platform.facilities.view-audit-logs',
                'platform.subscription-plans.read',
                'platform.subscription-plans.manage',
                'platform.subscription-plans.view-audit-logs',
            ],
            'ADMIN.FACILITY' => [
                // Dedicated workspace-access permissions (2026-08-13) — a
                // facility administrator should retain oversight access to
                // every clinical workspace; granted explicitly here rather
                // than left to fall out of a broader operational permission
                // (which is exactly the bug these two permissions replace
                // for ADMIN.REGISTRATION/CLINICAL.NURSE above).
                'reception.access',
                'nursing.access',
                'patients.read',
                'patients.create',
                'patients.update',
                'admissions.read',
                'admissions.create',
                'admissions.update',
                'admissions.update-status',
                'admissions.view-audit-logs',
                'appointments.read',
                'medical.records.read',
                'medical.records.archive',
                'medical-records.view-audit-logs',
                'laboratory.orders.read',
                'pharmacy.orders.read',
                'radiology.orders.read',
                'clinical-procedure.orders.read',
                'theatre.procedures.read',
                'claims.insurance.read',
                'billing.invoices.read',
                'billing.payments.view-history',
                'billing.financial-controls.read',
                'service.requests.create',
                'service.requests.read',
                'service.requests.update-status',
                'service.requests.export',
                'service.requests.audit-logs.read',
                'pos.registers.read',
                'pos.sessions.read',
                'pos.sales.read',
                'pos.lab-quick.read',
                'pos.frontdesk-quick.read',
                'pos.cafeteria.read',
                'pos.pharmacy-otc.read',
                'inventory.procurement.read',
                'inventory.procurement.create-movement',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.view-audit-logs',
                'inventory.procurement.manage-suppliers',
                'inventory.procurement.manage-warehouses',
                'inpatient.ward.read',
                'staff.read',
                'staff.attendance.read',
                'staff.attendance.update',
                'staff.clinical-directory.read',
                'staff.view-audit-logs',
                'staff.documents.read',
                'staff.credentialing.read',
                'staff.privileges.read',
                'staff.create',
                'staff.update',
                'staff.update-status',
                'staff.specialties.read',
                'staff.specialties.manage',
                'specialties.read',
                'specialties.create',
                'specialties.update',
                'specialties.update-status',
                'specialties.view-audit-logs',
                'departments.read',
                'departments.create',
                'departments.update',
                'departments.update-status',
                'departments.view-audit-logs',
                'platform.resources.read',
                'platform.resources.manage-service-points',
                'platform.resources.manage-ward-beds',
                'platform.resources.manage-observation-rooms',
                'platform.resources.view-audit-logs',
                'platform.clinical-catalog.read',
                'platform.clinical-catalog.manage-lab-tests',
                'platform.clinical-catalog.manage-radiology-procedures',
                'platform.clinical-catalog.manage-theatre-procedures',
                'platform.clinical-catalog.manage-clinical-procedures',
                'platform.clinical-catalog.manage-formulary',
                'platform.clinical-catalog.view-audit-logs',
                'billing.service-catalog.read',
                'billing.service-catalog.manage-identity',
                'billing.service-catalog.manage-pricing',
                'billing.service-catalog.view-audit-logs',
                'platform.users.read',
                'platform.users.create',
                'platform.users.update',
                'platform.users.update-status',
                'platform.users.reset-password',
                'platform.users.view-audit-logs',
                'platform.users.manage-facilities',
                'platform.users.approval-cases.read',
                'platform.users.approval-cases.create',
                'platform.users.approval-cases.manage',
                'platform.users.approval-cases.review',
                'platform.users.approval-cases.view-audit-logs',
                'platform.rbac.read',
                'platform.rbac.manage-user-roles',
            ],
            'ADMIN.HR' => [
                'staff.read',
                'staff.attendance.read',
                'staff.attendance.update',
                'staff.create',
                'staff.update',
                'staff.update-status',
                'staff.view-audit-logs',
                'staff.documents.read',
                'staff.documents.create',
                'staff.documents.update',
                'staff.documents.verify',
                'staff.documents.update-status',
                'staff.documents.view-audit-logs',
                'staff.credentialing.read',
                'staff.credentialing.manage-profile',
                'staff.credentialing.manage-registrations',
                'staff.credentialing.verify',
                'staff.credentialing.view-audit-logs',
                'staff.privileges.read',
                'staff.privileges.create',
                'staff.privileges.update',
                'staff.privileges.review',
                'staff.privileges.approve',
                'staff.privileges.update-status',
                'staff.privileges.view-audit-logs',
                'staff.specialties.read',
                'specialties.read',
                'departments.read',
            ],
            'ADMIN.REGISTRATION' => [
                // Dedicated workspace-access permission (Volume 2.1 §13,
                // added 2026-08-13) — the blueprint always named
                // `reception.access` as the required permission; it just
                // never existed as a real row until now. Added after a nurse
                // was found able to open the full Reception workspace via
                // the operational `patients.create` permission both roles
                // happened to share — gates the /reception route and its nav
                // icon on its own dedicated permission instead of overloading
                // an action permission as a page-access check.
                'reception.access',
                'patients.read',
                'patients.create',
                'patients.update',
                // Volume 2.1 §8.3 (Reception workspace "Edit demographics"):
                // reception can already create this same data at registration
                // (patients.create) — added 2026-08-10 so corrections don't
                // require a clinical role, per explicit user decision after
                // flagging the gap (Volume 3.7 audit).
                'patient.demographics.update',
                'admissions.read',
                'admissions.create',
                'admissions.update',
                'appointments.read',
                'appointments.create',
                'appointments.update',
                'appointments.update-status',
                // Volume 2.1 §10.1 (Arrival intake — walk-in/scheduled/
                // emergency): gates POST /reception/walk-ins,
                // PATCH /appointments/{id}/check-in, and
                // POST /reception/queue/{id}/cancel — all three would 403
                // for a real Registration Clerk without it. A 2026-07-16
                // migration (2026_07_16_000002_insert_workflow_permissions)
                // already paired ADMIN.REGISTRATION with this exact
                // permission; this sync-command list had drifted from it.
                // Fixed 2026-08-10 (Volume 3.7 audit) to match that intent.
                'appointment.check-in',
                'service.requests.create',
                'service.requests.read',
                'staff.clinical-directory.read',
                // Volume 2.1 §8.1/§16 #10 (Insurance add/verify UI) — decided
                // 2026-08-11: capturing/updating a patient's insurance is a
                // registration-desk task in most front-desk workflows (often
                // captured at the exact moment of arrival), not a Billing-only
                // function — matches how patient.demographics.update was
                // granted above for the same "reception already creates this
                // data at registration" reasoning. Gates the reception-scoped
                // POST/PATCH insurance routes; PatientInsuranceController's
                // read/index routes are unused by Reception (insurance
                // *display* already works through the summary endpoint's own
                // `patients.read` gate, unaffected by this addition).
                'patients.insurance.manage',
                'patients.insurance.verify',
            ],
            'ADMIN.MEDICAL.RECORDS' => [
                'patients.read',
                'medical.records.read',
                'medical.records.archive',
                'medical-records.view-audit-logs',
            ],
            'CLINICAL.PHYSICIAN' => [
                'patients.read',
                'patients.create',
                'patients.update',
                'appointments.read',
                'appointments.create',
                'admissions.read',
                'admissions.create',
                'medical.records.read',
                'medical.records.create',
                'medical.records.draft.update',
                'medical.records.finalize',
                'medical.records.amend',
                'medical.records.attest',
                'laboratory.access',
                'pharmacy.access',
                'patient.demographics.update',
                'patient.allergies.manage',
                'patient.medications.manage',
                'patient.vitals.record',
                'lab.order',
                'medication.prescribe',
                'imaging.order',
                'clinical-procedure.order',
                'admissions.update-status',
                'laboratory.orders.read',
                'pharmacy.orders.read',
                'radiology.orders.read',
                'clinical-procedure.orders.read',
            ],
            'CLINICAL.NURSE' => [
                // Dedicated workspace-access permission (Volume 2.3 §13,
                // added 2026-08-13) — same reasoning as ADMIN.REGISTRATION's
                // `reception.access` above: the blueprint always named
                // `nursing.access`, it just never existed as a real row.
                // Replaces `inpatient.ward.read` (an operational permission,
                // not a page-access one) as the /nursing route + nav gate.
                'nursing.access',
                'patients.read',
                'service.requests.create',
                'service.requests.read',
                'admissions.read',
                'appointments.read',
                'medical.records.read',
                // Added 2026-08-13 (Volume 3.8 §6 #2) — neither was ever granted
                // to CLINICAL.NURSE, not even in the original permission-seeding
                // migration (2026_07_16_000002_insert_workflow_permissions.php,
                // which scoped patient.vitals.record to
                // ADMIN.FACILITY/CLINICAL.PHYSICIAN/CLINICAL.GENERAL/
                // CLINICAL.EMERGENCY only). Confirmed this made the role
                // non-functional for its own core task: `POST /nursing/vitals`
                // and `POST /nursing/notes` both require these, and a ward nurse
                // unable to record vital signs or write a nursing note isn't a
                // narrower role, it's a broken one. Treated as the same class of
                // accidental gap as Reception's own permission-drift fixes this
                // session (§16 #13/#14 in Volume 3.7), just without a migration
                // to point to as proof — a deliberate decision, not a rediscovered
                // one, see Volume 3.8 §6 #2 for the full reasoning.
                'patient.vitals.record',
                'medical.records.create',
                // Added 2026-08-13 (Volume 3.8 Phase 6) — same class of gap
                // as patient.vitals.record/medical.records.create above:
                // `GET /nursing/mar` requires this, and a ward nurse unable
                // to even view a patient's medication list is a broken
                // role, not a narrower one. Found via a live 403, not
                // assumed — Volume 3.8's own baseline had claimed this
                // endpoint was "confirmed real" without actually checking
                // against a real permission set.
                'pharmacy.orders.read',
                'inpatient.ward.read',
                'inpatient.ward.create-task',
                'inpatient.ward.update-task-status',
                'inpatient.ward.create-care-plan',
                'inpatient.ward.update-care-plan',
                'inpatient.ward.update-care-plan-status',
                'inpatient.ward.manage-discharge-checklist',
                'inpatient.ward.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'staff.clinical-directory.read',
            ],
            'CLINICAL.EMERGENCY' => [
                'patients.read',
                'appointments.read',
                'admissions.read',
                'medical.records.read',
                'medical.records.create',
                'medical.records.update',
                'medical.records.finalize',
                'medical.records.amend',
                'medical.records.attest',
                'emergency.triage.read',
                'emergency.triage.create',
                'emergency.triage.update',
                'emergency.triage.update-status',
                'emergency.triage.view-audit-logs',
                'emergency.triage.manage-transfers',
                'emergency.triage.view-transfer-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'staff.clinical-directory.read',
            ],
            'CLINICAL.GENERAL' => [
                'patients.read',
                'patients.create',
                'patients.update',
                'appointments.read',
                'appointments.create',
                'admissions.read',
                'medical.records.read',
                'medical.records.create',
                'medical.records.draft.update',
                'medical.records.finalize',
                'medical.records.amend',
                'laboratory.access',
                'pharmacy.access',
                'patient.demographics.update',
                'patient.medications.manage',
                'patient.vitals.record',
                'lab.order',
                'medication.prescribe',
                'imaging.order',
                'clinical-procedure.order',
                'laboratory.orders.read',
                'pharmacy.orders.read',
                'radiology.orders.read',
                'clinical-procedure.orders.read',
            ],
            'LAB.STAFF' => [
                'laboratory.orders.read',
                'laboratory.orders.update-status',
                'service.requests.read',
                'service.requests.update-status',
                'laboratory.orders.verify-result',
                'laboratory-orders.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'pos.registers.read',
                'pos.sessions.read',
                'pos.sessions.manage',
                'pos.sales.read',
                'pos.sales.create',
                'pos.lab-quick.read',
                'pos.lab-quick.create',
            ],
            'LAB.SUPERVISOR' => [
                'laboratory.orders.read',
                'laboratory.orders.update-status',
                'service.requests.read',
                'service.requests.update-status',
                'laboratory.orders.verify-result',
                'laboratory-orders.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.create-movement',
                'pos.registers.read',
                'pos.sessions.read',
                'pos.sessions.manage',
                'pos.sales.read',
                'pos.sales.create',
                'pos.lab-quick.read',
                'pos.lab-quick.create',
            ],
            'LAB.MANAGER' => [
                'laboratory.orders.read',
                'laboratory.orders.update-status',
                'service.requests.read',
                'service.requests.update-status',
                'laboratory.orders.verify-result',
                'laboratory-orders.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.create-movement',
                'inventory.procurement.manage-items',
                'inventory.procurement.view-audit-logs',
                'pos.registers.read',
                'pos.sessions.read',
                'pos.sessions.manage',
                'pos.sales.read',
                'pos.sales.create',
                'pos.lab-quick.read',
                'pos.lab-quick.create',
            ],
            'RADIOLOGY.STAFF' => [
                'radiology.orders.read',
                'service.requests.read',
                'service.requests.update-status',
                'radiology.orders.update',
                'radiology.orders.update-status',
                'radiology.orders.view-audit-logs',
                'clinical-procedure.orders.read',
                'clinical-procedure.orders.update',
                'clinical-procedure.orders.update-status',
                'clinical-procedure.orders.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
            ],
            'RADIOLOGY.SUPERVISOR' => [
                'radiology.orders.read',
                'service.requests.read',
                'service.requests.update-status',
                'radiology.orders.update',
                'radiology.orders.update-status',
                'radiology.orders.view-audit-logs',
                'clinical-procedure.orders.read',
                'clinical-procedure.orders.update',
                'clinical-procedure.orders.update-status',
                'clinical-procedure.orders.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
            ],
            'RADIOLOGY.MANAGER' => [
                'radiology.orders.read',
                'service.requests.read',
                'service.requests.update-status',
                'radiology.orders.update',
                'radiology.orders.update-status',
                'radiology.orders.view-audit-logs',
                'clinical-procedure.orders.read',
                'clinical-procedure.orders.update',
                'clinical-procedure.orders.update-status',
                'clinical-procedure.orders.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.manage-items',
                'inventory.procurement.view-audit-logs',
            ],
            'PHARMACY.STAFF' => [
                'patients.read',
                'service.requests.read',
                'service.requests.update-status',
                'pharmacy.orders.read',
                'pharmacy.orders.update-status',
                'pharmacy.orders.verify-dispense',
                'pharmacy.orders.manage-policy',
                'pharmacy.orders.reconcile',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'pharmacy-orders.view-audit-logs',
                'pos.registers.read',
                'pos.sessions.read',
                'pos.sessions.manage',
                'pos.sales.read',
                'pos.sales.create',
                'pos.pharmacy-otc.read',
                'pos.pharmacy-otc.create',
            ],
            'PHARMACY.SUPERVISOR' => [
                'patients.read',
                'service.requests.read',
                'service.requests.update-status',
                'pharmacy.orders.read',
                'pharmacy.orders.update-status',
                'pharmacy.orders.verify-dispense',
                'pharmacy.orders.manage-policy',
                'pharmacy.orders.reconcile',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.view-audit-logs',
                'pharmacy-orders.view-audit-logs',
                'pos.registers.read',
                'pos.sessions.read',
                'pos.sessions.manage',
                'pos.sales.read',
                'pos.sales.create',
                'pos.pharmacy-otc.read',
                'pos.pharmacy-otc.create',
            ],
            'PHARMACY.MANAGER' => [
                'patients.read',
                'service.requests.read',
                'service.requests.update-status',
                'pharmacy.orders.read',
                'pharmacy.orders.update-status',
                'pharmacy.orders.verify-dispense',
                'pharmacy.orders.manage-policy',
                'pharmacy.orders.reconcile',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.create-movement',
                'inventory.procurement.manage-items',
                'inventory.procurement.manage-suppliers',
                'inventory.procurement.view-audit-logs',
                'pharmacy-orders.view-audit-logs',
                'pos.registers.read',
                'pos.sessions.read',
                'pos.sessions.manage',
                'pos.sales.read',
                'pos.sales.create',
                'pos.pharmacy-otc.read',
                'pos.pharmacy-otc.create',
            ],
            'THEATRE.STAFF' => [
                'theatre.procedures.read',
                'theatre.procedures.create',
                'theatre.procedures.update',
                'theatre.procedures.update-status',
                'theatre.procedures.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'staff.clinical-directory.read',
            ],
            'THEATRE.SUPERVISOR' => [
                'theatre.procedures.read',
                'theatre.procedures.create',
                'theatre.procedures.update',
                'theatre.procedures.update-status',
                'theatre.procedures.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'staff.clinical-directory.read',
            ],
            'THEATRE.MANAGER' => [
                'theatre.procedures.read',
                'theatre.procedures.create',
                'theatre.procedures.update',
                'theatre.procedures.update-status',
                'theatre.procedures.view-audit-logs',
                'inventory.procurement.read',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.manage-items',
                'inventory.procurement.view-audit-logs',
                'staff.clinical-directory.read',
            ],
            'INVENTORY.STAFF' => [
                'inventory.procurement.read',
                'inventory.procurement.create-movement',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.view-audit-logs',
            ],
            'INVENTORY.SUPERVISOR' => [
                'inventory.procurement.read',
                'inventory.procurement.manage-items',
                'inventory.procurement.create-movement',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.view-audit-logs',
                'inventory.procurement.manage-suppliers',
                'inventory.procurement.manage-item-units',
                'inventory.procurement.manage-unit-prices',
            ],
            'INVENTORY.MANAGER' => [
                'inventory.procurement.read',
                'inventory.procurement.manage-items',
                'inventory.procurement.create-movement',
                'inventory.procurement.create-request',
                'inventory.procurement.update-request-status',
                'inventory.procurement.reconcile-stock',
                'inventory.procurement.manage-warehouses',
                'inventory.procurement.manage-suppliers',
                'inventory.procurement.manage-item-units',
                'inventory.procurement.manage-unit-prices',
                'inventory.procurement.view-audit-logs',
            ],
            'FINANCE.CASHIER' => [
                'patients.read',
                'billing.invoices.read',
                'billing.payments.record',
                'billing.payments.view-history',
                'billing.cash-accounts.read',
                'billing.cash-accounts.manage',
                'billing.refunds.create',
                'billing.refunds.read',
                'pos.registers.read',
                'pos.sessions.read',
                'pos.sessions.manage',
                'pos.sales.read',
                'pos.sales.create',
                'pos.lab-quick.read',
                'pos.lab-quick.create',
                'pos.frontdesk-quick.read',
                'pos.frontdesk-quick.create',
                'pos.cafeteria.read',
                'pos.cafeteria.create',
                'pos.pharmacy-otc.read',
                'pos.pharmacy-otc.create',
            ],
            'FINANCE.OFFICER' => [
                'patients.read',
                'departments.read',
                'billing.invoices.create',
                'billing.invoices.read',
                'billing.invoices.issue',
                'billing.invoices.update-draft',
                'billing.invoices.cancel',
                'billing.payments.view-history',
                'billing.service-catalog.read',
                'billing.service-catalog.manage-identity',
                'billing.payer-contracts.read',
                'billing.routing.read',
                'billing.discounts.read',
                'billing.refunds.read',
                'billing.refunds.create',
                'pos.registers.read',
                'pos.registers.manage',
                'pos.sessions.read',
                'pos.sales.read',
                'pos.lab-quick.read',
                'pos.frontdesk-quick.read',
                'pos.cafeteria.read',
                'pos.cafeteria.manage-catalog',
                'pos.pharmacy-otc.read',
            ],
            'FINANCE.CONTROLLER' => [
                'patients.read',
                'departments.read',
                'billing.invoices.read',
                'billing.financial-controls.read',
                'billing.invoices.void',
                'billing-invoices.view-audit-logs',
                'billing.payments.reverse',
                'billing.payments.view-history',
                'billing.service-catalog.read',
                'billing.service-catalog.manage-pricing',
                'billing.service-catalog.view-audit-logs',
                'billing.payer-contracts.read',
                'billing.payer-contracts.view-audit-logs',
                'billing.payer-contracts.manage-price-overrides',
                'billing.payer-contracts.view-price-override-audit-logs',
                'billing.routing.read',
                'billing.discounts.read',
                'billing.discounts.manage',
                'billing.refunds.read',
                'billing.refunds.approve',
                'billing.refunds.process',
                'pos.registers.read',
                'pos.registers.manage',
                'pos.sessions.read',
                'pos.sales.read',
                'pos.sales.void',
                'pos.sales.refund',
                'pos.lab-quick.read',
                'pos.cafeteria.read',
                'pos.cafeteria.manage-catalog',
                'pos.pharmacy-otc.read',
            ],
            'FINANCE.CLAIMS' => [
                'patients.read',
                'claims.insurance.read',
                'claims.insurance.create',
                'claims.insurance.update',
                'claims.insurance.update-status',
                'claims.insurance.view-audit-logs',
            ],
        ];
    }
}

Artisan::command('app:bootstrap-super-admin {--email=admin@local.test} {--name=} {--password=} {--show-password}', function (): int {
    $email = trim((string) $this->option('email'));
    $nameOption = trim((string) $this->option('name'));
    $passwordOption = $this->option('password');
    $hasExplicitPassword = is_string($passwordOption) && trim($passwordOption) !== '';
    $systemSuperAdminRoleAssigned = false;

    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Provide a valid --email value.');

        return 1;
    }

    $allPermissions = [
        'patients.read',
        'patients.create',
        'patients.view-audit-logs',
        'patients.update',
        'patients.update-status',
        'appointments.view-audit-logs',
        'appointments.read',
        'appointments.create',
        'appointments.update',
        'appointments.update-status',
        'appointments.manage-referrals',
        'appointments.view-referral-audit-logs',
        'admissions.read',
        'admissions.create',
        'admissions.update',
        'admissions.update-status',
        'admissions.view-audit-logs',
        'medical-records.view-audit-logs',
        'medical.records.read',
        'medical.records.create',
        'medical.records.update',
        'medical.records.finalize',
        'medical.records.amend',
        'medical.records.archive',
        'medical.records.attest',
        'laboratory-orders.view-audit-logs',
        'laboratory.orders.create',
        'laboratory.orders.read',
        'laboratory.orders.update-status',
        'laboratory.orders.verify-result',
        'radiology.orders.read',
        'radiology.orders.create',
        'radiology.orders.update',
        'radiology.orders.update-status',
        'radiology.orders.view-audit-logs',
        'clinical-procedure.orders.read',
        'clinical-procedure.orders.create',
        'clinical-procedure.orders.update',
        'clinical-procedure.orders.update-status',
        'clinical-procedure.orders.view-audit-logs',
        'emergency.triage.read',
        'emergency.triage.create',
        'emergency.triage.update',
        'emergency.triage.update-status',
        'emergency.triage.view-audit-logs',
        'emergency.triage.manage-transfers',
        'emergency.triage.view-transfer-audit-logs',
        'claims.insurance.read',
        'claims.insurance.create',
        'claims.insurance.update',
        'claims.insurance.update-status',
        'claims.insurance.view-audit-logs',
        'inventory.procurement.read',
        'inventory.procurement.manage-items',
        'inventory.procurement.create-movement',
        'inventory.procurement.reconcile-stock',
        'inventory.procurement.create-request',
        'inventory.procurement.update-request-status',
        'inventory.procurement.view-audit-logs',
        'inventory.procurement.manage-suppliers',
        'inventory.procurement.manage-warehouses',
        'theatre.procedures.read',
        'theatre.procedures.create',
        'theatre.procedures.update',
        'theatre.procedures.update-status',
        'theatre.procedures.view-audit-logs',
        'theatre.procedures.manage-resources',
        'theatre.procedures.view-resource-audit-logs',
        'service.requests.create',
        'service.requests.read',
        'service.requests.update-status',
        'service.requests.export',
        'service.requests.audit-logs.read',
        'inpatient.ward.read',
        'inpatient.ward.create-task',
        'inpatient.ward.update-task-status',
        'inpatient.ward.create-round-note',
        'inpatient.ward.create-care-plan',
        'inpatient.ward.update-care-plan',
        'inpatient.ward.update-care-plan-status',
        'inpatient.ward.manage-discharge-checklist',
        'inpatient.ward.view-audit-logs',
        'billing-invoices.view-audit-logs',
        'billing.invoices.create',
        'billing.invoices.read',
        'billing.invoices.issue',
        'billing.invoices.update-draft',
        'billing.invoices.cancel',
        'billing.invoices.void',
        'billing.payments.record',
        'billing.payments.view-history',
        'billing.payments.reverse',
        'billing.cash-accounts.read',
        'billing.cash-accounts.manage',
        'billing.routing.read',
        'billing.discounts.read',
        'billing.discounts.manage',
        'billing.refunds.read',
        'billing.refunds.create',
        'billing.refunds.approve',
        'billing.refunds.process',
        'billing.financial-controls.read',
        'billing.service-catalog.read',
        'billing.service-catalog.manage',
        'billing.service-catalog.view-audit-logs',
        'billing.payer-contracts.read',
        'billing.payer-contracts.manage',
        'billing.payer-contracts.view-audit-logs',
        'billing.payer-contracts.manage-price-overrides',
        'billing.payer-contracts.view-price-override-audit-logs',
        'billing.payer-contracts.manage-authorization-rules',
        'billing.payer-contracts.view-authorization-audit-logs',
        'pos.registers.read',
        'pos.registers.manage',
        'pos.sessions.read',
        'pos.sessions.manage',
        'pos.sales.read',
        'pos.sales.create',
        'pos.sales.void',
        'pos.sales.refund',
        'pos.lab-quick.read',
        'pos.lab-quick.create',
        'pos.cafeteria.read',
        'pos.cafeteria.create',
        'pos.cafeteria.manage-catalog',
        'pos.pharmacy-otc.read',
        'pos.pharmacy-otc.create',
        'pos.frontdesk-quick.read',
        'pos.frontdesk-quick.create',
        'pharmacy-orders.view-audit-logs',
        'pharmacy.orders.create',
        'pharmacy.orders.read',
        'pharmacy.orders.update-status',
        'pharmacy.orders.verify-dispense',
        'pharmacy.orders.manage-policy',
        'pharmacy.orders.reconcile',
        'staff.read',
        'staff.clinical-directory.read',
        'staff.create',
        'staff.update',
        'staff.update-status',
        'staff.view-audit-logs',
        'staff.documents.read',
        'staff.documents.create',
        'staff.documents.update',
        'staff.documents.verify',
        'staff.documents.update-status',
        'staff.documents.view-audit-logs',
        'staff.credentialing.read',
        'staff.credentialing.manage-profile',
        'staff.credentialing.manage-registrations',
        'staff.credentialing.verify',
        'staff.credentialing.view-audit-logs',
        'staff.privileges.read',
        'staff.privileges.create',
        'staff.privileges.update',
        'staff.privileges.review',
        'staff.privileges.approve',
        'staff.privileges.update-status',
        'staff.privileges.view-audit-logs',
        'specialties.read',
        'specialties.create',
        'specialties.update',
        'specialties.update-status',
        'specialties.view-audit-logs',
        'staff.specialties.read',
        'staff.specialties.manage',
        'departments.read',
        'departments.create',
        'departments.update',
        'departments.update-status',
        'departments.view-audit-logs',
        'platform.rbac.read',
        'platform.rbac.manage-roles',
        'platform.rbac.manage-user-roles',
        'platform.rbac.view-audit-logs',
        'platform.users.read',
        'platform.users.create',
        'platform.users.update',
        'platform.users.update-status',
        'platform.users.manage-facilities',
        'platform.users.reset-password',
        'platform.users.view-audit-logs',
        'platform.users.approval-cases.read',
        'platform.users.approval-cases.create',
        'platform.users.approval-cases.manage',
        'platform.users.approval-cases.review',
        'platform.users.approval-cases.view-audit-logs',
        'platform.resources.read',
        'platform.resources.manage-service-points',
        'platform.resources.manage-ward-beds',
        'platform.resources.manage-observation-rooms',
        'platform.resources.view-audit-logs',
        'platform.clinical-catalog.read',
        'platform.clinical-catalog.manage-lab-tests',
        'platform.clinical-catalog.manage-radiology-procedures',
        'platform.clinical-catalog.manage-theatre-procedures',
        'platform.clinical-catalog.manage-clinical-procedures',
        'platform.clinical-catalog.manage-formulary',
        'platform.clinical-catalog.view-audit-logs',
        'platform.facilities.read',
        'platform.facilities.create',
        'platform.facilities.update',
        'platform.facilities.update-status',
        'platform.facilities.manage-owners',
        'platform.facilities.manage-subscriptions',
        'platform.facilities.view-audit-logs',
        'platform.subscription-plans.read',
        'platform.subscription-plans.manage',
        'platform.subscription-plans.view-audit-logs',
        'platform.settings.manage-branding',
        'platform.feature-flag-overrides.manage',
        'platform.feature-flag-overrides.view-audit-logs',
        'platform.cross-tenant.read',
        'platform.cross-tenant.write',
        'platform.cross-tenant.view-audit-logs',
        'platform.cross-tenant.view-audit-holds',
        'platform.cross-tenant.manage-audit-holds',
        'platform.multi-facility.read',
        'platform.multi-facility.manage-rollouts',
        'platform.multi-facility.manage-incidents',
        'platform.multi-facility.execute-rollback',
        'platform.multi-facility.approve-acceptance',
        'platform.multi-facility.view-audit-logs',
    ];

    try {
        $user = User::query()->firstOrNew(['email' => $email]);
        $wasExisting = $user->exists;
        $generatedPassword = false;
        $rotatedExistingPassword = false;
        $resolvedPassword = null;
        $name = $nameOption !== ''
            ? $nameOption
            : ($wasExisting ? trim((string) $user->name) : 'Super Admin');

        if ($name === '') {
            $this->error('Provide a non-empty --name value.');

            return 1;
        }

        $user->name = $name;
        if ($hasExplicitPassword) {
            $resolvedPassword = trim((string) $passwordOption);
            $user->password = $resolvedPassword;
        } else {
            $generatedPassword = true;
            $rotatedExistingPassword = $wasExisting;
            $resolvedPassword = Str::password(20);
            $user->password = $resolvedPassword;
        }
        $user->email_verified_at = now();
        $user->is_platform_admin = true;
        $user->save();

        foreach ($allPermissions as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName]);
            $user->givePermissionTo($permissionName);
        }

        $systemSuperAdminRole = RoleModel::query()
            ->whereNull('tenant_id')
            ->whereNull('facility_id')
            ->where('code', 'PLATFORM.SUPER.ADMIN')
            ->first();

        if (! $systemSuperAdminRole instanceof RoleModel) {
            $systemSuperAdminRole = RoleModel::query()->create([
                'tenant_id' => null,
                'facility_id' => null,
                'code' => 'PLATFORM.SUPER.ADMIN',
                'name' => 'System Super Admin',
                'status' => 'active',
                'description' => 'Unrestricted platform owner role for bootstrap and emergency administration.',
                'is_system' => true,
            ]);
        }

        $permissionIds = Permission::query()
            ->whereIn('name', $allPermissions)
            ->pluck('id')
            ->all();

        $systemSuperAdminRole->permissions()->syncWithoutDetaching($permissionIds);
        $user->roles()->syncWithoutDetaching([$systemSuperAdminRole->id]);
        $systemSuperAdminRoleAssigned = true;
    } catch (QueryException $exception) {
        $this->error('Unable to create/update super admin. Ensure PostgreSQL is running, the database exists, and migrations are applied.');
        $this->line('Database error: '.$exception->getCode());

        return 1;
    }

    $this->info(($wasExisting ? 'Updated' : 'Created').' super admin user.');
    $this->line('Email: '.$user->email);
    $this->line('Name: '.$user->name);
    $this->line('User ID: '.$user->id);
    $this->line('Email verified: yes');
    $this->line('Granted permissions: '.count($allPermissions));
    $this->line('System super admin role: '.($systemSuperAdminRoleAssigned ? 'assigned' : 'not assigned'));

    if ($generatedPassword && is_string($resolvedPassword) && $resolvedPassword !== '') {
        $this->warn('Password: '.$resolvedPassword);
        if ($rotatedExistingPassword) {
            $this->line('Password was rotated for the existing account. Store this new password now.');
        } else {
            $this->line('Password was generated automatically. Store it now.');
        }
    } elseif ($hasExplicitPassword && (bool) $this->option('show-password')) {
        $this->warn('Password: '.trim((string) $passwordOption));
    } elseif ($hasExplicitPassword) {
        $this->line('Password was updated from --password (hidden).');
    }

    $this->line('Next: php artisan migrate (if not already run) and log in at /login');

    return 0;
})->purpose('Create or update a local super admin user for UI testing and grant all current gate-backed permissions');

Artisan::command('app:grant-system-super-admin {email}', function (string $email): int {
    $email = trim($email);

    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Provide a valid email address.');

        return self::FAILURE;
    }

    try {
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            $this->error('User not found: '.$email);

            return self::FAILURE;
        }

        $role = RoleModel::query()
            ->whereNull('tenant_id')
            ->whereNull('facility_id')
            ->where('code', 'PLATFORM.SUPER.ADMIN')
            ->first();

        if (! $role instanceof RoleModel) {
            $role = RoleModel::query()->create([
                'tenant_id' => null,
                'facility_id' => null,
                'code' => 'PLATFORM.SUPER.ADMIN',
                'name' => 'System Super Admin',
                'status' => 'active',
                'description' => 'Unrestricted platform owner role for bootstrap and emergency administration.',
                'is_system' => true,
            ]);
        } else {
            $role->forceFill([
                'name' => $role->name ?: 'System Super Admin',
                'status' => 'active',
                'is_system' => true,
            ])->save();
        }

        $permissionNames = Permission::query()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $permissionIds = Permission::query()
            ->orderBy('name')
            ->pluck('id')
            ->all();

        $role->permissions()->syncWithoutDetaching($permissionIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        foreach ($permissionNames as $permissionName) {
            $user->givePermissionTo((string) $permissionName);
        }

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
            $user->save();
        }
    } catch (QueryException $exception) {
        $this->error('Unable to grant system super admin access.');
        $this->line('Database error: '.$exception->getCode());

        return self::FAILURE;
    }

    $this->info('System super admin access granted.');
    $this->line('Email: '.$user->email);
    $this->line('Role: PLATFORM.SUPER.ADMIN');
    $this->line('Direct permissions synced: '.count($permissionNames));
    $this->line('Password: unchanged');

    return self::SUCCESS;
})->purpose('Grant true system super admin access to an existing user without changing the password');

Artisan::command('app:sync-billing-permissions {--profile=implemented} {--grant-user-email=} {--list}', function (): int {
    $profile = trim((string) $this->option('profile'));
    $profile = $profile !== '' ? $profile : 'implemented';

    /** @var array<string, mixed> $profiles */
    $profiles = (array) config('billing_permissions.profiles', []);
    $permissions = billingPermissionProfile($profile);

    if ($permissions === []) {
        $this->error('Unknown or empty Billing permission profile: '.$profile);
        $this->line('Available profiles: '.implode(', ', array_keys($profiles)));

        return 1;
    }

    if ((bool) $this->option('list')) {
        $this->info('Billing permission profile: '.$profile);
        foreach ($permissions as $permission) {
            $this->line('- '.$permission);
        }

        return 0;
    }

    $createdCount = 0;
    foreach ($permissions as $permissionName) {
        $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
        if ($permission->wasRecentlyCreated) {
            $createdCount++;
        }
    }

    $grantUserEmail = trim((string) $this->option('grant-user-email'));
    $grantedCount = 0;
    if ($grantUserEmail !== '') {
        $user = User::query()->where('email', $grantUserEmail)->first();
        if (! $user) {
            $this->error('User not found for --grant-user-email: '.$grantUserEmail);
            $this->line('Permissions were still synced.');

            return 1;
        }

        foreach ($permissions as $permissionName) {
            $user->givePermissionTo($permissionName);
            $grantedCount++;
        }
    }

    $this->info('Billing permissions synced.');
    $this->line('Profile: '.$profile);
    $this->line('Total in profile: '.count($permissions));
    $this->line('Newly created permissions: '.$createdCount);
    if ($grantUserEmail !== '') {
        $this->line('Granted to user: '.$grantUserEmail.' ('.$grantedCount.' permissions)');
    }

    return 0;
})->purpose('Create Billing permission IDs from a named profile and optionally grant them to a user');

Artisan::command('app:sync-default-role-permissions {--code=*} {--list}', function (): int {
    $profiles = defaultHospitalRolePermissionProfiles();
    $requestedCodes = array_values(array_filter(array_map(
        static fn ($value): string => Str::upper(trim((string) $value)),
        (array) $this->option('code')
    )));

    if ($requestedCodes !== []) {
        $profiles = array_intersect_key($profiles, array_flip($requestedCodes));

        if ($profiles === []) {
            $this->error('No matching role profiles found for the provided --code values.');

            return 1;
        }
    }

    if ((bool) $this->option('list')) {
        $this->info('Default role permission bundles');

        foreach ($profiles as $roleCode => $permissions) {
            $this->line($roleCode.' ('.count($permissions).' permissions)');
            foreach ($permissions as $permission) {
                $this->line('  - '.$permission);
            }
        }

        return 0;
    }

    $roleCodes = array_keys($profiles);
    $allPermissions = array_values(array_unique(array_merge(...array_values($profiles))));
    $createdPermissions = 0;
    $syncedRoles = [];
    $missingRoles = [];

    $roleDisplayNames = defaultHospitalRoleDisplayNames();

    DB::transaction(function () use ($allPermissions, $roleCodes, &$createdPermissions, &$syncedRoles, &$missingRoles, $profiles, $roleDisplayNames): void {
        $permissionIdsByName = [];

        foreach ($allPermissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            if ($permission->wasRecentlyCreated) {
                $createdPermissions++;
            }
            $permissionIdsByName[$permissionName] = $permission->id;
        }

        // Grouped by code, not keyBy (bug fixed 2026-08-13, Volume 3.8 §6
        // #2): a role code can have multiple real rows — one per facility,
        // sometimes plus a null-facility variant (confirmed live: 2 rows for
        // CLINICAL.NURSE, 3 for ADMIN.REGISTRATION). `keyBy` silently
        // collapsed that down to one arbitrary representative row, so only
        // one facility's copy of a role ever received a permission-profile
        // update — every other facility's copy went stale with no error.
        // Found while granting CLINICAL.NURSE a missing permission (only one
        // of its two rows actually got it); confirmed app-wide, not
        // role-specific, so fixed here rather than worked around per-role.
        $rolesByCode = RoleModel::query()
            ->whereIn('code', $roleCodes)
            ->get()
            ->groupBy(static fn (RoleModel $role): string => Str::upper((string) $role->code));

        foreach ($profiles as $roleCode => $permissionNames) {
            /** @var \Illuminate\Support\Collection<int, RoleModel> $roles */
            $roles = $rolesByCode->get($roleCode) ?? collect();

            if ($roles->isEmpty()) {
                $roleName = $roleDisplayNames[$roleCode]
                    ?? Str::of($roleCode)
                        ->afterLast('.')
                        ->replace('.', ' ')
                        ->replace('_', ' ')
                        ->title()
                        ->toString();

                $role = RoleModel::query()->create([
                    'code' => $roleCode,
                    'name' => $roleName,
                    'status' => 'active',
                    'description' => null,
                    'is_system' => false,
                ]);
                $roles = collect([$role]);
                $rolesByCode->put($roleCode, $roles);
                $missingRoles[] = $roleCode;
            }

            $permissionIds = array_values(array_map(
                static fn (string $permissionName): int => $permissionIdsByName[$permissionName],
                $permissionNames
            ));

            foreach ($roles as $role) {
                $role->permissions()->sync($permissionIds);
                $syncedRoles[] = [
                    'code' => $roleCode,
                    'name' => $role->name,
                    'facilityId' => $role->facility_id,
                    'count' => count($permissionIds),
                ];
            }
        }
    });

    $this->info('Default role permission bundles synced.');
    $this->line('Roles updated: '.count($syncedRoles));
    $this->line('Permissions ensured: '.count($allPermissions));
    $this->line('New permissions created: '.$createdPermissions);

    foreach ($syncedRoles as $entry) {
        $facilityLabel = $entry['facilityId'] === null ? 'no facility' : 'facility '.$entry['facilityId'];
        $this->line('- '.$entry['code'].' ('.$entry['name'].', '.$facilityLabel.'): '.$entry['count'].' permissions');
    }

    if ($missingRoles !== []) {
        $this->info('Roles created during sync: '.implode(', ', $missingRoles));
    }

    return 0;
})->purpose('Sync the default permission bundles for common hospital and platform roles');

Artisan::command('app:bootstrap-staging-minimum
    {--admin-email=admin@afyanova.test}
    {--admin-name=Hospital Administrator}
    {--admin-password=}
    {--registration-email=}
    {--registration-name=Registration Clerk}
    {--registration-password=}
    {--tenant-code=TZH}
    {--tenant-name=Tanzania Health Network}
    {--facility-code=DAR-MAIN}
    {--facility-name=Dar Main Hospital}', function (): int {
    $adminEmail = trim((string) $this->option('admin-email'));
    $adminName = trim((string) $this->option('admin-name'));
    $adminPassword = trim((string) $this->option('admin-password'));
    $registrationEmail = trim((string) $this->option('registration-email'));
    $registrationName = trim((string) $this->option('registration-name'));
    $registrationPassword = trim((string) $this->option('registration-password'));
    $tenantCode = strtoupper(trim((string) $this->option('tenant-code')));
    $tenantName = trim((string) $this->option('tenant-name'));
    $facilityCode = strtoupper(trim((string) $this->option('facility-code')));
    $facilityName = trim((string) $this->option('facility-name'));

    if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $this->error('Provide a valid --admin-email value.');

        return self::FAILURE;
    }

    if ($registrationEmail !== '' && ! filter_var($registrationEmail, FILTER_VALIDATE_EMAIL)) {
        $this->error('Provide a valid --registration-email value.');

        return self::FAILURE;
    }

    if ($adminName === '' || $tenantCode === '' || $tenantName === '' || $facilityCode === '' || $facilityName === '') {
        $this->error('Admin name, tenant code/name, and facility code/name are required.');

        return self::FAILURE;
    }

    $generatedAdminPassword = false;
    if ($adminPassword === '') {
        $adminPassword = Str::password(20);
        $generatedAdminPassword = true;
    }

    $generatedRegistrationPassword = false;
    if ($registrationEmail !== '' && $registrationPassword === '') {
        $registrationPassword = Str::password(20);
        $generatedRegistrationPassword = true;
    }

    try {
        Artisan::call('app:sync-default-role-permissions');

        DB::transaction(function () use (
            $adminEmail,
            $adminName,
            $adminPassword,
            $registrationEmail,
            $registrationName,
            $registrationPassword,
            $tenantCode,
            $tenantName,
            $facilityCode,
            $facilityName
        ): void {
            $tenant = TenantModel::query()->firstOrCreate(
                ['code' => $tenantCode],
                [
                    'name' => $tenantName,
                    'country_code' => 'TZ',
                    'status' => 'active',
                ],
            );

            $tenant->fill([
                'name' => $tenantName,
                'country_code' => 'TZ',
                'status' => 'active',
            ])->save();

            $facility = FacilityModel::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $facilityCode],
                [
                    'name' => $facilityName,
                    'facility_type' => 'hospital',
                    'timezone' => 'Africa/Dar_es_Salaam',
                    'status' => 'active',
                ],
            );

            $facility->fill([
                'name' => $facilityName,
                'facility_type' => 'hospital',
                'timezone' => 'Africa/Dar_es_Salaam',
                'status' => 'active',
            ])->save();

            BaselineDepartmentCatalog::seedForScope(
                tenantId: (string) $tenant->id,
                facilityId: (string) $facility->id,
            );

            $admin = User::query()->firstOrNew(['email' => $adminEmail]);
            $admin->fill([
                'name' => $adminName,
                'password' => $adminPassword,
                'status' => 'active',
            ]);
            $admin->email_verified_at = now();
            $admin->save();

            DB::table('facility_user')->updateOrInsert(
                [
                    'facility_id' => $facility->id,
                    'user_id' => $admin->id,
                ],
                [
                    'role' => 'super_admin',
                    'is_primary' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            foreach (Permission::query()->pluck('name')->all() as $permissionName) {
                $admin->givePermissionTo((string) $permissionName);
            }

            if ($registrationEmail !== '') {
                $registrationUser = User::query()->firstOrNew(['email' => $registrationEmail]);
                $registrationUser->fill([
                    'name' => $registrationName !== '' ? $registrationName : 'Registration Clerk',
                    'password' => $registrationPassword,
                    'status' => 'active',
                ]);
                $registrationUser->email_verified_at = now();
                $registrationUser->save();

                DB::table('facility_user')->updateOrInsert(
                    [
                        'facility_id' => $facility->id,
                        'user_id' => $registrationUser->id,
                    ],
                    [
                        'role' => 'front_desk',
                        'is_primary' => true,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $registrationRole = RoleModel::query()
                    ->where('code', 'HOSPITAL.REGISTRATION.CLERK')
                    ->first();

                if ($registrationRole instanceof RoleModel) {
                    $registrationUser->roles()->syncWithoutDetaching([$registrationRole->id]);
                }
            }

            FacilitySubscriptionBootstrap::ensureActiveSubscription(
                (string) $tenant->id,
                (string) $facility->id,
            );
        });
    } catch (QueryException $exception) {
        $this->error('Unable to bootstrap staging data. Ensure migrations are complete and database credentials are correct.');
        $this->line('Database error: '.$exception->getCode());

        return self::FAILURE;
    }

    $this->info('Minimum staging data is ready.');
    $this->line('Admin email: '.$adminEmail);
    $this->line('Tenant: '.$tenantCode);
    $this->line('Facility: '.$facilityCode);
    $this->line('Created/updated: tenant, facility, facility subscription (active plan), departments, permissions, roles, admin user, facility assignment.');

    if ($generatedAdminPassword) {
        $this->warn('Generated admin password: '.$adminPassword);
        $this->line('Store it now. It will not be shown again.');
    }

    if ($registrationEmail !== '') {
        $this->line('Registration user email: '.$registrationEmail);

        if ($generatedRegistrationPassword) {
            $this->warn('Generated registration password: '.$registrationPassword);
            $this->line('Store it now. It will not be shown again.');
        }
    }

    return self::SUCCESS;
})->purpose('Create clean minimum staging data for first hospital testing without demo patient records');

Artisan::command('app:ensure-facility-subscription {--tenant-code=TZH} {--facility-code=DAR-MAIN}', function (): int {
    $tenantCode = strtoupper(trim((string) $this->option('tenant-code')));
    $facilityCode = strtoupper(trim((string) $this->option('facility-code')));

    if ($tenantCode === '' || $facilityCode === '') {
        $this->error('Provide non-empty --tenant-code and --facility-code.');

        return self::FAILURE;
    }

    $tenant = TenantModel::query()->where('code', $tenantCode)->first();
    if ($tenant === null) {
        $this->error('Unknown tenant code: '.$tenantCode);

        return self::FAILURE;
    }

    $facility = FacilityModel::query()
        ->where('tenant_id', $tenant->id)
        ->where('code', $facilityCode)
        ->first();

    if ($facility === null) {
        $this->error('Unknown facility code '.$facilityCode.' for tenant '.$tenantCode);

        return self::FAILURE;
    }

    if (FacilitySubscriptionBootstrap::ensureActiveSubscription((string) $tenant->id, (string) $facility->id)) {
        $this->info('Facility subscription ensured (active plan attached).');

        return self::SUCCESS;
    }

    $this->warn('Could not attach subscription. Ensure migrations ran and platform_subscription_plans exists.');

    return self::FAILURE;
})->purpose('Attach an active platform subscription plan to a facility (repair local/staging API entitlement checks)');

Artisan::command('app:seed-demo-opd-data {--user-email=admin@local.test} {--tenant-code=TZH} {--facility-code=DAR-MAIN}', function (): int {
    $userEmail = trim((string) $this->option('user-email'));
    $tenantCode = strtoupper(trim((string) $this->option('tenant-code')));
    $facilityCode = strtoupper(trim((string) $this->option('facility-code')));

    if ($userEmail === '' || ! filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        $this->error('Provide a valid --user-email value.');

        return 1;
    }

    if ($tenantCode === '' || $facilityCode === '') {
        $this->error('Provide non-empty --tenant-code and --facility-code values.');

        return 1;
    }

    try {
        $user = User::query()->where('email', $userEmail)->first();
    } catch (QueryException $exception) {
        $this->error('Unable to query users. Ensure migrations are applied and database is reachable.');
        $this->line('Database error: '.$exception->getCode());

        return 1;
    }

    if (! $user) {
        $this->error('User not found: '.$userEmail);
        $this->line('Run: php artisan app:bootstrap-super-admin --email='.$userEmail);

        return 1;
    }

    try {
        DB::transaction(function () use ($user, $tenantCode, $facilityCode): void {
            $tenant = TenantModel::query()->firstOrCreate(
                ['code' => $tenantCode],
                [
                    'name' => 'Tanzania Health Network',
                    'country_code' => 'TZ',
                    'status' => 'active',
                ],
            );

            $facility = FacilityModel::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $facilityCode],
                [
                    'name' => 'Dar Main Hospital',
                    'facility_type' => 'hospital',
                    'timezone' => 'Africa/Dar_es_Salaam',
                    'status' => 'active',
                ],
            );

            FacilitySubscriptionBootstrap::ensureActiveSubscription(
                (string) $tenant->id,
                (string) $facility->id,
            );

            DB::table('facility_user')->updateOrInsert(
                [
                    'facility_id' => $facility->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => 'super_admin',
                    'is_primary' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $departmentNamesByCode = BaselineDepartmentCatalog::seedForScope(
                tenantId: (string) $tenant->id,
                facilityId: (string) $facility->id,
            );

            $patientsSeed = [
                [
                    'patient_number' => 'PAT-DEMO-0001',
                    'first_name' => 'Asha',
                    'middle_name' => null,
                    'last_name' => 'Mollel',
                    'gender' => 'female',
                    'date_of_birth' => '1992-05-12',
                    'phone' => '+255700000001',
                    'email' => 'asha.demo@example.test',
                    'national_id' => 'TZ-DEMO-0001',
                    'country_code' => 'TZ',
                    'region' => 'Dar es Salaam',
                    'district' => 'Ilala',
                    'address_line' => 'Upanga',
                    'next_of_kin_name' => 'Juma Mollel',
                    'next_of_kin_phone' => '+255700000011',
                    'status' => 'active',
                ],
                [
                    'patient_number' => 'PAT-DEMO-0002',
                    'first_name' => 'Neema',
                    'middle_name' => 'Paul',
                    'last_name' => 'Kimaro',
                    'gender' => 'female',
                    'date_of_birth' => '1988-11-03',
                    'phone' => '+255700000002',
                    'email' => 'neema.demo@example.test',
                    'national_id' => 'TZ-DEMO-0002',
                    'country_code' => 'TZ',
                    'region' => 'Dar es Salaam',
                    'district' => 'Kinondoni',
                    'address_line' => 'Mikocheni',
                    'next_of_kin_name' => 'Paulo Kimaro',
                    'next_of_kin_phone' => '+255700000022',
                    'status' => 'active',
                ],
                [
                    'patient_number' => 'PAT-DEMO-0003',
                    'first_name' => 'John',
                    'middle_name' => null,
                    'last_name' => 'Mwakyusa',
                    'gender' => 'male',
                    'date_of_birth' => '1979-01-25',
                    'phone' => '+255700000003',
                    'email' => 'john.demo@example.test',
                    'national_id' => 'TZ-DEMO-0003',
                    'country_code' => 'TZ',
                    'region' => 'Pwani',
                    'district' => 'Kibaha',
                    'address_line' => 'Mailimoja',
                    'next_of_kin_name' => 'Rehema Mwakyusa',
                    'next_of_kin_phone' => '+255700000033',
                    'status' => 'active',
                ],
            ];

            $patientModels = [];

            foreach ($patientsSeed as $index => $seed) {
                $patient = PatientModel::query()->firstOrNew([
                    'patient_number' => $seed['patient_number'],
                ]);

                $patient->fill($seed + [
                    'tenant_id' => $tenant->id,
                    'status_reason' => null,
                ]);
                $patient->save();

                $patientModels[$index] = $patient;
            }

            $appointmentsSeed = [
                [
                    'appointment_number' => 'APT-DEMO-0001',
                    'patient_index' => 0,
                    'department' => $departmentNamesByCode['OPD'] ?? 'General OPD',
                    'scheduled_at' => now()->addHour()->startOfMinute(),
                    'duration_minutes' => 20,
                    'reason' => 'Fever and headache',
                    'notes' => 'Walk-in converted to scheduled queue',
                    'status' => 'scheduled',
                ],
                [
                    'appointment_number' => 'APT-DEMO-0002',
                    'patient_index' => 1,
                    'department' => $departmentNamesByCode['ANC'] ?? 'Antenatal Clinic',
                    'scheduled_at' => now()->addHours(2)->startOfMinute(),
                    'duration_minutes' => 30,
                    'reason' => 'ANC follow-up visit',
                    'notes' => 'Routine review',
                    'status' => 'scheduled',
                ],
            ];

            $appointmentModels = [];

            foreach ($appointmentsSeed as $index => $seed) {
                $appointment = AppointmentModel::query()->firstOrNew([
                    'appointment_number' => $seed['appointment_number'],
                ]);

                $appointment->fill([
                    'appointment_number' => $seed['appointment_number'],
                    'tenant_id' => $tenant->id,
                    'facility_id' => $facility->id,
                    'patient_id' => $patientModels[$seed['patient_index']]->id,
                    'clinician_user_id' => $user->id,
                    'department' => $seed['department'],
                    'scheduled_at' => $seed['scheduled_at'],
                    'duration_minutes' => $seed['duration_minutes'],
                    'reason' => $seed['reason'],
                    'notes' => $seed['notes'],
                    'status' => $seed['status'],
                    'status_reason' => null,
                ]);
                $appointment->save();

                $appointmentModels[$index] = $appointment;
            }

            $labOrdersSeed = [
                [
                    'order_number' => 'LAB-DEMO-0001',
                    'patient_index' => 0,
                    'appointment_index' => 0,
                    'test_code' => 'CBC',
                    'test_name' => 'Complete Blood Count',
                    'priority' => 'routine',
                    'specimen_type' => 'blood',
                    'clinical_notes' => 'Fever workup',
                    'status' => 'ordered',
                ],
                [
                    'order_number' => 'LAB-DEMO-0002',
                    'patient_index' => 2,
                    'appointment_index' => null,
                    'test_code' => 'RBG',
                    'test_name' => 'Random Blood Glucose',
                    'priority' => 'urgent',
                    'specimen_type' => 'blood',
                    'clinical_notes' => 'Polyuria and fatigue',
                    'status' => 'ordered',
                ],
            ];

            foreach ($labOrdersSeed as $seed) {
                $order = LaboratoryOrderModel::query()->firstOrNew([
                    'order_number' => $seed['order_number'],
                ]);

                $order->fill([
                    'order_number' => $seed['order_number'],
                    'tenant_id' => $tenant->id,
                    'facility_id' => $facility->id,
                    'patient_id' => $patientModels[$seed['patient_index']]->id,
                    'admission_id' => null,
                    'appointment_id' => $seed['appointment_index'] !== null ? $appointmentModels[$seed['appointment_index']]->id : null,
                    'ordered_by_user_id' => $user->id,
                    'ordered_at' => now()->subMinutes(15),
                    'test_code' => $seed['test_code'],
                    'test_name' => $seed['test_name'],
                    'priority' => $seed['priority'],
                    'specimen_type' => $seed['specimen_type'],
                    'clinical_notes' => $seed['clinical_notes'],
                    'result_summary' => null,
                    'resulted_at' => null,
                    'status' => $seed['status'],
                    'status_reason' => null,
                ]);
                $order->save();
            }

            $pharmacyOrdersSeed = [
                [
                    'order_number' => 'PHM-DEMO-0001',
                    'patient_index' => 0,
                    'appointment_index' => 0,
                    'medication_code' => 'PCM500',
                    'medication_name' => 'Paracetamol 500mg',
                    'dosage_instruction' => '1 tablet every 8 hours for 3 days',
                    'quantity_prescribed' => 9,
                    'status' => 'pending',
                ],
                [
                    'order_number' => 'PHM-DEMO-0002',
                    'patient_index' => 1,
                    'appointment_index' => 1,
                    'medication_code' => 'IFA',
                    'medication_name' => 'Iron + Folic Acid',
                    'dosage_instruction' => '1 tablet daily',
                    'quantity_prescribed' => 30,
                    'status' => 'pending',
                ],
            ];

            foreach ($pharmacyOrdersSeed as $seed) {
                $order = PharmacyOrderModel::query()->firstOrNew([
                    'order_number' => $seed['order_number'],
                ]);

                $order->fill([
                    'order_number' => $seed['order_number'],
                    'tenant_id' => $tenant->id,
                    'facility_id' => $facility->id,
                    'patient_id' => $patientModels[$seed['patient_index']]->id,
                    'admission_id' => null,
                    'appointment_id' => $appointmentModels[$seed['appointment_index']]->id,
                    'ordered_by_user_id' => $user->id,
                    'ordered_at' => now()->subMinutes(10),
                    'medication_code' => $seed['medication_code'],
                    'medication_name' => $seed['medication_name'],
                    'dosage_instruction' => $seed['dosage_instruction'],
                    'quantity_prescribed' => $seed['quantity_prescribed'],
                    'quantity_dispensed' => 0,
                    'dispensing_notes' => null,
                    'dispensed_at' => null,
                    'status' => $seed['status'],
                    'status_reason' => null,
                ]);
                $order->save();
            }

            $billingSeed = [
                [
                    'invoice_number' => 'INV-DEMO-0001',
                    'patient_index' => 0,
                    'appointment_index' => 0,
                    'total_amount' => 25000,
                    'paid_amount' => 0,
                    'status' => 'draft',
                    'notes' => 'OPD consultation + lab request',
                ],
                [
                    'invoice_number' => 'INV-DEMO-0002',
                    'patient_index' => 1,
                    'appointment_index' => 1,
                    'total_amount' => 18000,
                    'paid_amount' => 0,
                    'status' => 'draft',
                    'notes' => 'ANC review visit',
                ],
            ];

            foreach ($billingSeed as $seed) {
                $invoice = BillingInvoiceModel::query()->firstOrNew([
                    'invoice_number' => $seed['invoice_number'],
                ]);

                $invoice->fill([
                    'invoice_number' => $seed['invoice_number'],
                    'tenant_id' => $tenant->id,
                    'facility_id' => $facility->id,
                    'patient_id' => $patientModels[$seed['patient_index']]->id,
                    'admission_id' => null,
                    'appointment_id' => $appointmentModels[$seed['appointment_index']]->id,
                    'issued_by_user_id' => $user->id,
                    'invoice_date' => now(),
                    'currency_code' => 'TZS',
                    'subtotal_amount' => $seed['total_amount'],
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'total_amount' => $seed['total_amount'],
                    'paid_amount' => $seed['paid_amount'],
                    'balance_amount' => max($seed['total_amount'] - $seed['paid_amount'], 0),
                    'payment_due_at' => now()->addDay(),
                    'notes' => $seed['notes'],
                    'status' => $seed['status'],
                    'status_reason' => null,
                ]);
                $invoice->save();
            }
        });
    } catch (QueryException $exception) {
        $this->error('Unable to seed demo OPD data.');
        $this->line('Database error: '.$exception->getCode());

        return 1;
    }

    $this->info('Demo OPD data seeded/updated successfully.');
    $this->line('User: '.$userEmail);
    $this->line('Tenant: '.$tenantCode);
    $this->line('Facility: '.$facilityCode);
    $this->line('Created/updated: tenant, facility, facility assignment, departments, sample patients/appointments/lab/pharmacy/billing records');
    $this->line('Next: log in and open /dashboard');

    return 0;
})->purpose('Seed a demo tenant/facility assignment and OPD sample records for UI testing (idempotent)');

Artisan::command('pharmacy:backfill-dispense-stock {--tenantCode=} {--facilityCode=} {--batch=200} {--confirm} {--json}', function (): int {
    $tenantCode = trim((string) $this->option('tenantCode'));
    $facilityCode = trim((string) $this->option('facilityCode'));
    $batchOption = $this->option('batch');
    $batch = is_numeric($batchOption) ? (int) $batchOption : 200;

    if ($batch < 1 || $batch > 10000) {
        $this->error('The --batch option must be between 1 and 10000.');

        return 1;
    }

    $tenant = null;
    if ($tenantCode !== '') {
        $tenant = TenantModel::query()
            ->where('code', $tenantCode)
            ->first();

        if (! $tenant) {
            $this->error('Unknown tenant code: '.$tenantCode);

            return 1;
        }
    }

    $facility = null;
    if ($facilityCode !== '') {
        $facilityQuery = FacilityModel::query()
            ->where('code', $facilityCode);

        if ($tenant) {
            $facilityQuery->where('tenant_id', $tenant->id);
        }

        $facility = $facilityQuery->first();
        if (! $facility) {
            $this->error('Unknown facility code: '.$facilityCode);

            return 1;
        }

        if (! $tenant && $facility->tenant_id) {
            $tenant = TenantModel::query()->find($facility->tenant_id);
        }
    }

    $confirm = (bool) $this->option('confirm');

    $normalizeLookupText = static fn (?string $value): string => Str::lower(trim((string) $value));
    $orderIndicatesSubstitution = static function (PharmacyOrderModel $order): bool {
        if ((bool) $order->substitution_made) {
            return true;
        }

        return str_contains(
            Str::lower((string) ($order->dispensing_notes ?? '')),
            'substitution: yes',
        );
    };
    $resolveDispenseTarget = static function (PharmacyOrderModel $order) use ($orderIndicatesSubstitution): array {
        if ($orderIndicatesSubstitution($order)) {
            $substitutedCode = trim((string) ($order->substituted_medication_code ?? ''));
            $substitutedName = trim((string) ($order->substituted_medication_name ?? ''));

            if ($substitutedCode !== '' || $substitutedName !== '') {
                return [$substitutedCode !== '' ? $substitutedCode : null, $substitutedName !== '' ? $substitutedName : null];
            }
        }

        $orderedCode = trim((string) ($order->medication_code ?? ''));
        $orderedName = trim((string) ($order->medication_name ?? ''));

        return [$orderedCode !== '' ? $orderedCode : null, $orderedName !== '' ? $orderedName : null];
    };
    $inventoryItemMatchScore = static function (
        InventoryItemModel $item,
        ?string $requestedCode,
        ?string $requestedName
    ) use ($normalizeLookupText): int {
        $itemCode = $normalizeLookupText((string) $item->item_code);
        $itemName = $normalizeLookupText((string) $item->item_name);
        $normalizedCode = $normalizeLookupText($requestedCode);
        $normalizedName = $normalizeLookupText($requestedName);

        $score = 0;

        if ($normalizedCode !== '') {
            if ($itemCode === $normalizedCode) {
                $score = max($score, 700);
            } elseif (str_ends_with($itemCode, '-'.$normalizedCode)) {
                $score = max($score, 560);
            } elseif (str_contains($itemCode, $normalizedCode)) {
                $score = max($score, 460);
            }
        }

        if ($normalizedName !== '') {
            if ($itemName === $normalizedName) {
                $score = max($score, 640);
            } elseif (
                str_contains($itemName, $normalizedName)
                || str_contains($normalizedName, $itemName)
            ) {
                $score = max($score, 420);
            }
        }

        return $score;
    };
    $findInventoryMatch = static function (PharmacyOrderModel $order) use ($resolveDispenseTarget, $inventoryItemMatchScore): ?InventoryItemModel {
        [$dispenseTargetCode, $dispenseTargetName] = $resolveDispenseTarget($order);

        if (
            trim((string) $dispenseTargetCode) === ''
            && trim((string) $dispenseTargetName) === ''
        ) {
            return null;
        }

        $query = InventoryItemModel::query()
            ->where('status', 'active')
            ->when(
                $order->tenant_id !== null,
                fn ($builder) => $builder->where('tenant_id', $order->tenant_id),
                fn ($builder) => $builder->whereNull('tenant_id'),
            )
            ->when(
                $order->facility_id !== null,
                fn ($builder) => $builder->where('facility_id', $order->facility_id),
                fn ($builder) => $builder->whereNull('facility_id'),
            )
            ->where(function ($builder) use ($dispenseTargetCode, $dispenseTargetName): void {
                if ($dispenseTargetCode !== null && trim($dispenseTargetCode) !== '') {
                    $builder->orWhere('item_code', 'like', '%'.trim($dispenseTargetCode).'%');
                }

                if ($dispenseTargetName !== null && trim($dispenseTargetName) !== '') {
                    $builder->orWhere('item_name', 'like', '%'.trim($dispenseTargetName).'%');
                }
            })
            ->limit(25)
            ->get();

        $bestMatch = $query
            ->map(fn (InventoryItemModel $item): array => [
                'item' => $item,
                'score' => $inventoryItemMatchScore($item, $dispenseTargetCode, $dispenseTargetName),
            ])
            ->filter(fn (array $entry): bool => $entry['score'] > 0)
            ->sort(function (array $left, array $right): int {
                if ($right['score'] !== $left['score']) {
                    return $right['score'] <=> $left['score'];
                }

                return strcmp(
                    (string) ($left['item']->item_name ?? ''),
                    (string) ($right['item']->item_name ?? ''),
                );
            })
            ->first();

        return $bestMatch['item'] ?? null;
    };

    $movementScopeQuery = InventoryStockMovementModel::query()
        ->where('movement_type', 'issue')
        ->when(
            $tenant !== null,
            fn ($builder) => $builder->where('tenant_id', $tenant->id),
        )
        ->when(
            $facility !== null,
            fn ($builder) => $builder->where('facility_id', $facility->id),
        );

    $alreadyBackfilledOrderIds = $movementScopeQuery
        ->get(['metadata'])
        ->map(static fn (InventoryStockMovementModel $movement): ?string => data_get($movement->metadata, 'pharmacy_order_id'))
        ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
        ->unique()
        ->values()
        ->all();

    $alreadyBackfilledLookup = array_fill_keys($alreadyBackfilledOrderIds, true);

    $baseOrderQuery = PharmacyOrderModel::query()
        ->whereIn('status', ['partially_dispensed', 'dispensed'])
        ->where('quantity_dispensed', '>', 0)
        ->when(
            $tenant !== null,
            fn ($builder) => $builder->where('tenant_id', $tenant->id),
        )
        ->when(
            $facility !== null,
            fn ($builder) => $builder->where('facility_id', $facility->id),
        )
        ->orderBy('created_at')
        ->orderBy('id');

    $eligibleOrders = [];
    $alreadyBackfilledOrders = 0;

    foreach ($baseOrderQuery->cursor() as $order) {
        if (isset($alreadyBackfilledLookup[$order->id])) {
            $alreadyBackfilledOrders++;

            continue;
        }

        $eligibleOrders[] = $order;
    }

    $candidateBatch = array_slice($eligibleOrders, 0, $batch);
    $processableOrders = 0;
    $missingInventoryMatches = 0;
    $insufficientStockOrders = 0;
    $ordersBackfilled = 0;
    $processedOrderIds = [];
    $missingInventoryExamples = [];
    $insufficientStockExamples = [];

    foreach ($candidateBatch as $order) {
        $inventoryItem = $findInventoryMatch($order);
        if (! $inventoryItem) {
            $missingInventoryMatches++;

            if (count($missingInventoryExamples) < 5) {
                $missingInventoryExamples[] = [
                    'orderId' => $order->id,
                    'orderNumber' => $order->order_number,
                ];
            }

            continue;
        }

        $quantityIssued = round((float) ($order->quantity_dispensed ?? 0), 3);
        $stockBefore = round((float) ($inventoryItem->current_stock ?? 0), 3);
        $stockAfter = round($stockBefore - $quantityIssued, 3);

        if ($stockAfter < 0) {
            $insufficientStockOrders++;

            if (count($insufficientStockExamples) < 5) {
                $insufficientStockExamples[] = [
                    'orderId' => $order->id,
                    'orderNumber' => $order->order_number,
                    'inventoryItemId' => $inventoryItem->id,
                    'inventoryItemCode' => $inventoryItem->item_code,
                ];
            }

            continue;
        }

        $processableOrders++;
        $processedOrderIds[] = $order->id;

        if (! $confirm) {
            continue;
        }

        DB::transaction(function () use ($inventoryItem, $order, $quantityIssued, $stockBefore, $stockAfter): void {
            $inventoryItem->forceFill([
                'current_stock' => $stockAfter,
            ])->save();

            InventoryStockMovementModel::query()->create([
                'tenant_id' => $order->tenant_id,
                'facility_id' => $order->facility_id,
                'item_id' => $inventoryItem->id,
                'movement_type' => 'issue',
                'adjustment_direction' => null,
                'quantity' => $quantityIssued,
                'quantity_delta' => -1 * $quantityIssued,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => 'Historical pharmacy dispense backfill.',
                'notes' => $order->dispensing_notes,
                'actor_id' => null,
                'metadata' => [
                    'source_module' => 'pharmacy',
                    'source_action' => 'pharmacy-order.backfill-dispense-stock',
                    'pharmacy_order_id' => $order->id,
                    'pharmacy_order_number' => $order->order_number,
                    'patient_id' => $order->patient_id,
                    'appointment_id' => $order->appointment_id,
                    'admission_id' => $order->admission_id,
                    'dispense_target_code' => $order->substitution_made
                        ? ($order->substituted_medication_code ?: $order->medication_code)
                        : $order->medication_code,
                    'dispense_target_name' => $order->substitution_made
                        ? ($order->substituted_medication_name ?: $order->medication_name)
                        : $order->medication_name,
                    'backfill' => true,
                    'status' => $order->status,
                    'quantity_dispensed' => (float) $order->quantity_dispensed,
                ],
                'occurred_at' => $order->dispensed_at ?? $order->updated_at ?? $order->created_at ?? now(),
                'created_at' => now(),
            ]);
        });

        $ordersBackfilled++;
    }

    $report = [
        'mode' => $confirm ? 'backfill' : 'dry_run',
        'scope' => [
            'tenantCode' => $tenant?->code,
            'facilityCode' => $facility?->code,
        ],
        'batchSize' => $batch,
        'totals' => [
            'eligibleOrdersBefore' => count($eligibleOrders),
            'alreadyBackfilledOrders' => $alreadyBackfilledOrders,
            'ordersInBatch' => count($candidateBatch),
            'processableOrdersInBatch' => $processableOrders,
            'ordersBackfilled' => $confirm ? $ordersBackfilled : 0,
            'ordersRemainingWithoutStockMovement' => $confirm
                ? max(count($eligibleOrders) - $ordersBackfilled, 0)
                : count($eligibleOrders),
        ],
        'analysis' => [
            'missingInventoryMatchesInBatch' => $missingInventoryMatches,
            'insufficientStockInBatch' => $insufficientStockOrders,
        ],
        'processedOrderIds' => $confirm ? $processedOrderIds : [],
        'examples' => [
            'missingInventory' => $missingInventoryExamples,
            'insufficientStock' => $insufficientStockExamples,
        ],
        'mutationPerformed' => $confirm,
        'truncatedByBatch' => count($eligibleOrders) > $batch,
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info('Pharmacy dispense stock backfill '.($confirm ? '(confirmed)' : '(dry-run)'));
    $this->line('Eligible orders before: '.$report['totals']['eligibleOrdersBefore']);
    $this->line('Already backfilled orders: '.$report['totals']['alreadyBackfilledOrders']);
    $this->line('Orders in batch: '.$report['totals']['ordersInBatch']);
    $this->line('Processable orders in batch: '.$report['totals']['processableOrdersInBatch']);
    $this->line('Missing inventory matches in batch: '.$report['analysis']['missingInventoryMatchesInBatch']);
    $this->line('Insufficient stock in batch: '.$report['analysis']['insufficientStockInBatch']);
    $this->line('Orders backfilled: '.$report['totals']['ordersBackfilled']);
    $this->line('Orders remaining without stock movement: '.$report['totals']['ordersRemainingWithoutStockMovement']);
    $this->line('Mutation performed: '.($confirm ? 'yes' : 'no'));

    return 0;
})->purpose('Backfill inventory issue movements for historical dispensed pharmacy orders that predate dispense depletion');

Artisan::command('platform:audit-export-jobs:cleanup {--days=} {--batch=} {--confirm} {--json}', function (): int {
    $daysOption = $this->option('days');
    $batchOption = $this->option('batch');

    $days = is_numeric($daysOption)
        ? (int) $daysOption
        : (int) config('platform_audit_retention.audit_export_jobs.retention_days', 30);
    $batch = is_numeric($batchOption)
        ? (int) $batchOption
        : (int) config('platform_audit_retention.audit_export_jobs.batch_size', 500);

    if ($days < 1) {
        $this->error('The --days option must be at least 1.');

        return 1;
    }

    if ($batch < 1 || $batch > 10000) {
        $this->error('The --batch option must be between 1 and 10000.');

        return 1;
    }

    $fileDirectory = trim(str_replace('\\', '/', (string) config('platform_audit_retention.audit_export_jobs.file_directory', 'audit-exports')), '/');
    if ($fileDirectory === '') {
        $this->error('Audit export cleanup requires a non-empty configured file directory.');

        return 1;
    }

    $directoryPrefix = $fileDirectory.'/';
    $cutoff = now()->subDays($days);
    $cutoffTimestamp = $cutoff->getTimestamp();
    $confirm = (bool) $this->option('confirm');
    $disk = Storage::disk('local');

    $candidateQuery = AuditExportJobModel::query()
        ->where(function ($query) use ($cutoff): void {
            $query->where(function ($completedQuery) use ($cutoff): void {
                $completedQuery
                    ->where('status', 'completed')
                    ->where(function ($thresholdQuery) use ($cutoff): void {
                        $thresholdQuery
                            ->where('completed_at', '<', $cutoff)
                            ->orWhere(function ($fallbackQuery) use ($cutoff): void {
                                $fallbackQuery
                                    ->whereNull('completed_at')
                                    ->where('created_at', '<', $cutoff);
                            });
                    });
            })->orWhere(function ($failedQuery) use ($cutoff): void {
                $failedQuery
                    ->where('status', 'failed')
                    ->where(function ($thresholdQuery) use ($cutoff): void {
                        $thresholdQuery
                            ->where('failed_at', '<', $cutoff)
                            ->orWhere(function ($fallbackQuery) use ($cutoff): void {
                                $fallbackQuery
                                    ->whereNull('failed_at')
                                    ->where('created_at', '<', $cutoff);
                            });
                    });
            })->orWhere(function ($queuedQuery) use ($cutoff): void {
                $queuedQuery
                    ->whereIn('status', ['queued', 'processing'])
                    ->where('created_at', '<', $cutoff);
            });
        });

    $resolveStaleOrphanFiles = function () use ($disk, $fileDirectory, $directoryPrefix, $cutoffTimestamp): array {
        $referencedPaths = AuditExportJobModel::query()
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => str_replace('\\', '/', trim($value)))
            ->unique()
            ->values()
            ->all();

        $referencedPathLookup = array_fill_keys($referencedPaths, true);
        $allFiles = $disk->allFiles($fileDirectory);
        $staleOrphanFiles = [];

        foreach ($allFiles as $filePath) {
            $normalizedPath = str_replace('\\', '/', (string) $filePath);
            if (! str_starts_with($normalizedPath, $directoryPrefix)) {
                continue;
            }

            if (isset($referencedPathLookup[$normalizedPath])) {
                continue;
            }

            if ($disk->lastModified($normalizedPath) < $cutoffTimestamp) {
                $staleOrphanFiles[] = $normalizedPath;
            }
        }

        return [
            'scannedFiles' => count($allFiles),
            'staleOrphanFiles' => array_values(array_unique($staleOrphanFiles)),
        ];
    };

    try {
        $totalRows = AuditExportJobModel::query()->count();
        $candidateRowsBefore = (clone $candidateQuery)->count();

        $candidateBatch = (clone $candidateQuery)
            ->orderBy('created_at')
            ->limit($batch)
            ->get(['id', 'file_path']);

        $candidateIdsInBatch = $candidateBatch->pluck('id')->values()->all();
        $candidateFilePathsInBatch = $candidateBatch
            ->pluck('file_path')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => str_replace('\\', '/', trim($value)))
            ->unique()
            ->values()
            ->all();

        $orphanScanBefore = $resolveStaleOrphanFiles();
        $staleOrphanFilesBefore = (array) ($orphanScanBefore['staleOrphanFiles'] ?? []);
        $scannedFilesBefore = (int) ($orphanScanBefore['scannedFiles'] ?? 0);

        $deletedRows = 0;
        $deletedCandidateFiles = 0;
        $missingCandidateFiles = 0;
        $skippedCandidateFilesOutsideDirectory = 0;
        $deletedOrphanFiles = 0;
        $orphanDeleteCandidates = array_slice($staleOrphanFilesBefore, 0, $batch);

        if ($confirm) {
            if ($candidateIdsInBatch !== []) {
                $deletedRows = AuditExportJobModel::query()
                    ->whereIn('id', $candidateIdsInBatch)
                    ->delete();
            }

            foreach ($candidateFilePathsInBatch as $filePath) {
                if (! str_starts_with($filePath, $directoryPrefix)) {
                    $skippedCandidateFilesOutsideDirectory++;

                    continue;
                }

                if (! $disk->exists($filePath)) {
                    $missingCandidateFiles++;

                    continue;
                }

                if ($disk->delete($filePath)) {
                    $deletedCandidateFiles++;
                }
            }

            foreach ($orphanDeleteCandidates as $filePath) {
                if (! str_starts_with($filePath, $directoryPrefix)) {
                    continue;
                }

                if ($disk->delete($filePath)) {
                    $deletedOrphanFiles++;
                }
            }
        }

        $candidateRowsRemaining = $confirm
            ? (clone $candidateQuery)->count()
            : $candidateRowsBefore;
        $orphanScanAfter = $resolveStaleOrphanFiles();
        $staleOrphanFilesRemaining = count((array) ($orphanScanAfter['staleOrphanFiles'] ?? []));
        $scannedFilesAfter = (int) ($orphanScanAfter['scannedFiles'] ?? 0);
    } catch (QueryException $exception) {
        $this->error('Unable to run audit export job cleanup. Ensure migrations are applied.');
        $this->line('Database error: '.$exception->getCode());

        return 1;
    }

    $report = [
        'mode' => $confirm ? 'cleanup' : 'dry_run',
        'table' => 'audit_export_jobs',
        'retentionDays' => $days,
        'batchSize' => $batch,
        'cutoffTimestamp' => $cutoff->toIso8601String(),
        'fileDirectory' => $fileDirectory,
        'totals' => [
            'totalRows' => $totalRows,
            'candidateRowsBefore' => $candidateRowsBefore,
            'candidateRowsDeleted' => $deletedRows,
            'candidateRowsRemaining' => $candidateRowsRemaining,
        ],
        'candidateFiles' => [
            'candidateFilesInBatch' => count($candidateFilePathsInBatch),
            'deletedFiles' => $deletedCandidateFiles,
            'missingFiles' => $missingCandidateFiles,
            'skippedOutsideDirectory' => $skippedCandidateFilesOutsideDirectory,
        ],
        'staleOrphanFiles' => [
            'scannedFilesBefore' => $scannedFilesBefore,
            'scannedFilesAfter' => $scannedFilesAfter,
            'staleOrphanFilesBefore' => count($staleOrphanFilesBefore),
            'deletedOrphanFiles' => $deletedOrphanFiles,
            'staleOrphanFilesRemaining' => $staleOrphanFilesRemaining,
        ],
        'deletionPerformed' => $confirm,
        'truncatedByBatch' => $confirm
            ? ($candidateRowsRemaining > 0 || $staleOrphanFilesRemaining > 0)
            : ($candidateRowsBefore > $batch || count($staleOrphanFilesBefore) > $batch),
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info('Audit export job cleanup '.($confirm ? '(confirmed)' : '(dry-run)'));
    $this->line('Retention days: '.$days);
    $this->line('Cutoff: '.$report['cutoffTimestamp']);
    $this->line('Candidate rows before: '.$report['totals']['candidateRowsBefore']);
    $this->line('Candidate rows deleted: '.$report['totals']['candidateRowsDeleted']);
    $this->line('Candidate rows remaining: '.$report['totals']['candidateRowsRemaining']);
    $this->line('Stale orphan files before: '.$report['staleOrphanFiles']['staleOrphanFilesBefore']);
    $this->line('Stale orphan files deleted: '.$report['staleOrphanFiles']['deletedOrphanFiles']);
    $this->line('Stale orphan files remaining: '.$report['staleOrphanFiles']['staleOrphanFilesRemaining']);
    $this->line('Deletion performed: '.($confirm ? 'yes' : 'no'));

    return 0;
})->purpose('Clean up expired audit export jobs and stale export files');

Artisan::command('platform:audit-export-retry-resume-telemetry:cleanup {--days=} {--batch=} {--confirm} {--json}', function (): int {
    $startedAt = now();
    $daysOption = $this->option('days');
    $batchOption = $this->option('batch');
    $lastReportPath = trim(
        str_replace(
            '\\',
            '/',
            (string) config(
                'platform_audit_retention.audit_export_retry_resume_telemetry.observability.cleanup_last_report_path',
                'platform-audit/retry-resume-telemetry-cleanup-last-report.json'
            )
        ),
        '/'
    );
    $persistLastReport = static function (array $report) use ($lastReportPath): void {
        if ($lastReportPath === '') {
            return;
        }

        try {
            Storage::disk('local')->put(
                $lastReportPath,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable) {
            // Ignore observability persistence errors; cleanup should still proceed.
        }
    };

    $days = is_numeric($daysOption)
        ? (int) $daysOption
        : (int) config('platform_audit_retention.audit_export_retry_resume_telemetry.retention_days', 60);
    $batch = is_numeric($batchOption)
        ? (int) $batchOption
        : (int) config('platform_audit_retention.audit_export_retry_resume_telemetry.batch_size', 1000);

    if ($days < 1) {
        $this->error('The --days option must be at least 1.');

        return 1;
    }

    if ($batch < 1 || $batch > 10000) {
        $this->error('The --batch option must be between 1 and 10000.');

        return 1;
    }

    $cutoff = now()->subDays($days);
    $confirm = (bool) $this->option('confirm');

    $candidateQuery = AuditExportRetryResumeTelemetryEventModel::query()
        ->where('occurred_at', '<', $cutoff);

    try {
        $totalRows = AuditExportRetryResumeTelemetryEventModel::query()->count();
        $candidateRowsBefore = (clone $candidateQuery)->count();
        $candidateIdsInBatch = (clone $candidateQuery)
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->limit($batch)
            ->pluck('id')
            ->values()
            ->all();

        $deletedRows = 0;
        if ($confirm && $candidateIdsInBatch !== []) {
            $deletedRows = AuditExportRetryResumeTelemetryEventModel::query()
                ->whereIn('id', $candidateIdsInBatch)
                ->delete();
        }

        $candidateRowsRemaining = $confirm
            ? (clone $candidateQuery)->count()
            : $candidateRowsBefore;
    } catch (QueryException $exception) {
        $failedReport = [
            'status' => 'failed',
            'command' => 'platform:audit-export-retry-resume-telemetry:cleanup',
            'mode' => $confirm ? 'cleanup' : 'dry_run',
            'ranAt' => now()->toIso8601String(),
            'startedAt' => $startedAt->toIso8601String(),
            'retentionDays' => $days,
            'batchSize' => $batch,
            'cutoffTimestamp' => $cutoff->toIso8601String(),
            'errorCode' => $exception->getCode(),
            'errorMessage' => $exception->getMessage(),
        ];
        $persistLastReport($failedReport);

        $this->error('Unable to run audit export retry-resume telemetry cleanup. Ensure migrations are applied.');
        $this->line('Database error: '.$exception->getCode());

        return 1;
    }

    $report = [
        'status' => 'success',
        'command' => 'platform:audit-export-retry-resume-telemetry:cleanup',
        'mode' => $confirm ? 'cleanup' : 'dry_run',
        'ranAt' => now()->toIso8601String(),
        'startedAt' => $startedAt->toIso8601String(),
        'table' => 'audit_export_retry_resume_telemetry_events',
        'retentionDays' => $days,
        'batchSize' => $batch,
        'cutoffTimestamp' => $cutoff->toIso8601String(),
        'totals' => [
            'totalRows' => $totalRows,
            'candidateRowsBefore' => $candidateRowsBefore,
            'candidateRowsDeleted' => $deletedRows,
            'candidateRowsRemaining' => $candidateRowsRemaining,
        ],
        'deletionPerformed' => $confirm,
        'truncatedByBatch' => $confirm
            ? ($candidateRowsRemaining > 0)
            : ($candidateRowsBefore > $batch),
    ];
    $persistLastReport($report);

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info('Audit export retry-resume telemetry cleanup '.($confirm ? '(confirmed)' : '(dry-run)'));
    $this->line('Retention days: '.$days);
    $this->line('Cutoff: '.$report['cutoffTimestamp']);
    $this->line('Candidate rows before: '.$report['totals']['candidateRowsBefore']);
    $this->line('Candidate rows deleted: '.$report['totals']['candidateRowsDeleted']);
    $this->line('Candidate rows remaining: '.$report['totals']['candidateRowsRemaining']);
    $this->line('Deletion performed: '.($confirm ? 'yes' : 'no'));

    return 0;
})->purpose('Clean up expired audit export retry-resume telemetry events');

Artisan::command('platform:cross-tenant-audit-logs:retention-report {--days=400} {--tenantCode=} {--action=} {--json}', function (): int {
    $days = (int) $this->option('days');

    if ($days < 1) {
        $this->error('The --days option must be at least 1.');

        return 1;
    }

    $tenantCode = $this->option('tenantCode');
    $tenantCode = is_string($tenantCode) && trim($tenantCode) !== '' ? strtoupper(trim($tenantCode)) : null;

    $action = $this->option('action');
    $action = is_string($action) && trim($action) !== '' ? trim($action) : null;

    $cutoff = now()->subDays($days);

    try {
        $baseQuery = CrossTenantAdminAuditLogModel::query()
            ->when($tenantCode, fn ($query) => $query->where('target_tenant_code', $tenantCode))
            ->when($action, fn ($query) => $query->where('action', $action));

        $totalRows = (clone $baseQuery)->count();

        $candidateRowsBeforeHoldExclusionQuery = (clone $baseQuery)->where('created_at', '<', $cutoff);
        $candidateRowsBeforeHoldExclusion = (clone $candidateRowsBeforeHoldExclusionQuery)->count();

        $purgeCandidatesQuery = (clone $candidateRowsBeforeHoldExclusionQuery);
        $purgeCandidatesQuery->whereNotExists(function ($holdQuery): void {
            $holdQuery->selectRaw('1')
                ->from((new CrossTenantAdminAuditLogHoldModel)->getTable().' as holds')
                ->where('holds.is_active', true)
                ->whereNull('holds.released_at')
                ->where(function ($tenantScope): void {
                    $tenantScope
                        ->whereNull('holds.target_tenant_code')
                        ->orWhereColumn('holds.target_tenant_code', 'platform_cross_tenant_admin_audit_logs.target_tenant_code');
                })
                ->where(function ($actionScope): void {
                    $actionScope
                        ->whereNull('holds.action')
                        ->orWhereColumn('holds.action', 'platform_cross_tenant_admin_audit_logs.action');
                })
                ->where(function ($startScope): void {
                    $startScope
                        ->whereNull('holds.starts_at')
                        ->orWhereColumn('holds.starts_at', '<=', 'platform_cross_tenant_admin_audit_logs.created_at');
                })
                ->where(function ($endScope): void {
                    $endScope
                        ->whereNull('holds.ends_at')
                        ->orWhereColumn('holds.ends_at', '>=', 'platform_cross_tenant_admin_audit_logs.created_at');
                });
        });
        $purgeCandidateRows = (clone $purgeCandidatesQuery)->count();
    } catch (QueryException $exception) {
        $this->error('Unable to read platform cross-tenant admin audit logs. Ensure migrations are applied.');
        $this->line('Database error: '.$exception->getCode());

        return 1;
    }

    $report = [
        'mode' => 'dry_run',
        'table' => 'platform_cross_tenant_admin_audit_logs',
        'retentionDays' => $days,
        'cutoffTimestamp' => $cutoff->toIso8601String(),
        'filters' => [
            'tenantCode' => $tenantCode,
            'action' => $action,
        ],
        'totals' => [
            'totalRows' => $totalRows,
            'purgeCandidateRows' => $purgeCandidateRows,
            'retainedRows' => max($totalRows - $purgeCandidateRows, 0),
        ],
        'holdExclusions' => [
            'activeHoldRowsExcluded' => max($candidateRowsBeforeHoldExclusion - $purgeCandidateRows, 0),
            'candidateRowsBeforeHoldExclusion' => $candidateRowsBeforeHoldExclusion,
        ],
        'candidateWindow' => [
            'oldestCreatedAt' => optional((clone $purgeCandidatesQuery)->orderBy('created_at')->first()?->created_at)?->toIso8601String(),
            'newestCreatedAt' => optional((clone $purgeCandidatesQuery)->orderByDesc('created_at')->first()?->created_at)?->toIso8601String(),
        ],
        'overallWindow' => [
            'oldestCreatedAt' => optional((clone $baseQuery)->orderBy('created_at')->first()?->created_at)?->toIso8601String(),
            'newestCreatedAt' => optional((clone $baseQuery)->orderByDesc('created_at')->first()?->created_at)?->toIso8601String(),
        ],
        'deletionPerformed' => false,
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info('Platform cross-tenant admin audit log retention report (dry-run)');
    $this->line('Table: '.$report['table']);
    $this->line('Retention days: '.$days);
    $this->line('Cutoff: '.$report['cutoffTimestamp']);
    $this->line('Filters: tenantCode='.(string) ($tenantCode ?? '*').', action='.(string) ($action ?? '*'));
    $this->line('Total rows: '.$report['totals']['totalRows']);
    $this->line('Purge candidates: '.$report['totals']['purgeCandidateRows']);
    $this->line('Retained rows: '.$report['totals']['retainedRows']);
    $this->line('Deletion performed: no');

    return 0;
})->purpose('Report (dry-run only) cross-tenant admin audit log retention purge candidates');

Artisan::command('platform:cross-tenant-audit-logs:retention-purge {--days=400} {--tenantCode=} {--action=} {--batch=500} {--confirm} {--json}', function (): int {
    $days = (int) $this->option('days');
    $batch = (int) $this->option('batch');

    if ($days < 1) {
        $this->error('The --days option must be at least 1.');

        return 1;
    }

    if ($batch < 1 || $batch > 10000) {
        $this->error('The --batch option must be between 1 and 10000.');

        return 1;
    }

    $tenantCode = $this->option('tenantCode');
    $tenantCode = is_string($tenantCode) && trim($tenantCode) !== '' ? strtoupper(trim($tenantCode)) : null;

    $action = $this->option('action');
    $action = is_string($action) && trim($action) !== '' ? trim($action) : null;

    $cutoff = now()->subDays($days);

    try {
        $baseQuery = CrossTenantAdminAuditLogModel::query()
            ->when($tenantCode, fn ($query) => $query->where('target_tenant_code', $tenantCode))
            ->when($action, fn ($query) => $query->where('action', $action));

        $totalRows = (clone $baseQuery)->count();
        $candidateRowsBeforeHoldExclusionQuery = (clone $baseQuery)->where('created_at', '<', $cutoff);
        $candidateRowsBeforeHoldExclusion = (clone $candidateRowsBeforeHoldExclusionQuery)->count();

        $purgeCandidatesQuery = (clone $candidateRowsBeforeHoldExclusionQuery);
        $purgeCandidatesQuery->whereNotExists(function ($holdQuery): void {
            $holdQuery->selectRaw('1')
                ->from((new CrossTenantAdminAuditLogHoldModel)->getTable().' as holds')
                ->where('holds.is_active', true)
                ->whereNull('holds.released_at')
                ->where(function ($tenantScope): void {
                    $tenantScope
                        ->whereNull('holds.target_tenant_code')
                        ->orWhereColumn('holds.target_tenant_code', 'platform_cross_tenant_admin_audit_logs.target_tenant_code');
                })
                ->where(function ($actionScope): void {
                    $actionScope
                        ->whereNull('holds.action')
                        ->orWhereColumn('holds.action', 'platform_cross_tenant_admin_audit_logs.action');
                })
                ->where(function ($startScope): void {
                    $startScope
                        ->whereNull('holds.starts_at')
                        ->orWhereColumn('holds.starts_at', '<=', 'platform_cross_tenant_admin_audit_logs.created_at');
                })
                ->where(function ($endScope): void {
                    $endScope
                        ->whereNull('holds.ends_at')
                        ->orWhereColumn('holds.ends_at', '>=', 'platform_cross_tenant_admin_audit_logs.created_at');
                });
        });
        $purgeCandidateRowsBefore = (clone $purgeCandidatesQuery)->count();

        $candidateWindowBefore = [
            'oldestCreatedAt' => optional((clone $purgeCandidatesQuery)->orderBy('created_at')->first()?->created_at)?->toIso8601String(),
            'newestCreatedAt' => optional((clone $purgeCandidatesQuery)->orderByDesc('created_at')->first()?->created_at)?->toIso8601String(),
        ];

        $overallWindowBefore = [
            'oldestCreatedAt' => optional((clone $baseQuery)->orderBy('created_at')->first()?->created_at)?->toIso8601String(),
            'newestCreatedAt' => optional((clone $baseQuery)->orderByDesc('created_at')->first()?->created_at)?->toIso8601String(),
        ];
    } catch (QueryException $exception) {
        $this->error('Unable to read platform cross-tenant admin audit logs. Ensure migrations are applied.');
        $this->line('Database error: '.$exception->getCode());

        return 1;
    }

    if (! (bool) $this->option('confirm')) {
        $this->error('Refusing to delete audit logs without --confirm.');

        $report = [
            'mode' => 'blocked_no_confirm',
            'table' => 'platform_cross_tenant_admin_audit_logs',
            'retentionDays' => $days,
            'batchSize' => $batch,
            'cutoffTimestamp' => $cutoff->toIso8601String(),
            'filters' => [
                'tenantCode' => $tenantCode,
                'action' => $action,
            ],
            'totals' => [
                'totalRows' => $totalRows,
                'purgeCandidateRowsBefore' => $purgeCandidateRowsBefore,
            ],
            'holdExclusions' => [
                'activeHoldRowsExcluded' => max($candidateRowsBeforeHoldExclusion - $purgeCandidateRowsBefore, 0),
                'candidateRowsBeforeHoldExclusion' => $candidateRowsBeforeHoldExclusion,
            ],
            'deletionPerformed' => false,
            'deletedRows' => 0,
            'remainingCandidateRows' => $purgeCandidateRowsBefore,
            'safety' => [
                'confirmRequired' => true,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Run again with --confirm to delete up to '.$batch.' eligible rows.');
        }

        return 1;
    }

    try {
        $idsToDelete = (clone $purgeCandidatesQuery)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id')
            ->all();

        $deletedRows = 0;

        if ($idsToDelete !== []) {
            $deletedRows = CrossTenantAdminAuditLogModel::query()
                ->whereIn('id', $idsToDelete)
                ->delete();
        }

        $remainingCandidateRowsQuery = CrossTenantAdminAuditLogModel::query()
            ->when($tenantCode, fn ($query) => $query->where('target_tenant_code', $tenantCode))
            ->when($action, fn ($query) => $query->where('action', $action))
            ->where('created_at', '<', $cutoff);

        $remainingCandidateRowsQuery->whereNotExists(function ($holdQuery): void {
            $holdQuery->selectRaw('1')
                ->from((new CrossTenantAdminAuditLogHoldModel)->getTable().' as holds')
                ->where('holds.is_active', true)
                ->whereNull('holds.released_at')
                ->where(function ($tenantScope): void {
                    $tenantScope
                        ->whereNull('holds.target_tenant_code')
                        ->orWhereColumn('holds.target_tenant_code', 'platform_cross_tenant_admin_audit_logs.target_tenant_code');
                })
                ->where(function ($actionScope): void {
                    $actionScope
                        ->whereNull('holds.action')
                        ->orWhereColumn('holds.action', 'platform_cross_tenant_admin_audit_logs.action');
                })
                ->where(function ($startScope): void {
                    $startScope
                        ->whereNull('holds.starts_at')
                        ->orWhereColumn('holds.starts_at', '<=', 'platform_cross_tenant_admin_audit_logs.created_at');
                })
                ->where(function ($endScope): void {
                    $endScope
                        ->whereNull('holds.ends_at')
                        ->orWhereColumn('holds.ends_at', '>=', 'platform_cross_tenant_admin_audit_logs.created_at');
                });
        });

        $remainingCandidateRows = $remainingCandidateRowsQuery->count();

        $totalRowsAfter = CrossTenantAdminAuditLogModel::query()
            ->when($tenantCode, fn ($query) => $query->where('target_tenant_code', $tenantCode))
            ->when($action, fn ($query) => $query->where('action', $action))
            ->count();
    } catch (QueryException $exception) {
        $this->error('Unable to purge platform cross-tenant admin audit logs.');
        $this->line('Database error: '.$exception->getCode());

        return 1;
    }

    $report = [
        'mode' => 'purge',
        'table' => 'platform_cross_tenant_admin_audit_logs',
        'retentionDays' => $days,
        'batchSize' => $batch,
        'cutoffTimestamp' => $cutoff->toIso8601String(),
        'filters' => [
            'tenantCode' => $tenantCode,
            'action' => $action,
        ],
        'totals' => [
            'totalRowsBefore' => $totalRows,
            'purgeCandidateRowsBefore' => $purgeCandidateRowsBefore,
            'totalRowsAfter' => $totalRowsAfter,
            'remainingCandidateRows' => $remainingCandidateRows,
        ],
        'holdExclusions' => [
            'activeHoldRowsExcluded' => max($candidateRowsBeforeHoldExclusion - $purgeCandidateRowsBefore, 0),
            'candidateRowsBeforeHoldExclusion' => $candidateRowsBeforeHoldExclusion,
        ],
        'windowsBefore' => [
            'candidate' => $candidateWindowBefore,
            'overall' => $overallWindowBefore,
        ],
        'deletionPerformed' => true,
        'deletedRows' => $deletedRows,
        'truncatedByBatch' => $purgeCandidateRowsBefore > $deletedRows,
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    $this->info('Platform cross-tenant admin audit log retention purge (single batch)');
    $this->line('Cutoff: '.$report['cutoffTimestamp']);
    $this->line('Filters: tenantCode='.(string) ($tenantCode ?? '*').', action='.(string) ($action ?? '*'));
    $this->line('Batch size: '.$batch);
    $this->line('Candidates before: '.$purgeCandidateRowsBefore);
    $this->line('Deleted rows: '.$deletedRows);
    $this->line('Remaining candidates: '.$remainingCandidateRows);
    $this->line('More eligible rows remain: '.($report['truncatedByBatch'] ? 'yes' : 'no'));

    return 0;
})->purpose('Delete one confirmed batch of expired cross-tenant admin audit logs using retention cutoff');

Artisan::command('platform:cross-tenant-audit-logs:retention-purge-scheduled {--days=} {--tenantCode=} {--action=} {--batch=} {--json}', function (): int {
    $currentEnvironment = (string) app()->environment();
    $scheduleConfigPath = 'platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule';
    $monitoringConfigPath = 'platform_audit_retention.cross_tenant_admin_audit_logs.purge.monitoring';
    $holdsGovernanceConfigPath = 'platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance';
    $scheduleReadinessConfigPath = $scheduleConfigPath.'.readiness';
    $enabled = (bool) config($scheduleConfigPath.'.enabled', false);
    $allowedEnvironments = array_values(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        (array) config($scheduleConfigPath.'.allowed_environments', ['production'])
    ), static fn (string $value): bool => $value !== ''));

    $daysOption = $this->option('days');
    $days = is_numeric($daysOption)
        ? (int) $daysOption
        : (int) config('platform_audit_retention.cross_tenant_admin_audit_logs.retention_days', 400);

    $batchOption = $this->option('batch');
    $batch = is_numeric($batchOption)
        ? (int) $batchOption
        : (int) config('platform_audit_retention.cross_tenant_admin_audit_logs.purge.batch_size', 500);

    $tenantCode = $this->option('tenantCode');
    $tenantCode = is_string($tenantCode) && trim($tenantCode) !== '' ? strtoupper(trim($tenantCode)) : null;

    $action = $this->option('action');
    $action = is_string($action) && trim($action) !== '' ? trim($action) : null;

    $baseReport = [
        'command' => 'platform:cross-tenant-audit-logs:retention-purge-scheduled',
        'environment' => $currentEnvironment,
        'schedule' => [
            'enabled' => $enabled,
            'allowedEnvironments' => $allowedEnvironments,
        ],
        'effectiveOptions' => [
            'days' => $days,
            'batch' => $batch,
            'tenantCode' => $tenantCode,
            'action' => $action,
        ],
    ];

    $normalizeLogChannel = static function ($value): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    };

    $executionLogChannel = $normalizeLogChannel(config($monitoringConfigPath.'.log_channels.execution'));
    $metricsLogChannel = $normalizeLogChannel(config($monitoringConfigPath.'.log_channels.metrics'));
    $alertsLogChannel = $normalizeLogChannel(config($monitoringConfigPath.'.log_channels.alerts'));

    $writeLog = static function (string $level, string $message, array $context = [], ?string $channel = null): void {
        if ($channel !== null) {
            Log::channel($channel)->{$level}($message, $context);

            return;
        }

        match ($level) {
            'error' => Log::error($message, $context),
            'warning' => Log::warning($message, $context),
            default => Log::info($message, $context),
        };
    };

    $emitMetrics = function (string $status, array $context = []) use ($baseReport, $monitoringConfigPath, $metricsLogChannel, $alertsLogChannel, $executionLogChannel, $writeLog): void {
        $monitoring = [
            'alertsEnabled' => (bool) config($monitoringConfigPath.'.alerts_enabled', true),
            'remainingCandidatesWarningThreshold' => (int) config($monitoringConfigPath.'.remaining_candidates_warning_threshold', 5000),
            'deletedRowsWarningThreshold' => (int) config($monitoringConfigPath.'.deleted_rows_warning_threshold', 5000),
            'logChannels' => [
                'metrics' => $metricsLogChannel,
                'alerts' => $alertsLogChannel,
                'execution' => $executionLogChannel,
            ],
        ];

        $writeLog('info', 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled.metrics', $baseReport + [
            'status' => $status,
            'monitoring' => $monitoring,
        ] + $context, $metricsLogChannel);
    };

    $emitAlert = function (string $level, string $alertType, array $context = []) use ($baseReport, $monitoringConfigPath, $alertsLogChannel, $writeLog): void {
        if (! (bool) config($monitoringConfigPath.'.alerts_enabled', true)) {
            return;
        }

        $payload = $baseReport + [
            'alertType' => $alertType,
        ] + $context;

        $writeLog($level, 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled.alert', $payload, $alertsLogChannel);
    };

    if (! $enabled) {
        $report = $baseReport + [
            'status' => 'skipped_disabled',
            'deletionPerformed' => false,
        ];

        $writeLog('info', 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled', $report, $executionLogChannel);
        $emitMetrics('skipped_disabled', [
            'deletionPerformed' => false,
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Scheduled retention purge skipped: schedule is disabled.');
        }

        return 0;
    }

    if ($allowedEnvironments !== [] && ! app()->environment($allowedEnvironments)) {
        $report = $baseReport + [
            'status' => 'skipped_environment_guard',
            'deletionPerformed' => false,
        ];

        $writeLog('warning', 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled', $report, $executionLogChannel);
        $emitMetrics('skipped_environment_guard', [
            'deletionPerformed' => false,
        ]);

        if ((bool) config($monitoringConfigPath.'.alert_on_environment_guard_skip', false)) {
            $emitAlert('warning', 'environment_guard_skip', [
                'status' => 'skipped_environment_guard',
                'deletionPerformed' => false,
            ]);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Scheduled retention purge skipped: current environment is not allowed.');
        }

        return 0;
    }

    $readinessGuardRequiresTwoPersonControl = (bool) config($scheduleReadinessConfigPath.'.require_two_person_control', true);
    $twoPersonControlEnabled = (bool) config($holdsGovernanceConfigPath.'.enforce_two_person_control', false);
    $twoPersonControlWaiverEnabled = (bool) config($scheduleReadinessConfigPath.'.two_person_control_waiver.enabled', false);
    $twoPersonControlWaiverReferenceValue = config($scheduleReadinessConfigPath.'.two_person_control_waiver.reference');
    $twoPersonControlWaiverReference = is_string($twoPersonControlWaiverReferenceValue) && trim($twoPersonControlWaiverReferenceValue) !== ''
        ? trim($twoPersonControlWaiverReferenceValue)
        : null;
    $twoPersonControlWaiverValid = $twoPersonControlWaiverEnabled && $twoPersonControlWaiverReference !== null;

    if ($readinessGuardRequiresTwoPersonControl && ! $twoPersonControlEnabled && ! $twoPersonControlWaiverValid) {
        $report = $baseReport + [
            'status' => 'blocked_readiness_guard_two_person_control_required',
            'deletionPerformed' => false,
            'readinessGuard' => [
                'requireTwoPersonControl' => true,
                'twoPersonControlEnabled' => false,
                'waiver' => [
                    'enabled' => $twoPersonControlWaiverEnabled,
                    'hasReference' => $twoPersonControlWaiverReference !== null,
                    'reference' => $twoPersonControlWaiverReference,
                ],
            ],
        ];

        $writeLog('error', 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled', $report, $executionLogChannel);
        $emitMetrics('blocked_readiness_guard_two_person_control_required', [
            'deletionPerformed' => false,
            'readinessGuard' => $report['readinessGuard'],
        ]);
        $emitAlert('error', 'readiness_guard_two_person_control_required', [
            'status' => 'blocked_readiness_guard_two_person_control_required',
            'deletionPerformed' => false,
            'readinessGuard' => $report['readinessGuard'],
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error('Scheduled retention purge blocked: enable two-person control for legal holds or configure an explicit waiver reference.');
        }

        return 1;
    }

    $purgeOutputBuffer = new BufferedOutput;

    $purgeExitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge', [
        '--days' => $days,
        '--batch' => $batch,
        '--tenantCode' => $tenantCode,
        '--action' => $action,
        '--confirm' => true,
        '--json' => true,
    ], $purgeOutputBuffer);

    $purgeOutput = trim($purgeOutputBuffer->fetch());
    $purgeReport = json_decode($purgeOutput, true);

    if (! is_array($purgeReport)) {
        $report = $baseReport + [
            'status' => 'failed_unparseable_purge_output',
            'purgeCommandExitCode' => $purgeExitCode,
            'deletionPerformed' => false,
        ];

        $writeLog('error', 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled', $report + [
            'purgeOutputPreview' => substr($purgeOutput, 0, 500),
        ], $executionLogChannel);
        $emitMetrics('failed_unparseable_purge_output', [
            'purgeCommandExitCode' => $purgeExitCode,
            'deletionPerformed' => false,
        ]);
        $emitAlert('error', 'failed_unparseable_purge_output', [
            'status' => 'failed_unparseable_purge_output',
            'purgeCommandExitCode' => $purgeExitCode,
            'deletionPerformed' => false,
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error('Scheduled retention purge failed: purge output could not be parsed.');
        }

        return 1;
    }

    $report = $baseReport + [
        'status' => $purgeExitCode === 0 ? 'executed' : 'failed_purge_command',
        'purgeCommandExitCode' => $purgeExitCode,
        'deletionPerformed' => (bool) ($purgeReport['deletionPerformed'] ?? false),
        'deletedRows' => (int) ($purgeReport['deletedRows'] ?? 0),
        'remainingCandidateRows' => (int) ($purgeReport['totals']['remainingCandidateRows'] ?? 0),
        'truncatedByBatch' => (bool) ($purgeReport['truncatedByBatch'] ?? false),
        'holdExclusions' => $purgeReport['holdExclusions'] ?? null,
        'purgeReport' => $purgeReport,
    ];

    if ($purgeExitCode === 0) {
        $writeLog('info', 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled', $report, $executionLogChannel);
    } else {
        $writeLog('error', 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled', $report, $executionLogChannel);
    }

    $emitMetrics((string) $report['status'], [
        'deletionPerformed' => (bool) $report['deletionPerformed'],
        'deletedRows' => (int) $report['deletedRows'],
        'remainingCandidateRows' => (int) $report['remainingCandidateRows'],
        'truncatedByBatch' => (bool) $report['truncatedByBatch'],
        'holdExclusions' => $report['holdExclusions'],
        'purgeCommandExitCode' => (int) $report['purgeCommandExitCode'],
    ]);

    if ($purgeExitCode !== 0) {
        $emitAlert('error', 'purge_command_failed', [
            'status' => (string) $report['status'],
            'purgeCommandExitCode' => (int) $report['purgeCommandExitCode'],
            'deletionPerformed' => (bool) $report['deletionPerformed'],
        ]);
    }

    $remainingCandidatesWarningThreshold = (int) config($monitoringConfigPath.'.remaining_candidates_warning_threshold', 5000);
    if ((int) $report['remainingCandidateRows'] >= $remainingCandidatesWarningThreshold && $remainingCandidatesWarningThreshold >= 0) {
        $emitAlert('warning', 'remaining_candidates_threshold_exceeded', [
            'status' => (string) $report['status'],
            'remainingCandidateRows' => (int) $report['remainingCandidateRows'],
            'threshold' => $remainingCandidatesWarningThreshold,
            'truncatedByBatch' => (bool) $report['truncatedByBatch'],
        ]);
    }

    $deletedRowsWarningThreshold = (int) config($monitoringConfigPath.'.deleted_rows_warning_threshold', 5000);
    if ((int) $report['deletedRows'] >= $deletedRowsWarningThreshold && $deletedRowsWarningThreshold >= 0) {
        $emitAlert('warning', 'deleted_rows_threshold_exceeded', [
            'status' => (string) $report['status'],
            'deletedRows' => (int) $report['deletedRows'],
            'threshold' => $deletedRowsWarningThreshold,
        ]);
    }

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->line('Scheduled retention purge status: '.$report['status']);
        $this->line('Deleted rows: '.$report['deletedRows']);
        $this->line('Remaining candidates: '.$report['remainingCandidateRows']);
    }

    return $purgeExitCode === 0 ? 0 : 1;
})->purpose('Run env-gated scheduled purge wrapper for cross-tenant admin audit logs with structured metrics');

Artisan::command('platform:cross-tenant-audit-logs:retention-readiness-check {--environment=} {--json}', function (): int {
    $scheduleConfigPath = 'platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule';
    $holdsGovernanceConfigPath = 'platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance';
    $scheduleReadinessConfigPath = $scheduleConfigPath.'.readiness';

    $requestedEnvironment = $this->option('environment');
    $evaluatedEnvironment = is_string($requestedEnvironment) && trim($requestedEnvironment) !== ''
        ? trim($requestedEnvironment)
        : (string) app()->environment();

    $scheduleEnabled = (bool) config($scheduleConfigPath.'.enabled', false);
    $allowedEnvironments = array_values(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        (array) config($scheduleConfigPath.'.allowed_environments', ['production'])
    ), static fn (string $value): bool => $value !== ''));
    $environmentAllowed = $allowedEnvironments === [] || in_array($evaluatedEnvironment, $allowedEnvironments, true);

    $requireTwoPersonControl = (bool) config($scheduleReadinessConfigPath.'.require_two_person_control', true);
    $twoPersonControlEnabled = (bool) config($holdsGovernanceConfigPath.'.enforce_two_person_control', false);
    $waiverEnabled = (bool) config($scheduleReadinessConfigPath.'.two_person_control_waiver.enabled', false);
    $waiverReferenceValue = config($scheduleReadinessConfigPath.'.two_person_control_waiver.reference');
    $waiverReference = is_string($waiverReferenceValue) && trim($waiverReferenceValue) !== ''
        ? trim($waiverReferenceValue)
        : null;
    $waiverValid = $waiverEnabled && $waiverReference !== null;

    $twoPersonControlRequirementSatisfied = ! $requireTwoPersonControl || $twoPersonControlEnabled || $waiverValid;
    $eligibleToRunScheduledWrapperNow = $scheduleEnabled && $environmentAllowed && $twoPersonControlRequirementSatisfied;

    $status = match (true) {
        ! $scheduleEnabled => 'not_ready_schedule_disabled',
        ! $environmentAllowed => 'not_ready_environment_not_allowed',
        ! $twoPersonControlRequirementSatisfied => 'not_ready_two_person_control_required',
        $requireTwoPersonControl && ! $twoPersonControlEnabled && $waiverValid => 'ready_with_waiver',
        default => 'ready',
    };

    $report = [
        'command' => 'platform:cross-tenant-audit-logs:retention-readiness-check',
        'status' => $status,
        'eligibleToRunScheduledWrapperNow' => $eligibleToRunScheduledWrapperNow,
        'evaluatedEnvironment' => $evaluatedEnvironment,
        'schedule' => [
            'enabled' => $scheduleEnabled,
            'allowedEnvironments' => $allowedEnvironments,
            'environmentAllowed' => $environmentAllowed,
        ],
        'readinessGuard' => [
            'requireTwoPersonControl' => $requireTwoPersonControl,
            'twoPersonControlEnabled' => $twoPersonControlEnabled,
            'twoPersonControlRequirementSatisfied' => $twoPersonControlRequirementSatisfied,
            'waiver' => [
                'enabled' => $waiverEnabled,
                'hasReference' => $waiverReference !== null,
                'reference' => $waiverReference,
                'valid' => $waiverValid,
            ],
        ],
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->line('Retention scheduled purge readiness status: '.$status);
        $this->line('Eligible to run scheduled wrapper now: '.($eligibleToRunScheduledWrapperNow ? 'yes' : 'no'));
        $this->line('Evaluated environment: '.$evaluatedEnvironment);
    }

    return $eligibleToRunScheduledWrapperNow ? 0 : 1;
})->purpose('Check cross-tenant admin audit log scheduled retention purge readiness (schedule, environment, two-person control/waiver)');

Artisan::command('platform:interoperability:readiness-signoff-check {--contract-version=} {--partner=} {--json}', function (): int {
    $requestedVersion = $this->option('contract-version');
    $defaultVersion = (string) config('platform_interoperability.default_version', 'v1');
    $evaluatedVersion = is_string($requestedVersion) && trim($requestedVersion) !== ''
        ? strtolower(trim($requestedVersion))
        : strtolower(trim($defaultVersion));

    $adapterEnvelope = config('platform_interoperability.adapter_envelopes.'.$evaluatedVersion);
    $partnerOption = $this->option('partner');
    $partner = is_string($partnerOption) && trim($partnerOption) !== ''
        ? trim($partnerOption)
        : null;

    if (! is_array($adapterEnvelope)) {
        $report = [
            'command' => 'platform:interoperability:readiness-signoff-check',
            'status' => 'not_ready_unknown_version',
            'evaluatedVersion' => $evaluatedVersion,
            'partner' => $partner,
            'readiness' => [
                'contractBaselineReady' => false,
                'partnerSet' => $partner !== null,
                'signoffEligibleNow' => false,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error('Interoperability sign-off readiness check failed: unknown adapter version "'.$evaluatedVersion.'".');
        }

        return 1;
    }

    $envelope = (array) ($adapterEnvelope['envelope'] ?? []);
    $priorityFlows = collect((array) ($adapterEnvelope['priorityFlows'] ?? []))
        ->map(static function ($flow): array {
            if (! is_array($flow)) {
                return [];
            }

            $key = isset($flow['key']) ? trim((string) $flow['key']) : '';
            $label = isset($flow['label']) ? trim((string) $flow['label']) : '';
            if ($key === '' || $label === '') {
                return [];
            }

            return [
                'key' => $key,
                'label' => $label,
            ];
        })
        ->filter(static fn (array $flow): bool => $flow !== [])
        ->values()
        ->all();
    $nonFunctionalControls = array_values(array_filter(array_map(
        static fn ($control): string => trim((string) $control),
        (array) ($adapterEnvelope['nonFunctionalControls'] ?? [])
    ), static fn (string $control): bool => $control !== ''));

    $operationalChecks = collect((array) config('platform_interoperability.signoff.operational_checks', []))
        ->map(static function ($check): array {
            if (! is_array($check)) {
                return [];
            }

            $key = isset($check['key']) ? trim((string) $check['key']) : '';
            $label = isset($check['label']) ? trim((string) $check['label']) : '';
            if ($key === '' || $label === '') {
                return [];
            }

            $owner = isset($check['owner']) ? trim((string) $check['owner']) : '';
            $status = isset($check['status']) ? trim((string) $check['status']) : '';
            if ($status === '') {
                $status = 'pending_execution_detail';
            }

            $evidence = array_values(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                (array) ($check['evidence'] ?? [])
            ), static fn (string $item): bool => $item !== ''));

            return [
                'key' => $key,
                'label' => $label,
                'owner' => $owner !== '' ? $owner : null,
                'status' => $status,
                'evidence' => $evidence,
            ];
        })
        ->filter(static fn (array $check): bool => $check !== [])
        ->values()
        ->all();

    $pendingOperationalChecks = array_values(array_filter(
        $operationalChecks,
        static fn (array $check): bool => str_starts_with((string) ($check['status'] ?? ''), 'pending_')
    ));

    $hasBaseline = $envelope !== [] && $priorityFlows !== [] && $nonFunctionalControls !== [];
    $status = match (true) {
        ! $hasBaseline => 'not_ready_contract_baseline_incomplete',
        $partner === null => 'baseline_ready_partner_pending',
        $pendingOperationalChecks !== [] => 'baseline_ready_execution_details_pending',
        default => 'signoff_ready',
    };
    $signoffEligibleNow = $status === 'signoff_ready';

    $report = [
        'command' => 'platform:interoperability:readiness-signoff-check',
        'status' => $status,
        'evaluatedVersion' => $evaluatedVersion,
        'partner' => $partner,
        'summary' => [
            'flowCount' => count($priorityFlows),
            'controlCount' => count($nonFunctionalControls),
            'operationalCheckCount' => count($operationalChecks),
            'pendingOperationalCheckCount' => count($pendingOperationalChecks),
        ],
        'readiness' => [
            'contractBaselineReady' => $hasBaseline,
            'partnerSet' => $partner !== null,
            'pendingOperationalCheckKeys' => array_values(array_map(
                static fn (array $check): string => (string) ($check['key'] ?? ''),
                $pendingOperationalChecks
            )),
            'signoffEligibleNow' => $signoffEligibleNow,
        ],
        'priorityFlows' => $priorityFlows,
        'nonFunctionalControls' => $nonFunctionalControls,
        'operationalChecks' => $operationalChecks,
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->line('Interoperability sign-off readiness status: '.$status);
        $this->line('Version: '.$evaluatedVersion);
        $this->line('Partner set: '.($partner !== null ? 'yes' : 'no'));
        $this->line('Pending operational checks: '.count($pendingOperationalChecks));
        $this->line('Sign-off eligible now: '.($signoffEligibleNow ? 'yes' : 'no'));
    }

    return str_starts_with($status, 'not_ready') ? 1 : 0;
})->purpose('Check interoperability sign-off readiness baseline for adapter envelope version, partner assignment, and operational checks');

Artisan::command('platform:phase5:gate-readiness-check {--gate=} {--json}', function (): int {
    $requestedGate = $this->option('gate');
    $requestedGate = is_string($requestedGate) && trim($requestedGate) !== ''
        ? strtoupper(trim($requestedGate))
        : null;

    $configuredGates = collect((array) config('phase5_readiness.gates', []))
        ->map(static function ($gate): array {
            if (! is_array($gate)) {
                return [];
            }

            $key = isset($gate['key']) ? strtoupper(trim((string) $gate['key'])) : '';
            $label = isset($gate['label']) ? trim((string) $gate['label']) : '';
            if ($key === '' || $label === '') {
                return [];
            }

            $owner = isset($gate['owner']) ? trim((string) $gate['owner']) : '';
            $signedArtifact = isset($gate['signedArtifact']) ? trim((string) $gate['signedArtifact']) : '';

            return [
                'key' => $key,
                'label' => $label,
                'owner' => $owner !== '' ? $owner : null,
                'signedArtifact' => $signedArtifact,
            ];
        })
        ->filter(static fn (array $gate): bool => $gate !== [])
        ->values();

    if ($requestedGate !== null && ! $configuredGates->contains(static fn (array $gate): bool => $gate['key'] === $requestedGate)) {
        $report = [
            'command' => 'platform:phase5:gate-readiness-check',
            'status' => 'not_ready_unknown_gate',
            'requestedGate' => $requestedGate,
            'readiness' => [
                'eligibleToClosePhase5Now' => false,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error('Unknown Phase 5 gate key: '.$requestedGate);
        }

        return 1;
    }

    $selectedGates = $requestedGate === null
        ? $configuredGates
        : $configuredGates->filter(static fn (array $gate): bool => $gate['key'] === $requestedGate)->values();

    $gateRows = $selectedGates
        ->map(static function (array $gate): array {
            $artifactPath = (string) ($gate['signedArtifact'] ?? '');
            $artifactAbsolutePath = $artifactPath !== '' ? base_path($artifactPath) : null;
            $artifactExists = $artifactAbsolutePath !== null && is_file($artifactAbsolutePath);

            return [
                'key' => (string) $gate['key'],
                'label' => (string) $gate['label'],
                'owner' => $gate['owner'] ?? null,
                'status' => $artifactExists ? 'signed' : 'missing_signed_artifact',
                'signedArtifact' => [
                    'path' => $artifactPath,
                    'exists' => $artifactExists,
                ],
            ];
        })
        ->values()
        ->all();

    $missingRows = array_values(array_filter(
        $gateRows,
        static fn (array $row): bool => (string) ($row['status'] ?? '') !== 'signed'
    ));

    $totalGates = count($gateRows);
    $signedCount = $totalGates - count($missingRows);
    $eligibleToClosePhase5Now = $totalGates > 0 && $missingRows === [];
    $status = match (true) {
        $totalGates === 0 => 'not_ready_no_gates_configured',
        $missingRows !== [] => 'not_ready_missing_signed_artifacts',
        default => 'ready',
    };

    $report = [
        'command' => 'platform:phase5:gate-readiness-check',
        'status' => $status,
        'requestedGate' => $requestedGate,
        'summary' => [
            'totalGates' => $totalGates,
            'signedCount' => $signedCount,
            'missingCount' => count($missingRows),
        ],
        'readiness' => [
            'eligibleToClosePhase5Now' => $eligibleToClosePhase5Now,
            'missingGateKeys' => array_values(array_map(
                static fn (array $row): string => (string) ($row['key'] ?? ''),
                $missingRows
            )),
        ],
        'gates' => $gateRows,
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->line('Phase 5 gate readiness status: '.$status);
        $this->line('Total gates: '.$totalGates);
        $this->line('Signed gates: '.$signedCount);
        $this->line('Missing signed artifacts: '.count($missingRows));
        $this->line('Eligible to close Phase 5 now: '.($eligibleToClosePhase5Now ? 'yes' : 'no'));
    }

    return $eligibleToClosePhase5Now ? 0 : 1;
})->purpose('Check Phase 5 G1-G6 signed-artifact readiness for Tanzania compliance gate closure');

Artisan::command('platform:phase5:documentation-readiness-check {--module=} {--json}', function (): int {
    $requestedModule = $this->option('module');
    $requestedModule = is_string($requestedModule) && trim($requestedModule) !== ''
        ? strtolower(trim($requestedModule))
        : null;

    $configuredModules = collect((array) config('phase5_documentation_readiness.modules', []))
        ->map(static function ($module): array {
            if (! is_array($module)) {
                return [];
            }

            $key = isset($module['key']) ? strtolower(trim((string) $module['key'])) : '';
            $label = isset($module['label']) ? trim((string) $module['label']) : '';
            if ($key === '' || $label === '') {
                return [];
            }

            $requiredFiles = array_values(array_filter(array_map(
                static fn ($path): string => trim((string) $path),
                (array) ($module['requiredFiles'] ?? [])
            ), static fn (string $path): bool => $path !== ''));

            $citationPackPath = isset($module['citationPackPath'])
                ? trim((string) $module['citationPackPath'])
                : null;
            if ($citationPackPath === '') {
                $citationPackPath = null;
            }

            return [
                'key' => $key,
                'label' => $label,
                'requiredFiles' => $requiredFiles,
                'citationPackPath' => $citationPackPath,
            ];
        })
        ->filter(static fn (array $module): bool => $module !== [])
        ->values();

    if ($requestedModule !== null && ! $configuredModules->contains(static fn (array $module): bool => $module['key'] === $requestedModule)) {
        $report = [
            'command' => 'platform:phase5:documentation-readiness-check',
            'status' => 'not_ready_unknown_module',
            'requestedModule' => $requestedModule,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error('Unknown Phase 5 documentation module: '.$requestedModule);
        }

        return 1;
    }

    $selectedModules = $requestedModule === null
        ? $configuredModules
        : $configuredModules->filter(static fn (array $module): bool => $module['key'] === $requestedModule)->values();

    $moduleRows = $selectedModules
        ->map(static function (array $module): array {
            $requiredFiles = (array) ($module['requiredFiles'] ?? []);
            $requiredFileRows = array_map(static function (string $path): array {
                return [
                    'path' => $path,
                    'exists' => is_file(base_path($path)),
                ];
            }, $requiredFiles);
            $missingRequiredFiles = array_values(array_map(
                static fn (array $row): string => (string) $row['path'],
                array_filter($requiredFileRows, static fn (array $row): bool => ! ((bool) ($row['exists'] ?? false)))
            ));

            $citationStatusSummary = null;
            $citationPackPath = $module['citationPackPath'] ?? null;
            if (is_string($citationPackPath) && $citationPackPath !== '' && is_file(base_path($citationPackPath))) {
                $content = (string) file_get_contents(base_path($citationPackPath));
                $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

                $approvedCount = 0;
                $inProgressCount = 0;
                $rejectedCount = 0;

                foreach ($lines as $line) {
                    $trimmedLine = trim((string) $line);
                    if (! str_starts_with($trimmedLine, '|')) {
                        continue;
                    }
                    if (str_contains($trimmedLine, '| ---')) {
                        continue;
                    }
                    if (str_contains($trimmedLine, '| Control Area |')) {
                        continue;
                    }

                    $cells = array_map('trim', explode('|', trim($trimmedLine, '|')));
                    if (count($cells) < 9) {
                        continue;
                    }

                    $statusCell = strtolower(trim((string) end($cells)));
                    if ($statusCell === 'approved') {
                        $approvedCount++;
                    } elseif ($statusCell === 'in progress') {
                        $inProgressCount++;
                    } elseif ($statusCell === 'rejected') {
                        $rejectedCount++;
                    }
                }

                $citationStatusSummary = [
                    'approvedCount' => $approvedCount,
                    'inProgressCount' => $inProgressCount,
                    'rejectedCount' => $rejectedCount,
                ];
            }

            $status = 'ready';
            if ($missingRequiredFiles !== []) {
                $status = 'not_ready_missing_required_files';
            } elseif (
                is_array($citationStatusSummary)
                && ((int) ($citationStatusSummary['inProgressCount'] ?? 0)) > 0
            ) {
                $status = 'ready_signed_with_draft_register';
            }

            return [
                'key' => (string) $module['key'],
                'label' => (string) $module['label'],
                'status' => $status,
                'requiredFiles' => $requiredFileRows,
                'missingRequiredFilePaths' => $missingRequiredFiles,
                'citationStatusSummary' => $citationStatusSummary,
            ];
        })
        ->values()
        ->all();

    $notReadyModules = array_values(array_filter(
        $moduleRows,
        static fn (array $row): bool => str_starts_with((string) ($row['status'] ?? ''), 'not_ready')
    ));

    $status = $notReadyModules === [] ? 'ready' : 'not_ready_missing_required_files';

    $report = [
        'command' => 'platform:phase5:documentation-readiness-check',
        'status' => $status,
        'requestedModule' => $requestedModule,
        'summary' => [
            'totalModules' => count($moduleRows),
            'readyModules' => count($moduleRows) - count($notReadyModules),
            'notReadyModules' => count($notReadyModules),
        ],
        'readiness' => [
            'eligibleToAdvanceNow' => $notReadyModules === [],
            'notReadyModuleKeys' => array_values(array_map(
                static fn (array $row): string => (string) ($row['key'] ?? ''),
                $notReadyModules
            )),
        ],
        'modules' => $moduleRows,
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->line('Phase 5 documentation readiness status: '.$status);
        $this->line('Total modules: '.count($moduleRows));
        $this->line('Not-ready modules: '.count($notReadyModules));
        $this->line('Eligible to advance now: '.($notReadyModules === [] ? 'yes' : 'no'));
    }

    return $notReadyModules === [] ? 0 : 1;
})->purpose('Check Phase 5 documentation readiness for approval tracker and legal citation pack evidence');

$platformAuditRetentionScheduleEvent = Schedule::command('platform:cross-tenant-audit-logs:retention-purge-scheduled --json')
    ->name('platform.cross-tenant-admin-audit-logs.retention-purge-scheduled')
    ->cron((string) config('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.cron', '17 2 * * *'))
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/platform_cross_tenant_admin_audit_log_retention_purge.log'))
    ->when(fn (): bool => (bool) config('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', false));

$platformAuditRetentionScheduleEnvironments = array_values(array_filter(array_map(
    static fn ($value): string => trim((string) $value),
    (array) config('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['production'])
), static fn (string $value): bool => $value !== ''));

if ($platformAuditRetentionScheduleEnvironments !== []) {
    $platformAuditRetentionScheduleEvent->environments($platformAuditRetentionScheduleEnvironments);
}

$auditExportJobsCleanupScheduleEvent = Schedule::command('platform:audit-export-jobs:cleanup --confirm --json')
    ->name('platform.audit-export-jobs.cleanup')
    ->cron((string) config('platform_audit_retention.audit_export_jobs.schedule.cron', '41 2 * * *'))
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/platform_audit_export_jobs_cleanup.log'))
    ->when(fn (): bool => (bool) config('platform_audit_retention.audit_export_jobs.schedule.enabled', false));

$auditExportJobsCleanupScheduleEnvironments = array_values(array_filter(array_map(
    static fn ($value): string => trim((string) $value),
    (array) config('platform_audit_retention.audit_export_jobs.schedule.allowed_environments', ['production'])
), static fn (string $value): bool => $value !== ''));

if ($auditExportJobsCleanupScheduleEnvironments !== []) {
    $auditExportJobsCleanupScheduleEvent->environments($auditExportJobsCleanupScheduleEnvironments);
}

$auditExportRetryResumeTelemetryCleanupScheduleEvent = Schedule::command('platform:audit-export-retry-resume-telemetry:cleanup --confirm --json')
    ->name('platform.audit-export-retry-resume-telemetry.cleanup')
    ->cron((string) config('platform_audit_retention.audit_export_retry_resume_telemetry.schedule.cron', '53 2 * * *'))
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/platform_audit_export_retry_resume_telemetry_cleanup.log'))
    ->when(fn (): bool => (bool) config('platform_audit_retention.audit_export_retry_resume_telemetry.schedule.enabled', false));

$auditExportRetryResumeTelemetryCleanupScheduleEnvironments = array_values(array_filter(array_map(
    static fn ($value): string => trim((string) $value),
    (array) config('platform_audit_retention.audit_export_retry_resume_telemetry.schedule.allowed_environments', ['production'])
), static fn (string $value): bool => $value !== ''));

if ($auditExportRetryResumeTelemetryCleanupScheduleEnvironments !== []) {
    $auditExportRetryResumeTelemetryCleanupScheduleEvent->environments($auditExportRetryResumeTelemetryCleanupScheduleEnvironments);
}
