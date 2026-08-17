<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Http\Middleware\ResolvePlatformScopeContext;
use App\Modules\Admission\Presentation\Http\Controllers\AdmissionController;
use App\Modules\Appointment\Presentation\Http\Controllers\AppointmentController;
use App\Modules\Authentication\Presentation\Http\Controllers\AuthenticatedUserController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingCorporateBillingController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingInvoiceController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingPayerContractController;
use App\Modules\Billing\Presentation\Http\Controllers\BillingPaymentPlanController;

use App\Modules\Billing\Presentation\Http\Controllers\PatientInsuranceController;
use App\Modules\ClaimsInsurance\Presentation\Http\Controllers\ClaimsInsuranceCaseController;
use App\Modules\Department\Presentation\Http\Controllers\DepartmentController;
use App\Modules\EmergencyTriage\Presentation\Http\Controllers\EmergencyTriageCaseController;
use App\Modules\InpatientWard\Presentation\Http\Controllers\InpatientWardController;
use App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventoryAnalyticsController;
use App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventoryDepartmentAccessController;
use App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventoryCategoryAdminController;
use App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventoryExtendedController;
use App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventoryItemUnitController;
use App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventoryProcurementController;
use App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventorySupplierController;
use App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventoryWarehouseController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterClinicalAttachmentController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterController;
use App\Modules\Encounter\Presentation\Http\Controllers\EncounterDiagnosisController;
use App\Modules\Laboratory\Presentation\Http\Controllers\LaboratoryOrderController;
use App\Modules\MedicalRecord\Presentation\Http\Controllers\MedicalRecordController;
use App\Modules\Patient\Presentation\Http\Controllers\PatientController;
use App\Modules\Patient\Presentation\Http\Controllers\PatientMedicationSafetyController;
use App\Modules\Pharmacy\Presentation\Http\Controllers\PharmacyOrderController;
use App\Modules\Pharmacy\Presentation\Http\Controllers\PharmacyReportsController;
use App\Modules\Platform\Presentation\Http\Controllers\DashboardContextController;
use App\Modules\Platform\Presentation\Http\Controllers\FacilityConfigurationController;
use App\Modules\Platform\Presentation\Http\Controllers\FacilityResourceRegistryController;
use App\Modules\Platform\Presentation\Http\Controllers\MultiFacilityRolloutController;
use App\Modules\Platform\Presentation\Http\Controllers\ClinicalCatalogPackagingTemplateController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformAdminController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformBrandingController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformClinicalCatalogController;
use App\Modules\PatientFlow\Presentation\Http\Controllers\PatientFlowController;
use App\Modules\Reception\Presentation\Http\Controllers\ReceptionController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformConfigurationController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformRbacController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformSubscriptionPlanController;
use App\Modules\PatientVitals\Presentation\Http\Controllers\PatientVitalSetController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformOperationalFlagController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformUserAdminController;
use App\Modules\Platform\Presentation\Http\Controllers\PlatformUserApprovalCaseController;
use App\Modules\Pos\Presentation\Http\Controllers\PosController;
use App\Modules\Radiology\Presentation\Http\Controllers\RadiologyOrderController;
use App\Modules\ClinicalProcedure\Presentation\Http\Controllers\ClinicalProcedureOrderController;
use App\Modules\ServiceRequest\Presentation\Http\Controllers\ServiceRequestController;
use App\Modules\Staff\Presentation\Http\Controllers\ClinicalPrivilegeCatalogController;
use App\Modules\Staff\Presentation\Http\Controllers\ClinicalSpecialtyController;
use App\Modules\Staff\Presentation\Http\Controllers\StaffCredentialingController;
use App\Modules\Staff\Presentation\Http\Controllers\StaffDocumentController;
use App\Modules\Staff\Presentation\Http\Controllers\AttendanceController;
use App\Modules\Staff\Presentation\Http\Controllers\StaffPrivilegeGrantController;
use App\Modules\Staff\Presentation\Http\Controllers\StaffProfileController;
use App\Modules\Staff\Presentation\Http\Controllers\StaffProfileSpecialtyController;
use App\Modules\TheatreProcedure\Presentation\Http\Controllers\TheatreProcedureController;
use Illuminate\Support\Facades\Route;

// API routes rely on route-level permission checks and direct user grants rather than
// the web-style active-role gate. Keep the tenant-scope middleware ahead of the
// auth/permission stack so platform scope resolution can still work for users
// without an active role assignment.
//
// LEGACY (2026-08-10): this file is now the generic/shared route surface —
// it predates the per-workspace frontend rebuild and existed before any
// `<workspace>/*`-prefixed routes did. Nothing here is deprecated or
// scheduled for removal (deleting it would break real callers: other
// workspaces' frontends still mid-migration, non-workspace API consumers,
// the routes some workspace controllers above reuse internally, etc.) —
// "legacy" means "the general-purpose surface", not "unused". New
// workspace-scoped frontend code must NOT call routes from this file
// directly, even when a route here is functionally identical to what it
// needs — add or use a `<workspace>/*`-prefixed route in
// routes/api-workspaces.php instead, reusing the same controller/use-case
// this file already calls (no logic duplication). See the memory note this
// rule is pinned under: "reception-frontend-must-use-reception-scoped-api".
Route::middleware(['web', 'auth', ResolvePlatformScopeContext::class, EnforceTenantIsolationWhenEnabled::class, 'session.limits', EnsureMappedFacilitySubscriptionEntitlement::class])->prefix('v1')->group(function (): void {
    Route::get('auth/csrf-token', function (\Illuminate\Http\Request $request) {
        $request->session()->regenerateToken();

        return response()->json([
            'token' => csrf_token(),
        ]);
    })->middleware('throttle:30,1')->name('auth.csrf-token');
    Route::get('dashboard/context', [DashboardContextController::class, 'show'])->name('dashboard.context');
    Route::get('auth/me', [AuthenticatedUserController::class, 'me'])->name('auth.me');
    Route::get('auth/me/permissions', [AuthenticatedUserController::class, 'permissions'])->name('auth.me.permissions');
    Route::get('auth/me/security-status', [AuthenticatedUserController::class, 'securityStatus'])->name('auth.me.security-status');
    Route::get('platform/access-scope', [PlatformConfigurationController::class, 'accessScope'])->name('platform.access-scope');
    Route::get('platform/country-profile', [PlatformConfigurationController::class, 'countryProfile'])->name('platform.country-profile');
    Route::get('platform/interoperability/adapter-envelope', [PlatformConfigurationController::class, 'interoperabilityAdapterEnvelope'])
        ->name('platform.interoperability.adapter-envelope');
    Route::get('platform/feature-flags/effective', [PlatformConfigurationController::class, 'effectiveFeatureFlags'])->name('platform.feature-flags.effective');
    Route::get('platform/feature-flags/{name}/effective', [PlatformConfigurationController::class, 'effectiveFeatureFlag'])->name('platform.feature-flags.effective-one');
    Route::get('platform/audit-export-jobs/health', [PlatformConfigurationController::class, 'auditExportJobsHealth'])->name('platform.audit-export-jobs.health');
    Route::get('platform/audit-export-jobs/health/drilldown', [PlatformConfigurationController::class, 'auditExportJobsHealthDrilldown'])->name('platform.audit-export-jobs.health.drilldown');
    Route::post('platform/audit-export-jobs/retry-resume-telemetry/events', [PlatformConfigurationController::class, 'recordAuditExportRetryResumeTelemetryEvent'])
        ->name('platform.audit-export-jobs.retry-resume-telemetry.events.store');
    Route::get('platform/audit-export-jobs/retry-resume-telemetry/health', [PlatformConfigurationController::class, 'auditExportRetryResumeTelemetryHealth'])
        ->name('platform.audit-export-jobs.retry-resume-telemetry.health');
    Route::get('platform/audit-export-jobs/retry-resume-telemetry/health/drilldown', [PlatformConfigurationController::class, 'auditExportRetryResumeTelemetryHealthDrilldown'])
        ->name('platform.audit-export-jobs.retry-resume-telemetry.health.drilldown');
    Route::get('platform/audit-export-jobs/retry-resume-telemetry/health/drilldown/export', [PlatformConfigurationController::class, 'exportAuditExportRetryResumeTelemetryHealthDrilldown'])
        ->name('platform.audit-export-jobs.retry-resume-telemetry.health.drilldown.export');
    Route::get('platform/feature-flag-overrides', [PlatformConfigurationController::class, 'featureFlagOverrides'])->name('platform.feature-flag-overrides');
    Route::post('platform/feature-flag-overrides', [PlatformConfigurationController::class, 'createFeatureFlagOverride'])
        ->middleware('can:platform.feature-flag-overrides.manage')
        ->name('platform.feature-flag-overrides.store');
    Route::patch('platform/feature-flag-overrides/{id}', [PlatformConfigurationController::class, 'updateFeatureFlagOverride'])
        ->middleware('can:platform.feature-flag-overrides.manage')
        ->name('platform.feature-flag-overrides.update');
    Route::delete('platform/feature-flag-overrides/{id}', [PlatformConfigurationController::class, 'deleteFeatureFlagOverride'])
        ->middleware('can:platform.feature-flag-overrides.manage')
        ->name('platform.feature-flag-overrides.delete');
    Route::get('platform/feature-flag-overrides/{id}/audit-logs', [PlatformConfigurationController::class, 'featureFlagOverrideAuditLogs'])
        ->middleware('can:platform.feature-flag-overrides.view-audit-logs')
        ->name('platform.feature-flag-overrides.audit-logs');
    Route::get('platform/feature-flags', [PlatformConfigurationController::class, 'featureFlags'])->name('platform.feature-flags');
    Route::post('platform/admin/branding', [PlatformBrandingController::class, 'update'])
        ->middleware('can:platform.settings.manage-branding')
        ->name('platform.admin.branding.update');
    Route::get('platform/admin/cross-tenant-audit-logs', [PlatformAdminController::class, 'crossTenantAuditLogs'])
        ->middleware('can:platform.cross-tenant.view-audit-logs')
        ->name('platform.admin.cross-tenant-audit-logs.index');
    Route::get('platform/admin/cross-tenant-audit-log-holds', [PlatformAdminController::class, 'crossTenantAuditLogHolds'])
        ->middleware('can:platform.cross-tenant.view-audit-holds')
        ->name('platform.admin.cross-tenant-audit-log-holds.index');
    Route::post('platform/admin/cross-tenant-audit-log-holds', [PlatformAdminController::class, 'createCrossTenantAuditLogHold'])
        ->middleware('can:platform.cross-tenant.manage-audit-holds')
        ->name('platform.admin.cross-tenant-audit-log-holds.store');
    Route::patch('platform/admin/cross-tenant-audit-log-holds/{id}/release', [PlatformAdminController::class, 'releaseCrossTenantAuditLogHold'])
        ->middleware('can:platform.cross-tenant.manage-audit-holds')
        ->name('platform.admin.cross-tenant-audit-log-holds.release');
    Route::get('platform/admin/admissions', [PlatformAdminController::class, 'admissions'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.admissions.index');
    Route::get('platform/admin/appointments', [PlatformAdminController::class, 'appointments'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.appointments.index');
    Route::get('platform/admin/patients', [PlatformAdminController::class, 'patients'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.patients.index');
    Route::get('platform/admin/billing', [PlatformAdminController::class, 'billingInvoices'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.billing-invoices.index');
    Route::get('platform/admin/laboratory-orders', [PlatformAdminController::class, 'laboratoryOrders'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.laboratory-orders.index');
    Route::get('platform/admin/pharmacy-orders', [PlatformAdminController::class, 'pharmacyOrders'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.pharmacy-orders.index');
    Route::get('platform/admin/clinical-procedure-orders', [PlatformAdminController::class, 'clinicalProcedureOrders'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.clinical-procedure-orders.index');
    Route::get('platform/admin/medical-records', [PlatformAdminController::class, 'medicalRecords'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.medical-records.index');
    Route::get('platform/admin/staff', [PlatformAdminController::class, 'staff'])
        ->middleware('can:platform.cross-tenant.read')
        ->name('platform.admin.staff.index');
    Route::get('platform/admin/permissions', [PlatformRbacController::class, 'permissions'])
        ->middleware('can:platform.rbac.read')
        ->name('platform.admin.permissions.index');
    Route::get('platform/admin/roles', [PlatformRbacController::class, 'roles'])
        ->middleware('can:platform.rbac.read')
        ->name('platform.admin.roles.index');
    Route::post('platform/admin/roles', [PlatformRbacController::class, 'storeRole'])
        ->middleware(['can:platform.rbac.manage-roles', 'throttle:30,1'])
        ->name('platform.admin.roles.store');
    Route::get('platform/admin/roles/{id}', [PlatformRbacController::class, 'role'])
        ->middleware('can:platform.rbac.read')
        ->name('platform.admin.roles.show');
    Route::patch('platform/admin/roles/{id}', [PlatformRbacController::class, 'updateRole'])
        ->middleware(['can:platform.rbac.manage-roles', 'throttle:30,1'])
        ->name('platform.admin.roles.update');
    Route::delete('platform/admin/roles/{id}', [PlatformRbacController::class, 'deleteRole'])
        ->middleware(['can:platform.rbac.manage-roles', 'throttle:30,1'])
        ->name('platform.admin.roles.delete');
    Route::patch('platform/admin/roles/{id}/permissions', [PlatformRbacController::class, 'syncRolePermissions'])
        ->middleware(['can:platform.rbac.manage-roles', 'throttle:30,1'])
        ->name('platform.admin.roles.sync-permissions');
    Route::get('platform/admin/users', [PlatformUserAdminController::class, 'index'])
        ->middleware('can:platform.users.read')
        ->name('platform.admin.users.index');
    Route::get('platform/admin/users/status-counts', [PlatformUserAdminController::class, 'statusCounts'])
        ->middleware('can:platform.users.read')
        ->name('platform.admin.users.status-counts');
    Route::post('platform/admin/users', [PlatformUserAdminController::class, 'store'])
        ->middleware(['can:platform.users.create', 'throttle:30,1'])
        ->name('platform.admin.users.store');
    Route::patch('platform/admin/users/bulk-status', [PlatformUserAdminController::class, 'bulkUpdateStatus'])
        ->middleware('can:platform.users.update-status')
        ->name('platform.admin.users.bulk-update-status');
    Route::patch('platform/admin/users/bulk-facilities', [PlatformUserAdminController::class, 'bulkSyncFacilities'])
        ->middleware('can:platform.users.manage-facilities')
        ->name('platform.admin.users.bulk-sync-facilities');
    Route::post('platform/admin/users/bulk-credential-links', [PlatformUserAdminController::class, 'bulkSendCredentialLinks'])
        ->middleware('can:platform.users.reset-password')
        ->name('platform.admin.users.bulk-credential-links');
    Route::patch('platform/admin/users/bulk-roles', [PlatformRbacController::class, 'bulkSyncUserRoles'])
        ->middleware('can:platform.rbac.manage-user-roles')
        ->name('platform.admin.users.bulk-sync-roles');
    Route::get('platform/admin/users/{id}', [PlatformUserAdminController::class, 'show'])
        ->middleware('can:platform.users.read')
        ->name('platform.admin.users.show');
    Route::patch('platform/admin/users/{id}', [PlatformUserAdminController::class, 'update'])
        ->middleware(['can:platform.users.update', 'throttle:30,1'])
        ->name('platform.admin.users.update');
    Route::patch('platform/admin/users/{id}/status', [PlatformUserAdminController::class, 'updateStatus'])
        ->middleware(['can:platform.users.update-status', 'throttle:30,1'])
        ->name('platform.admin.users.update-status');
    Route::patch('platform/admin/users/{id}/facilities', [PlatformUserAdminController::class, 'syncFacilities'])
        ->middleware(['can:platform.users.manage-facilities', 'throttle:30,1'])
        ->name('platform.admin.users.sync-facilities');
    Route::post('platform/admin/users/{id}/password-reset-link', [PlatformUserAdminController::class, 'sendPasswordResetLink'])
        ->middleware(['can:platform.users.reset-password', 'throttle:30,1'])
        ->name('platform.admin.users.password-reset-link');
    Route::post('platform/admin/users/{id}/invite-link', [PlatformUserAdminController::class, 'sendInviteLink'])
        ->middleware(['can:platform.users.reset-password', 'throttle:30,1'])
        ->name('platform.admin.users.invite-link');
    Route::get('platform/admin/users/{id}/audit-logs/export', [PlatformUserAdminController::class, 'exportAuditLogsCsv'])
        ->middleware('can:platform.users.view-audit-logs')
        ->name('platform.admin.users.audit-logs.export');
    Route::get('platform/admin/users/{id}/audit-logs', [PlatformUserAdminController::class, 'auditLogs'])
        ->middleware('can:platform.users.view-audit-logs')
        ->name('platform.admin.users.audit-logs');
    Route::patch('platform/admin/users/{userId}/roles', [PlatformRbacController::class, 'syncUserRoles'])
        ->middleware(['can:platform.rbac.manage-user-roles', 'throttle:30,1'])
        ->name('platform.admin.users.sync-roles');
    Route::get('platform/admin/user-approval-cases', [PlatformUserApprovalCaseController::class, 'index'])
        ->middleware('can:platform.users.approval-cases.read')
        ->name('platform.admin.user-approval-cases.index');
    Route::post('platform/admin/user-approval-cases', [PlatformUserApprovalCaseController::class, 'store'])
        ->middleware('can:platform.users.approval-cases.create')
        ->name('platform.admin.user-approval-cases.store');
    Route::get('platform/admin/user-approval-cases/{id}/comments', [PlatformUserApprovalCaseController::class, 'comments'])
        ->middleware('can:platform.users.approval-cases.read')
        ->name('platform.admin.user-approval-cases.comments.index');
    Route::post('platform/admin/user-approval-cases/{id}/comments', [PlatformUserApprovalCaseController::class, 'addComment'])
        ->middleware('can:platform.users.approval-cases.manage')
        ->name('platform.admin.user-approval-cases.comments.store');
    Route::patch('platform/admin/user-approval-cases/{id}/status', [PlatformUserApprovalCaseController::class, 'updateStatus'])
        ->middleware('can:platform.users.approval-cases.manage')
        ->name('platform.admin.user-approval-cases.update-status');
    Route::patch('platform/admin/user-approval-cases/{id}/decision', [PlatformUserApprovalCaseController::class, 'decide'])
        ->middleware('can:platform.users.approval-cases.review')
        ->name('platform.admin.user-approval-cases.decision');
    Route::get('platform/admin/user-approval-cases/{id}/audit-logs/export', [PlatformUserApprovalCaseController::class, 'exportAuditLogsCsv'])
        ->middleware('can:platform.users.approval-cases.view-audit-logs')
        ->name('platform.admin.user-approval-cases.audit-logs.export');
    Route::get('platform/admin/user-approval-cases/{id}/audit-logs', [PlatformUserApprovalCaseController::class, 'auditLogs'])
        ->middleware('can:platform.users.approval-cases.view-audit-logs')
        ->name('platform.admin.user-approval-cases.audit-logs');
    Route::get('platform/admin/user-approval-cases/{id}', [PlatformUserApprovalCaseController::class, 'show'])
        ->middleware('can:platform.users.approval-cases.read')
        ->name('platform.admin.user-approval-cases.show');
    Route::get('platform/admin/rbac-audit-logs', [PlatformRbacController::class, 'auditLogs'])
        ->middleware('can:platform.rbac.view-audit-logs')
        ->name('platform.admin.rbac-audit-logs.index');
    Route::get('platform/admin/service-points', [FacilityResourceRegistryController::class, 'servicePoints'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.service-points.index');
    Route::get('platform/admin/service-points/status-counts', [FacilityResourceRegistryController::class, 'servicePointStatusCounts'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.service-points.status-counts');
    Route::post('platform/admin/service-points', [FacilityResourceRegistryController::class, 'storeServicePoint'])
        ->middleware('can:platform.resources.manage-service-points')
        ->name('platform.admin.service-points.store');
    Route::get('platform/admin/service-points/{id}', [FacilityResourceRegistryController::class, 'servicePoint'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.service-points.show');
    Route::patch('platform/admin/service-points/{id}', [FacilityResourceRegistryController::class, 'updateServicePoint'])
        ->middleware('can:platform.resources.manage-service-points')
        ->name('platform.admin.service-points.update');
    Route::patch('platform/admin/service-points/{id}/status', [FacilityResourceRegistryController::class, 'updateServicePointStatus'])
        ->middleware('can:platform.resources.manage-service-points')
        ->name('platform.admin.service-points.update-status');
    Route::get('platform/admin/service-points/{id}/audit-logs/export', [FacilityResourceRegistryController::class, 'exportServicePointAuditLogsCsv'])
        ->middleware('can:platform.resources.view-audit-logs')
        ->name('platform.admin.service-points.audit-logs.export');
    Route::get('platform/admin/service-points/{id}/audit-logs', [FacilityResourceRegistryController::class, 'servicePointAuditLogs'])
        ->middleware('can:platform.resources.view-audit-logs')
        ->name('platform.admin.service-points.audit-logs');
    Route::get('platform/admin/ward-beds', [FacilityResourceRegistryController::class, 'wardBeds'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.ward-beds.index');
    Route::get('platform/admin/ward-beds/status-counts', [FacilityResourceRegistryController::class, 'wardBedStatusCounts'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.ward-beds.status-counts');
    Route::post('platform/admin/ward-beds', [FacilityResourceRegistryController::class, 'storeWardBed'])
        ->middleware('can:platform.resources.manage-ward-beds')
        ->name('platform.admin.ward-beds.store');
    Route::get('platform/admin/ward-beds/{id}', [FacilityResourceRegistryController::class, 'wardBed'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.ward-beds.show');
    Route::patch('platform/admin/ward-beds/{id}', [FacilityResourceRegistryController::class, 'updateWardBed'])
        ->middleware('can:platform.resources.manage-ward-beds')
        ->name('platform.admin.ward-beds.update');
    Route::patch('platform/admin/ward-beds/{id}/status', [FacilityResourceRegistryController::class, 'updateWardBedStatus'])
        ->middleware('can:platform.resources.manage-ward-beds')
        ->name('platform.admin.ward-beds.update-status');
    Route::get('platform/admin/ward-beds/{id}/audit-logs/export', [FacilityResourceRegistryController::class, 'exportWardBedAuditLogsCsv'])
        ->middleware('can:platform.resources.view-audit-logs')
        ->name('platform.admin.ward-beds.audit-logs.export');
    Route::get('platform/admin/ward-beds/{id}/audit-logs', [FacilityResourceRegistryController::class, 'wardBedAuditLogs'])
        ->middleware('can:platform.resources.view-audit-logs')
        ->name('platform.admin.ward-beds.audit-logs');
    Route::get('platform/admin/observation-rooms', [FacilityResourceRegistryController::class, 'observationRooms'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.observation-rooms.index');
    Route::get('platform/admin/observation-rooms/status-counts', [FacilityResourceRegistryController::class, 'observationRoomStatusCounts'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.observation-rooms.status-counts');
    Route::post('platform/admin/observation-rooms', [FacilityResourceRegistryController::class, 'storeObservationRoom'])
        ->middleware('can:platform.resources.manage-observation-rooms')
        ->name('platform.admin.observation-rooms.store');
    Route::get('platform/admin/observation-rooms/{id}', [FacilityResourceRegistryController::class, 'observationRoom'])
        ->middleware('can:platform.resources.read')
        ->name('platform.admin.observation-rooms.show');
    Route::patch('platform/admin/observation-rooms/{id}', [FacilityResourceRegistryController::class, 'updateObservationRoom'])
        ->middleware('can:platform.resources.manage-observation-rooms')
        ->name('platform.admin.observation-rooms.update');
    Route::patch('platform/admin/observation-rooms/{id}/status', [FacilityResourceRegistryController::class, 'updateObservationRoomStatus'])
        ->middleware('can:platform.resources.manage-observation-rooms')
        ->name('platform.admin.observation-rooms.update-status');
    Route::get('platform/admin/observation-rooms/{id}/audit-logs/export', [FacilityResourceRegistryController::class, 'exportObservationRoomAuditLogsCsv'])
        ->middleware('can:platform.resources.view-audit-logs')
        ->name('platform.admin.observation-rooms.audit-logs.export');
    Route::get('platform/admin/observation-rooms/{id}/audit-logs', [FacilityResourceRegistryController::class, 'observationRoomAuditLogs'])
        ->middleware('can:platform.resources.view-audit-logs')
        ->name('platform.admin.observation-rooms.audit-logs');

    Route::get('platform/operational-flags', [PlatformOperationalFlagController::class, 'status'])
        ->name('platform.operational-flags.status');
    Route::post('platform/operational-flags/{flagType}/activate', [PlatformOperationalFlagController::class, 'activate'])
        ->middleware('can:emergency.triage.update-status')
        ->name('platform.operational-flags.activate');
    Route::post('platform/operational-flags/{flagType}/deactivate', [PlatformOperationalFlagController::class, 'deactivate'])
        ->middleware('can:emergency.triage.update-status')
        ->name('platform.operational-flags.deactivate');

    Route::get('platform/admin/clinical-catalogs/type-counts', [PlatformClinicalCatalogController::class, 'typeCounts'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.type-counts');

    Route::get('platform/admin/clinical-catalogs/lab-tests', [PlatformClinicalCatalogController::class, 'labTests'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.lab-tests.index');
    Route::get('platform/admin/clinical-catalogs/lab-tests/status-counts', [PlatformClinicalCatalogController::class, 'labTestStatusCounts'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.lab-tests.status-counts');
    Route::get('platform/admin/clinical-catalogs/lab-tests/consumption-inventory-options', [PlatformClinicalCatalogController::class, 'labTestConsumptionInventoryOptions'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.lab-tests.consumption-inventory-options');
    Route::get('platform/admin/clinical-catalogs/lab-tests/export', [PlatformClinicalCatalogController::class, 'exportLabTestsCsv'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.lab-tests.export');
    Route::get('platform/admin/clinical-catalogs/lab-tests/import-template', [PlatformClinicalCatalogController::class, 'labTestsImportTemplate'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.lab-tests.import-template');
    Route::post('platform/admin/clinical-catalogs/lab-tests/bulk-import', [PlatformClinicalCatalogController::class, 'bulkImportLabTests'])
        ->middleware('can:platform.clinical-catalog.manage-lab-tests')
        ->name('platform.admin.clinical-catalogs.lab-tests.bulk-import');
    Route::patch('platform/admin/clinical-catalogs/lab-tests/bulk-status', [PlatformClinicalCatalogController::class, 'bulkUpdateLabTestStatus'])
        ->middleware('can:platform.clinical-catalog.manage-lab-tests')
        ->name('platform.admin.clinical-catalogs.lab-tests.bulk-status');
    Route::post('platform/admin/clinical-catalogs/lab-tests', [PlatformClinicalCatalogController::class, 'storeLabTest'])
        ->middleware('can:platform.clinical-catalog.manage-lab-tests')
        ->name('platform.admin.clinical-catalogs.lab-tests.store');
    Route::get('platform/admin/clinical-catalogs/lab-tests/{id}', [PlatformClinicalCatalogController::class, 'labTest'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.lab-tests.show');
    Route::get('platform/admin/clinical-catalogs/lab-tests/{id}/consumption-recipe', [PlatformClinicalCatalogController::class, 'labTestConsumptionRecipe'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.lab-tests.consumption-recipe.show');
    Route::put('platform/admin/clinical-catalogs/lab-tests/{id}/consumption-recipe', [PlatformClinicalCatalogController::class, 'syncLabTestConsumptionRecipe'])
        ->middleware('can:platform.clinical-catalog.manage-lab-tests')
        ->name('platform.admin.clinical-catalogs.lab-tests.consumption-recipe.sync');
    Route::patch('platform/admin/clinical-catalogs/lab-tests/{id}', [PlatformClinicalCatalogController::class, 'updateLabTest'])
        ->middleware('can:platform.clinical-catalog.manage-lab-tests')
        ->name('platform.admin.clinical-catalogs.lab-tests.update');
    Route::patch('platform/admin/clinical-catalogs/lab-tests/{id}/status', [PlatformClinicalCatalogController::class, 'updateLabTestStatus'])
        ->middleware('can:platform.clinical-catalog.manage-lab-tests')
        ->name('platform.admin.clinical-catalogs.lab-tests.update-status');
    Route::get('platform/admin/clinical-catalogs/lab-tests/{id}/audit-logs/export', [PlatformClinicalCatalogController::class, 'exportLabTestAuditLogsCsv'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.lab-tests.audit-logs.export');
    Route::get('platform/admin/clinical-catalogs/lab-tests/{id}/audit-logs', [PlatformClinicalCatalogController::class, 'labTestAuditLogs'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.lab-tests.audit-logs');

    Route::get('platform/admin/clinical-catalogs/radiology-procedures', [PlatformClinicalCatalogController::class, 'radiologyProcedures'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.index');
    Route::get('platform/admin/clinical-catalogs/radiology-procedures/status-counts', [PlatformClinicalCatalogController::class, 'radiologyProcedureStatusCounts'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.status-counts');
    Route::get('platform/admin/clinical-catalogs/radiology-procedures/consumption-inventory-options', [PlatformClinicalCatalogController::class, 'radiologyConsumptionInventoryOptions'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.consumption-inventory-options');
    Route::get('platform/admin/clinical-catalogs/radiology-procedures/export', [PlatformClinicalCatalogController::class, 'exportRadiologyProceduresCsv'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.export');
    Route::get('platform/admin/clinical-catalogs/radiology-procedures/import-template', [PlatformClinicalCatalogController::class, 'radiologyProceduresImportTemplate'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.import-template');
    Route::post('platform/admin/clinical-catalogs/radiology-procedures/bulk-import', [PlatformClinicalCatalogController::class, 'bulkImportRadiologyProcedures'])
        ->middleware('can:platform.clinical-catalog.manage-radiology-procedures')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.bulk-import');
    Route::patch('platform/admin/clinical-catalogs/radiology-procedures/bulk-status', [PlatformClinicalCatalogController::class, 'bulkUpdateRadiologyProcedureStatus'])
        ->middleware('can:platform.clinical-catalog.manage-radiology-procedures')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.bulk-status');
    Route::post('platform/admin/clinical-catalogs/radiology-procedures', [PlatformClinicalCatalogController::class, 'storeRadiologyProcedure'])
        ->middleware('can:platform.clinical-catalog.manage-radiology-procedures')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.store');
    Route::get('platform/admin/clinical-catalogs/radiology-procedures/{id}', [PlatformClinicalCatalogController::class, 'radiologyProcedure'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.show');
    Route::get('platform/admin/clinical-catalogs/radiology-procedures/{id}/consumption-recipe', [PlatformClinicalCatalogController::class, 'radiologyConsumptionRecipe'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.consumption-recipe.show');
    Route::put('platform/admin/clinical-catalogs/radiology-procedures/{id}/consumption-recipe', [PlatformClinicalCatalogController::class, 'syncRadiologyConsumptionRecipe'])
        ->middleware('can:platform.clinical-catalog.manage-radiology-procedures')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.consumption-recipe.sync');
    Route::patch('platform/admin/clinical-catalogs/radiology-procedures/{id}', [PlatformClinicalCatalogController::class, 'updateRadiologyProcedure'])
        ->middleware('can:platform.clinical-catalog.manage-radiology-procedures')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.update');
    Route::patch('platform/admin/clinical-catalogs/radiology-procedures/{id}/status', [PlatformClinicalCatalogController::class, 'updateRadiologyProcedureStatus'])
        ->middleware('can:platform.clinical-catalog.manage-radiology-procedures')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.update-status');
    Route::get('platform/admin/clinical-catalogs/radiology-procedures/{id}/audit-logs/export', [PlatformClinicalCatalogController::class, 'exportRadiologyProcedureAuditLogsCsv'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.audit-logs.export');
    Route::get('platform/admin/clinical-catalogs/radiology-procedures/{id}/audit-logs', [PlatformClinicalCatalogController::class, 'radiologyProcedureAuditLogs'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.radiology-procedures.audit-logs');

    Route::get('platform/admin/clinical-catalogs/theatre-procedures', [PlatformClinicalCatalogController::class, 'theatreProcedures'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.index');
    Route::get('platform/admin/clinical-catalogs/theatre-procedures/status-counts', [PlatformClinicalCatalogController::class, 'theatreProcedureStatusCounts'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.status-counts');
    Route::get('platform/admin/clinical-catalogs/theatre-procedures/consumption-inventory-options', [PlatformClinicalCatalogController::class, 'theatreConsumptionInventoryOptions'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.consumption-inventory-options');
    Route::get('platform/admin/clinical-catalogs/theatre-procedures/export', [PlatformClinicalCatalogController::class, 'exportTheatreProceduresCsv'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.export');
    Route::get('platform/admin/clinical-catalogs/theatre-procedures/import-template', [PlatformClinicalCatalogController::class, 'theatreProceduresImportTemplate'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.import-template');
    Route::post('platform/admin/clinical-catalogs/theatre-procedures/bulk-import', [PlatformClinicalCatalogController::class, 'bulkImportTheatreProcedures'])
        ->middleware('can:platform.clinical-catalog.manage-theatre-procedures')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.bulk-import');
    Route::patch('platform/admin/clinical-catalogs/theatre-procedures/bulk-status', [PlatformClinicalCatalogController::class, 'bulkUpdateTheatreProcedureStatus'])
        ->middleware('can:platform.clinical-catalog.manage-theatre-procedures')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.bulk-status');
    Route::post('platform/admin/clinical-catalogs/theatre-procedures', [PlatformClinicalCatalogController::class, 'storeTheatreProcedure'])
        ->middleware('can:platform.clinical-catalog.manage-theatre-procedures')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.store');
    Route::get('platform/admin/clinical-catalogs/theatre-procedures/{id}', [PlatformClinicalCatalogController::class, 'theatreProcedure'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.show');
    Route::get('platform/admin/clinical-catalogs/theatre-procedures/{id}/consumption-recipe', [PlatformClinicalCatalogController::class, 'theatreConsumptionRecipe'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.consumption-recipe.show');
    Route::put('platform/admin/clinical-catalogs/theatre-procedures/{id}/consumption-recipe', [PlatformClinicalCatalogController::class, 'syncTheatreConsumptionRecipe'])
        ->middleware('can:platform.clinical-catalog.manage-theatre-procedures')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.consumption-recipe.sync');
    Route::patch('platform/admin/clinical-catalogs/theatre-procedures/{id}', [PlatformClinicalCatalogController::class, 'updateTheatreProcedure'])
        ->middleware('can:platform.clinical-catalog.manage-theatre-procedures')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.update');
    Route::patch('platform/admin/clinical-catalogs/theatre-procedures/{id}/status', [PlatformClinicalCatalogController::class, 'updateTheatreProcedureStatus'])
        ->middleware('can:platform.clinical-catalog.manage-theatre-procedures')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.update-status');
    Route::get('platform/admin/clinical-catalogs/theatre-procedures/{id}/audit-logs/export', [PlatformClinicalCatalogController::class, 'exportTheatreProcedureAuditLogsCsv'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.audit-logs.export');
    Route::get('platform/admin/clinical-catalogs/theatre-procedures/{id}/audit-logs', [PlatformClinicalCatalogController::class, 'theatreProcedureAuditLogs'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.theatre-procedures.audit-logs');

    Route::get('platform/admin/clinical-catalogs/clinical-procedures', [PlatformClinicalCatalogController::class, 'clinicalProcedures'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.index');
    Route::get('platform/admin/clinical-catalogs/clinical-procedures/status-counts', [PlatformClinicalCatalogController::class, 'clinicalProcedureStatusCounts'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.status-counts');
    Route::get('platform/admin/clinical-catalogs/clinical-procedures/consumption-inventory-options', [PlatformClinicalCatalogController::class, 'clinicalProcedureConsumptionInventoryOptions'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.consumption-inventory-options');
    Route::get('platform/admin/clinical-catalogs/clinical-procedures/export', [PlatformClinicalCatalogController::class, 'exportClinicalProceduresCsv'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.export');
    Route::get('platform/admin/clinical-catalogs/clinical-procedures/import-template', [PlatformClinicalCatalogController::class, 'clinicalProceduresImportTemplate'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.import-template');
    Route::post('platform/admin/clinical-catalogs/clinical-procedures/bulk-import', [PlatformClinicalCatalogController::class, 'bulkImportClinicalProcedures'])
        ->middleware('can:platform.clinical-catalog.manage-clinical-procedures')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.bulk-import');
    Route::patch('platform/admin/clinical-catalogs/clinical-procedures/bulk-status', [PlatformClinicalCatalogController::class, 'bulkUpdateClinicalProcedureStatus'])
        ->middleware('can:platform.clinical-catalog.manage-clinical-procedures')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.bulk-status');
    Route::post('platform/admin/clinical-catalogs/clinical-procedures', [PlatformClinicalCatalogController::class, 'storeClinicalProcedure'])
        ->middleware('can:platform.clinical-catalog.manage-clinical-procedures')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.store');
    Route::get('platform/admin/clinical-catalogs/clinical-procedures/{id}', [PlatformClinicalCatalogController::class, 'clinicalProcedure'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.show');
    Route::get('platform/admin/clinical-catalogs/clinical-procedures/{id}/consumption-recipe', [PlatformClinicalCatalogController::class, 'clinicalProcedureConsumptionRecipe'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.consumption-recipe.show');
    Route::put('platform/admin/clinical-catalogs/clinical-procedures/{id}/consumption-recipe', [PlatformClinicalCatalogController::class, 'syncClinicalProcedureConsumptionRecipe'])
        ->middleware('can:platform.clinical-catalog.manage-clinical-procedures')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.consumption-recipe.sync');
    Route::patch('platform/admin/clinical-catalogs/clinical-procedures/{id}', [PlatformClinicalCatalogController::class, 'updateClinicalProcedure'])
        ->middleware('can:platform.clinical-catalog.manage-clinical-procedures')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.update');
    Route::patch('platform/admin/clinical-catalogs/clinical-procedures/{id}/status', [PlatformClinicalCatalogController::class, 'updateClinicalProcedureStatus'])
        ->middleware('can:platform.clinical-catalog.manage-clinical-procedures')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.update-status');
    Route::get('platform/admin/clinical-catalogs/clinical-procedures/{id}/audit-logs/export', [PlatformClinicalCatalogController::class, 'exportClinicalProcedureAuditLogsCsv'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.audit-logs.export');
    Route::get('platform/admin/clinical-catalogs/clinical-procedures/{id}/audit-logs', [PlatformClinicalCatalogController::class, 'clinicalProcedureAuditLogs'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.clinical-procedures.audit-logs');

    Route::get('platform/admin/clinical-catalogs/formulary-items', [PlatformClinicalCatalogController::class, 'formularyItems'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.formulary-items.index');
    Route::get('platform/admin/clinical-catalogs/formulary-items/status-counts', [PlatformClinicalCatalogController::class, 'formularyItemStatusCounts'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.formulary-items.status-counts');
    Route::get('platform/admin/clinical-catalogs/formulary-items/export', [PlatformClinicalCatalogController::class, 'exportFormularyItemsCsv'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.formulary-items.export');
    Route::get('platform/admin/clinical-catalogs/formulary-items/import-template', [PlatformClinicalCatalogController::class, 'formularyItemsImportTemplate'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.formulary-items.import-template');
    Route::post('platform/admin/clinical-catalogs/formulary-items/bulk-import', [PlatformClinicalCatalogController::class, 'bulkImportFormularyItems'])
        ->middleware('can:platform.clinical-catalog.manage-formulary')
        ->name('platform.admin.clinical-catalogs.formulary-items.bulk-import');
    Route::patch('platform/admin/clinical-catalogs/formulary-items/bulk-status', [PlatformClinicalCatalogController::class, 'bulkUpdateFormularyItemStatus'])
        ->middleware('can:platform.clinical-catalog.manage-formulary')
        ->name('platform.admin.clinical-catalogs.formulary-items.bulk-status');
    Route::post('platform/admin/clinical-catalogs/formulary-items', [PlatformClinicalCatalogController::class, 'storeFormularyItem'])
        ->middleware('can:platform.clinical-catalog.manage-formulary')
        ->name('platform.admin.clinical-catalogs.formulary-items.store');
    Route::get('platform/admin/clinical-catalogs/formulary-items/{id}', [PlatformClinicalCatalogController::class, 'formularyItem'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.formulary-items.show');
    Route::patch('platform/admin/clinical-catalogs/formulary-items/{id}', [PlatformClinicalCatalogController::class, 'updateFormularyItem'])
        ->middleware('can:platform.clinical-catalog.manage-formulary')
        ->name('platform.admin.clinical-catalogs.formulary-items.update');
    Route::patch('platform/admin/clinical-catalogs/formulary-items/{id}/status', [PlatformClinicalCatalogController::class, 'updateFormularyItemStatus'])
        ->middleware('can:platform.clinical-catalog.manage-formulary')
        ->name('platform.admin.clinical-catalogs.formulary-items.update-status');
    Route::get('platform/admin/clinical-catalogs/formulary-items/{id}/audit-logs/export', [PlatformClinicalCatalogController::class, 'exportFormularyItemAuditLogsCsv'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.formulary-items.audit-logs.export');
    Route::get('platform/admin/clinical-catalogs/formulary-items/{id}/audit-logs', [PlatformClinicalCatalogController::class, 'formularyItemAuditLogs'])
        ->middleware('can:platform.clinical-catalog.view-audit-logs')
        ->name('platform.admin.clinical-catalogs.formulary-items.audit-logs');

    // Inventory_MasterData_Alignment_Plan.md Phase 4: reusable packaging default per
    // formulary item, copied into inventory_item_units when an inventory item links to it.
    Route::get('platform/admin/clinical-catalogs/formulary-items/{id}/packaging-templates', [ClinicalCatalogPackagingTemplateController::class, 'index'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.formulary-items.packaging-templates.index');
    Route::post('platform/admin/clinical-catalogs/formulary-items/{id}/packaging-templates', [ClinicalCatalogPackagingTemplateController::class, 'store'])
        ->middleware('can:platform.clinical-catalog.manage-formulary')
        ->name('platform.admin.clinical-catalogs.formulary-items.packaging-templates.store');
    Route::delete('platform/admin/clinical-catalogs/formulary-items/{id}/packaging-templates/{templateId}', [ClinicalCatalogPackagingTemplateController::class, 'destroy'])
        ->middleware('can:platform.clinical-catalog.manage-formulary')
        ->name('platform.admin.clinical-catalogs.formulary-items.packaging-templates.destroy');

    Route::get('platform/admin/clinical-catalogs/sync-candidates', [PlatformClinicalCatalogController::class, 'syncCandidates'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.admin.clinical-catalogs.sync-candidates');

    Route::get('platform/catalog/by-department/{departmentId}', [PlatformClinicalCatalogController::class, 'byDepartment'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.clinical-catalogs.by-department');

    Route::get('platform/catalog/search', [PlatformClinicalCatalogController::class, 'search'])
        ->middleware('can:platform.clinical-catalog.read')
        ->name('platform.clinical-catalogs.search');

    Route::get('platform/admin/facilities', [FacilityConfigurationController::class, 'index'])
        ->middleware('can:platform.facilities.read')
        ->name('platform.admin.facilities.index');
    Route::get('platform/admin/facility-subscription-plans', [FacilityConfigurationController::class, 'subscriptionPlans'])
        ->middleware('can:platform.facilities.read')
        ->name('platform.admin.facilities.subscription-plans');
    Route::get('platform/admin/facility-admin-candidates', [FacilityConfigurationController::class, 'adminCandidates'])
        ->middleware(['can:platform.facilities.create', 'can:platform.users.read'])
        ->name('platform.admin.facilities.admin-candidates');
    Route::post('platform/admin/facilities', [FacilityConfigurationController::class, 'store'])
        ->middleware(['can:platform.facilities.create', 'throttle:30,1'])
        ->name('platform.admin.facilities.store');
    Route::get('platform/admin/facilities/{id}', [FacilityConfigurationController::class, 'show'])
        ->middleware('can:platform.facilities.read')
        ->name('platform.admin.facilities.show');
    Route::patch('platform/admin/facilities/{id}', [FacilityConfigurationController::class, 'update'])
        ->middleware(['can:platform.facilities.update', 'throttle:30,1'])
        ->name('platform.admin.facilities.update');
    Route::patch('platform/admin/facilities/{id}/status', [FacilityConfigurationController::class, 'updateStatus'])
        ->middleware(['can:platform.facilities.update-status', 'throttle:30,1'])
        ->name('platform.admin.facilities.update-status');
    Route::patch('platform/admin/facilities/{id}/owners', [FacilityConfigurationController::class, 'syncOwners'])
        ->middleware(['can:platform.facilities.manage-owners', 'throttle:30,1'])
        ->name('platform.admin.facilities.sync-owners');
    Route::get('platform/admin/facilities/{id}/subscription', [FacilityConfigurationController::class, 'subscription'])
        ->middleware('can:platform.facilities.read')
        ->name('platform.admin.facilities.subscription');
    Route::patch('platform/admin/facilities/{id}/subscription', [FacilityConfigurationController::class, 'updateSubscription'])
        ->middleware(['can:platform.facilities.manage-subscriptions', 'throttle:30,1'])
        ->name('platform.admin.facilities.update-subscription');
    Route::get('platform/admin/facilities/{id}/audit-logs/export', [FacilityConfigurationController::class, 'exportAuditLogsCsv'])
        ->middleware('can:platform.facilities.view-audit-logs')
        ->name('platform.admin.facilities.audit-logs.export');
    Route::get('platform/admin/facilities/{id}/audit-logs', [FacilityConfigurationController::class, 'auditLogs'])
        ->middleware('can:platform.facilities.view-audit-logs')
        ->name('platform.admin.facilities.audit-logs');
    Route::get('platform/admin/service-plans', [PlatformSubscriptionPlanController::class, 'index'])
        ->middleware('can:platform.subscription-plans.read')
        ->name('platform.admin.service-plans.index');
    Route::get('platform/admin/service-plans/{id}/audit-logs', [PlatformSubscriptionPlanController::class, 'auditLogs'])
        ->middleware('can:platform.subscription-plans.view-audit-logs')
        ->name('platform.admin.service-plans.audit-logs');
    Route::get('platform/admin/service-plans/{id}', [PlatformSubscriptionPlanController::class, 'show'])
        ->middleware('can:platform.subscription-plans.read')
        ->name('platform.admin.service-plans.show');
    Route::patch('platform/admin/service-plans/{id}', [PlatformSubscriptionPlanController::class, 'update'])
        ->middleware('can:platform.subscription-plans.manage')
        ->name('platform.admin.service-plans.update');
    Route::post('platform/admin/service-plans/{id}/entitlements', [PlatformSubscriptionPlanController::class, 'storeEntitlement'])
        ->middleware(['can:platform.subscription-plans.manage', 'throttle:30,1'])
        ->name('platform.admin.service-plans.entitlements.store');
    Route::patch('platform/admin/service-plans/{id}/entitlements/{entitlementId}', [PlatformSubscriptionPlanController::class, 'updateEntitlement'])
        ->middleware('can:platform.subscription-plans.manage')
        ->name('platform.admin.service-plans.entitlements.update');
    Route::get('platform/admin/facility-rollouts', [MultiFacilityRolloutController::class, 'index'])
        ->middleware('can:platform.multi-facility.read')
        ->name('platform.admin.facility-rollouts.index');
    Route::post('platform/admin/facility-rollouts', [MultiFacilityRolloutController::class, 'store'])
        ->middleware('can:platform.multi-facility.manage-rollouts')
        ->name('platform.admin.facility-rollouts.store');
    Route::patch('platform/admin/facility-rollouts/{id}/checkpoints', [MultiFacilityRolloutController::class, 'upsertCheckpoints'])
        ->middleware('can:platform.multi-facility.manage-rollouts')
        ->name('platform.admin.facility-rollouts.checkpoints');
    Route::post('platform/admin/facility-rollouts/{id}/incidents', [MultiFacilityRolloutController::class, 'createIncident'])
        ->middleware('can:platform.multi-facility.manage-incidents')
        ->name('platform.admin.facility-rollouts.incidents.store');
    Route::patch('platform/admin/facility-rollouts/{id}/incidents/{incidentId}', [MultiFacilityRolloutController::class, 'updateIncident'])
        ->middleware('can:platform.multi-facility.manage-incidents')
        ->name('platform.admin.facility-rollouts.incidents.update');
    Route::post('platform/admin/facility-rollouts/{id}/rollback', [MultiFacilityRolloutController::class, 'executeRollback'])
        ->middleware('can:platform.multi-facility.execute-rollback')
        ->name('platform.admin.facility-rollouts.rollback');
    Route::patch('platform/admin/facility-rollouts/{id}/acceptance', [MultiFacilityRolloutController::class, 'updateAcceptance'])
        ->middleware('can:platform.multi-facility.approve-acceptance')
        ->name('platform.admin.facility-rollouts.acceptance');
    Route::get('platform/admin/facility-rollouts/{id}/audit-logs/export', [MultiFacilityRolloutController::class, 'exportAuditLogsCsv'])
        ->middleware('can:platform.multi-facility.view-audit-logs')
        ->name('platform.admin.facility-rollouts.audit-logs.export');
    Route::get('platform/admin/facility-rollouts/{id}/audit-logs', [MultiFacilityRolloutController::class, 'auditLogs'])
        ->middleware('can:platform.multi-facility.view-audit-logs')
        ->name('platform.admin.facility-rollouts.audit-logs');
    Route::get('platform/admin/facility-rollouts/{id}', [MultiFacilityRolloutController::class, 'show'])
        ->middleware('can:platform.multi-facility.read')
        ->name('platform.admin.facility-rollouts.show');
    Route::patch('platform/admin/facility-rollouts/{id}', [MultiFacilityRolloutController::class, 'update'])
        ->middleware('can:platform.multi-facility.manage-rollouts')
        ->name('platform.admin.facility-rollouts.update');

    Route::get('patients', [PatientController::class, 'index'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.index');
    Route::get('patients/status-counts', [PatientController::class, 'statusCounts'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.status-counts');
    Route::post('patients', [PatientController::class, 'store'])
        ->middleware(['can:patients.create', 'facility.entitlement:patients.registration'])
        ->name('patients.store');
    Route::post('patients/duplicate-check', [PatientController::class, 'checkDuplicates'])
        ->middleware(['can:patients.create', 'facility.entitlement:patients.registration'])
        ->name('patients.duplicate-check');
    Route::get('patients/export/csv', [PatientController::class, 'exportCsv'])
        ->middleware(['can:patients.export', 'facility.entitlement:patients.search'])
        ->name('patients.export.csv');
    Route::get('patients/import-template', [PatientController::class, 'importTemplate'])
        ->middleware(['can:patients.export', 'facility.entitlement:patients.search'])
        ->name('patients.import-template');
    Route::post('patients/bulk-import', [PatientController::class, 'bulkImport'])
        ->middleware(['can:patients.import', 'facility.entitlement:patients.registration'])
        ->name('patients.bulk-import');
    Route::get('patients/insurance-options', [PatientInsuranceController::class, 'options'])
        ->middleware(['can:patients.insurance.read', 'facility.entitlement:patients.search'])
        ->name('patients.insurance.options');
    Route::get('patients/{id}', [PatientController::class, 'show'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.show');
    Route::patch('patients/{id}', [PatientController::class, 'update'])
        ->middleware(['can:patient.demographics.update', 'facility.entitlement:patients.demographics'])
        ->name('patients.update');
    Route::patch('patients/{id}/status', [PatientController::class, 'updateStatus'])
        ->middleware(['can:patients.update-status', 'facility.entitlement:patients.demographics'])
        ->name('patients.update-status');
    Route::get('patients/{id}/activity-feed', [PatientController::class, 'activityFeed'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.activity-feed');
    Route::get('patients/{id}/summary', [PatientController::class, 'summary'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.summary');
    Route::get('patients/{id}/audit-logs/export', [PatientController::class, 'exportAuditLogsCsv'])
        ->middleware(['can:patients.view-audit-logs', 'facility.entitlement:patients.search'])
        ->name('patients.audit-logs.export');
    Route::get('patients/{id}/audit-logs', [PatientController::class, 'auditLogs'])
        ->middleware(['can:patients.view-audit-logs', 'facility.entitlement:patients.search'])
        ->name('patients.audit-logs');
    Route::get('patients/{id}/insurance', [PatientInsuranceController::class, 'index'])
        ->middleware(['can:patients.insurance.read', 'facility.entitlement:patients.search'])
        ->name('patients.insurance.index');
    Route::post('patients/{id}/insurance', [PatientInsuranceController::class, 'store'])
        ->middleware(['can:patients.insurance.manage', 'facility.entitlement:patients.demographics'])
        ->name('patients.insurance.store');
    Route::patch('patients/{id}/insurance/{recordId}', [PatientInsuranceController::class, 'update'])
        ->middleware(['can:patients.insurance.manage', 'facility.entitlement:patients.demographics'])
        ->name('patients.insurance.update');
    Route::patch('patients/{id}/insurance/{recordId}/verify', [PatientInsuranceController::class, 'verify'])
        ->middleware(['can:patients.insurance.verify', 'facility.entitlement:patients.demographics'])
        ->name('patients.insurance.verify');
    Route::delete('patients/{id}/insurance/{recordId}', [PatientInsuranceController::class, 'destroy'])
        ->middleware(['can:patients.insurance.manage', 'facility.entitlement:patients.demographics'])
        ->name('patients.insurance.destroy');
    Route::get('patients/{id}/insurance/audit-events', [PatientInsuranceController::class, 'auditEvents'])
        ->middleware(['can:patients.insurance.view-audit-logs', 'facility.entitlement:patients.search'])
        ->name('patients.insurance.audit-events');
    Route::get('patients/{id}/allergies', [PatientMedicationSafetyController::class, 'allergies'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.allergies.index');
    Route::post('patients/{id}/allergies', [PatientMedicationSafetyController::class, 'storeAllergy'])
        ->middleware(['can:patient.allergies.manage', 'facility.entitlement:patients.demographics'])
        ->name('patients.allergies.store');
    Route::patch('patients/{id}/allergies/{allergyId}', [PatientMedicationSafetyController::class, 'updateAllergy'])
        ->middleware(['can:patient.allergies.manage', 'facility.entitlement:patients.demographics'])
        ->name('patients.allergies.update');
    Route::get('patients/{id}/medication-profile', [PatientMedicationSafetyController::class, 'medicationProfile'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.medication-profile.index');
    Route::post('patients/{id}/medication-profile', [PatientMedicationSafetyController::class, 'storeMedicationProfile'])
        ->middleware(['can:patient.medications.manage', 'facility.entitlement:patients.demographics'])
        ->name('patients.medication-profile.store');
    Route::patch('patients/{id}/medication-profile/{medicationId}', [PatientMedicationSafetyController::class, 'updateMedicationProfile'])
        ->middleware(['can:patient.medications.manage', 'facility.entitlement:patients.demographics'])
        ->name('patients.medication-profile.update');
    Route::get('patients/{id}/medication-safety-summary', [PatientMedicationSafetyController::class, 'medicationSafetySummary'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.medication-safety-summary');
    Route::get('patients/{id}/medication-reconciliation', [PatientMedicationSafetyController::class, 'medicationReconciliation'])
        ->middleware(['can:patients.read', 'facility.entitlement:patients.search'])
        ->name('patients.medication-reconciliation');

    Route::get('appointments', [AppointmentController::class, 'index'])
        ->middleware('can:appointments.read')
        ->name('appointments.index');
    Route::get('appointments/status-counts', [AppointmentController::class, 'statusCounts'])
        ->middleware('can:appointments.read')
        ->name('appointments.status-counts');
    Route::get('appointments/department-options', [AppointmentController::class, 'departmentOptions'])
        ->middleware('can:appointments.read-routing-options')
        ->name('appointments.department-options');
    Route::get('appointments/referrals/network', [AppointmentController::class, 'referralNetwork'])
        ->middleware('can:appointments.read')
        ->name('appointments.referrals.network');
    Route::get('appointments/referrals/network/status-counts', [AppointmentController::class, 'referralNetworkStatusCounts'])
        ->middleware('can:appointments.read')
        ->name('appointments.referrals.network.status-counts');
    Route::post('appointments', [AppointmentController::class, 'store'])
        ->middleware('can:appointments.create')
        ->name('appointments.store');
    Route::get('appointments/{id}', [AppointmentController::class, 'show'])
        ->middleware('can:appointments.read')
        ->name('appointments.show');
    Route::get('appointments/{id}/encounter', [AppointmentController::class, 'encounter'])
        ->middleware(['can:medical.records.read', 'can:medical.records.create'])
        ->name('appointments.encounter');
    Route::patch('appointments/{id}', [AppointmentController::class, 'update'])
        ->middleware('can:appointment.reschedule')
        ->name('appointments.update');
    Route::patch('appointments/{id}/triage', [AppointmentController::class, 'recordTriage'])
        ->middleware('can:appointments.record-triage')
        ->name('appointments.record-triage');
    Route::patch('appointments/{id}/claim-triage', [AppointmentController::class, 'claimTriage'])
        ->middleware('can:appointments.record-triage')
        ->name('appointments.claim-triage');
    Route::patch('appointments/{id}/release-triage-claim', [AppointmentController::class, 'releaseTriageClaim'])
        ->middleware('can:appointments.record-triage')
        ->name('appointments.release-triage-claim');
    Route::patch('appointments/{id}/start-consultation', [AppointmentController::class, 'startConsultation'])
        ->middleware('can:appointments.start-consultation')
        ->name('appointments.start-consultation');
    Route::patch('appointments/{id}/provider-workflow', [AppointmentController::class, 'updateProviderWorkflow'])
        ->middleware('can:appointments.manage-provider-session')
        ->name('appointments.manage-provider-session');
    Route::patch('appointments/{id}/status', [AppointmentController::class, 'updateStatus'])
        ->middleware('can:appointment.check-in')
        ->name('appointments.update-status');
    Route::patch('appointments/{id}/check-in', [ReceptionController::class, 'checkIn'])
        ->middleware('can:appointment.check-in')
        ->name('appointments.check-in');
    Route::post('reception/walk-ins', [ReceptionController::class, 'registerWalkIn'])
        ->middleware(['can:appointments.create', 'can:appointment.check-in'])
        ->name('reception.walk-ins.store');
    Route::get('reception/queue', [ReceptionController::class, 'queue'])
        ->middleware('can:appointments.read')
        ->name('reception.queue');
    Route::get('reception/queue/status-counts', [ReceptionController::class, 'queueStatusCounts'])
        ->middleware('can:appointments.read')
        ->name('reception.queue.status-counts');
    Route::get('reception/triage-queue/status-counts', [ReceptionController::class, 'triageQueueStatusCounts'])
        ->middleware('can:appointments.read')
        ->name('reception.triage-queue.status-counts');
    Route::get('reception/triage-queue/completed-today', [ReceptionController::class, 'triageCompletedToday'])
        ->middleware('can:appointments.read')
        ->name('reception.triage-queue.completed-today');
    Route::get('reception/clinician-queue/status-counts', [ReceptionController::class, 'clinicianQueueStatusCounts'])
        ->middleware('can:appointments.read')
        ->name('reception.clinician-queue.status-counts');
    Route::get('reception/workspace/appointments/{id}', [ReceptionController::class, 'workspaceSnapshot'])
        ->middleware('can:appointments.read')
        ->name('reception.workspace.snapshot');
    Route::get('patient-flow/board', [PatientFlowController::class, 'board'])
        ->middleware('can:appointments.read')
        ->name('patient-flow.board');
    Route::get('patient-flow/notifications', [PatientFlowController::class, 'notifications'])
        ->middleware('can:appointments.read')
        ->name('patient-flow.notifications');
    Route::get('patient-flow/clinician-directory', [PatientFlowController::class, 'clinicianDirectory'])
        ->middleware('can:appointments.read')
        ->name('patient-flow.clinician-directory');
    Route::patch('appointments/{id}/consultation-type', [AppointmentController::class, 'overrideConsultationType'])
        ->middleware('can:appointment.reschedule')
        ->name('appointments.override-consultation-type');
    Route::get('appointments/analytics/consultation-type-summary', [AppointmentController::class, 'consultationTypeSummary'])
        ->middleware('can:appointments.read')
        ->name('appointments.analytics.consultation-type-summary');
    Route::get('appointments/{id}/referrals', [AppointmentController::class, 'referrals'])
        ->middleware('can:appointments.read')
        ->name('appointments.referrals.index');
    Route::get('appointments/{id}/referral-status-counts', [AppointmentController::class, 'referralStatusCounts'])
        ->middleware('can:appointments.read')
        ->name('appointments.referrals.status-counts');
    Route::post('appointments/{id}/referrals', [AppointmentController::class, 'storeReferral'])
        ->middleware('can:appointments.manage-referrals')
        ->name('appointments.referrals.store');
    Route::patch('appointments/{id}/referrals/{referralId}', [AppointmentController::class, 'updateReferral'])
        ->middleware('can:appointments.manage-referrals')
        ->name('appointments.referrals.update');
    Route::patch('appointments/{id}/referrals/{referralId}/status', [AppointmentController::class, 'updateReferralStatus'])
        ->middleware('can:appointments.manage-referrals')
        ->name('appointments.referrals.update-status');
    Route::get('appointments/{id}/referrals/{referralId}/audit-logs/export', [AppointmentController::class, 'exportReferralAuditLogsCsv'])
        ->middleware('can:appointments.view-referral-audit-logs')
        ->name('appointments.referrals.audit-logs.export');
    Route::get('appointments/{id}/referrals/{referralId}/audit-logs', [AppointmentController::class, 'referralAuditLogs'])
        ->middleware('can:appointments.view-referral-audit-logs')
        ->name('appointments.referrals.audit-logs');
    Route::get('appointments/{id}/audit-logs/export', [AppointmentController::class, 'exportAuditLogsCsv'])
        ->middleware('can:appointments.view-audit-logs')
        ->name('appointments.audit-logs.export');
    Route::get('appointments/{id}/audit-logs', [AppointmentController::class, 'auditLogs'])
        ->middleware('can:appointments.view-audit-logs')
        ->name('appointments.audit-logs');

    Route::get('admissions', [AdmissionController::class, 'index'])
        ->middleware('can:admissions.read')
        ->name('admissions.index');
    Route::get('admissions/status-counts', [AdmissionController::class, 'statusCounts'])
        ->middleware('can:admissions.read')
        ->name('admissions.status-counts');
    Route::get('admissions/discharge-destination-options', [AdmissionController::class, 'dischargeDestinationOptions'])
        ->middleware('can:admissions.read')
        ->name('admissions.discharge-destination-options');
    Route::get('admissions/available-beds', [AdmissionController::class, 'availableBeds'])
        ->middleware('can:admissions.read')
        ->name('admissions.available-beds.index');
    Route::post('admissions', [AdmissionController::class, 'store'])
        ->middleware('can:admissions.create')
        ->name('admissions.store');
    Route::get('admissions/{id}', [AdmissionController::class, 'show'])
        ->middleware('can:admissions.read')
        ->name('admissions.show');
    Route::patch('admissions/{id}', [AdmissionController::class, 'update'])
        ->middleware('can:admissions.update')
        ->name('admissions.update');
    Route::patch('admissions/{id}/status', [AdmissionController::class, 'updateStatus'])
        ->middleware('can:admissions.update-status')
        ->name('admissions.update-status');
    Route::get('admissions/{id}/audit-logs/export', [AdmissionController::class, 'exportAuditLogsCsv'])
        ->middleware('can:admissions.view-audit-logs')
        ->name('admissions.audit-logs.export');
    Route::get('admissions/{id}/audit-logs', [AdmissionController::class, 'auditLogs'])
        ->middleware('can:admissions.view-audit-logs')
        ->name('admissions.audit-logs');

    Route::get('medical-records', [MedicalRecordController::class, 'index'])
        ->middleware('can:medical.records.read')
        ->name('medical-records.index');
    Route::get('medical-records/status-counts', [MedicalRecordController::class, 'statusCounts'])
        ->middleware('can:medical.records.read')
        ->name('medical-records.status-counts');
    Route::post('medical-records', [MedicalRecordController::class, 'store'])
        ->middleware('can:medical.records.create')
        ->name('medical-records.store');
    Route::get('medical-records/{id}', [MedicalRecordController::class, 'show'])
        ->middleware('can:medical.records.read')
        ->name('medical-records.show');
    Route::patch('medical-records/{id}', [MedicalRecordController::class, 'update'])
        ->middleware('can:medical.records.draft.update,id')
        ->name('medical-records.update');
    Route::patch('medical-records/{id}/status', [MedicalRecordController::class, 'updateStatus'])
        ->middleware('can:medical.records.update-status')
        ->name('medical-records.update-status');
    Route::get('medical-records/{id}/audit-logs/export', [MedicalRecordController::class, 'exportAuditLogsCsv'])
        ->middleware('can:medical.records.audit-logs.view')
        ->name('medical-records.audit-logs.export');
    Route::get('medical-records/{id}/audit-logs', [MedicalRecordController::class, 'auditLogs'])
        ->middleware('can:medical.records.audit-logs.view')
        ->name('medical-records.audit-logs');
    Route::get('medical-records/{id}/versions', [MedicalRecordController::class, 'versions'])
        ->middleware('can:medical.records.read')
        ->name('medical-records.versions.index');
    Route::get('medical-records/{id}/versions/{versionId}/diff', [MedicalRecordController::class, 'versionDiff'])
        ->middleware('can:medical.records.read')
        ->name('medical-records.versions.diff');
    Route::get('medical-records/{id}/signer-attestations', [MedicalRecordController::class, 'signerAttestations'])
        ->middleware('can:medical.records.read')
        ->name('medical-records.signer-attestations.index');
    Route::post('medical-records/{id}/signer-attestations', [MedicalRecordController::class, 'storeSignerAttestation'])
        ->middleware('can:medical.records.attest')
        ->name('medical-records.signer-attestations.store');
    Route::post('medical-records/{id}/handoff', [MedicalRecordController::class, 'handoff'])
        ->middleware('can:medical.records.create')
        ->name('medical-records.handoff');
    Route::post('medical-records/{id}/handoff/accept', [MedicalRecordController::class, 'acceptHandoff'])
        ->middleware('can:medical.records.handoff.accept')
        ->name('medical-records.handoff.accept');
    Route::post('medical-records/{id}/handoff/cancel', [MedicalRecordController::class, 'cancelHandoff'])
        ->middleware('can:medical.records.handoff.cancel')
        ->name('medical-records.handoff.cancel');

    Route::get('encounters', [EncounterController::class, 'index'])
        ->middleware('can:medical.records.read')
        ->name('encounters.index');
    Route::get('encounters/status-counts', [EncounterController::class, 'statusCounts'])
        ->middleware('can:medical.records.read')
        ->name('encounters.status-counts');
    Route::get('encounters/by-appointment/{appointmentId}', [EncounterController::class, 'resolveForAppointment'])
        ->middleware('can:medical.records.read')
        ->name('encounters.by-appointment.resolve');
    Route::get('encounters/{id}', [EncounterController::class, 'show'])
        ->middleware('can:medical.records.read')
        ->name('encounters.show');
    Route::patch('encounters/{id}/status', [EncounterController::class, 'updateStatus'])
        ->middleware('can:medical.records.update-status')
        ->name('encounters.update-status');
    Route::get('encounters/{id}/audit-logs/export', [EncounterController::class, 'exportAuditLogsCsv'])
        ->middleware('can:medical.records.audit-logs.view')
        ->name('encounters.audit-logs.export');
    Route::get('encounters/{id}/audit-logs', [EncounterController::class, 'auditLogs'])
        ->middleware('can:medical.records.audit-logs.view')
        ->name('encounters.audit-logs');
    Route::get('encounters/{id}/clinical-documents', [EncounterClinicalAttachmentController::class, 'index'])
        ->middleware('can:medical.records.read')
        ->name('encounters.clinical-documents.index');
    Route::post('encounters/{id}/clinical-documents', [EncounterClinicalAttachmentController::class, 'store'])
        ->middleware('can:medical.records.create')
        ->name('encounters.clinical-documents.store');
    Route::get('encounters/{id}/clinical-documents/{documentId}', [EncounterClinicalAttachmentController::class, 'show'])
        ->middleware('can:medical.records.read')
        ->name('encounters.clinical-documents.show');
    Route::patch('encounters/{id}/clinical-documents/{documentId}', [EncounterClinicalAttachmentController::class, 'update'])
        ->middleware('can:medical.records.update')
        ->name('encounters.clinical-documents.update');
    Route::patch('encounters/{id}/clinical-documents/{documentId}/status', [EncounterClinicalAttachmentController::class, 'updateStatus'])
        ->middleware('can:medical.records.update')
        ->name('encounters.clinical-documents.update-status');
    Route::get('encounters/{id}/clinical-documents/{documentId}/download', [EncounterClinicalAttachmentController::class, 'download'])
        ->middleware('can:medical.records.read')
        ->name('encounters.clinical-documents.download');
    Route::post('encounters/{id}/diagnoses', [EncounterDiagnosisController::class, 'store'])
        ->middleware('can:medical.records.create')
        ->name('encounters.diagnoses.store');
    Route::delete('encounters/{id}/diagnoses/{diagnosisId}', [EncounterDiagnosisController::class, 'destroy'])
        ->middleware('can:medical.records.update')
        ->name('encounters.diagnoses.destroy');

    Route::get('laboratory-orders', [LaboratoryOrderController::class, 'index'])
        ->middleware('can:laboratory.orders.read')
        ->name('laboratory-orders.index');
    Route::get('laboratory-orders/status-counts', [LaboratoryOrderController::class, 'statusCounts'])
        ->middleware('can:laboratory.orders.read')
        ->name('laboratory-orders.status-counts');
    Route::get('laboratory-orders/duplicate-check', [LaboratoryOrderController::class, 'duplicateCheck'])
        ->middleware('can:lab.order')
        ->name('laboratory-orders.duplicate-check');
    Route::post('laboratory-orders', [LaboratoryOrderController::class, 'store'])
        ->middleware('can:lab.order')
        ->name('laboratory-orders.store');
    Route::get('laboratory-orders/{id}', [LaboratoryOrderController::class, 'show'])
        ->middleware('can:laboratory.orders.read')
        ->name('laboratory-orders.show');
    Route::patch('laboratory-orders/{id}', [LaboratoryOrderController::class, 'update'])
        ->middleware('can:lab.order')
        ->name('laboratory-orders.update');
    Route::post('laboratory-orders/{id}/sign', [LaboratoryOrderController::class, 'sign'])
        ->middleware('can:lab.order')
        ->name('laboratory-orders.sign');
    Route::delete('laboratory-orders/{id}/draft', [LaboratoryOrderController::class, 'discardDraft'])
        ->middleware('can:lab.order')
        ->name('laboratory-orders.discard-draft');
    Route::patch('laboratory-orders/{id}/status', [LaboratoryOrderController::class, 'updateStatus'])
        ->middleware('can:lab.sample.collect')
        ->name('laboratory-orders.update-status');
    Route::post('laboratory-orders/{id}/lifecycle', [LaboratoryOrderController::class, 'applyLifecycleAction'])
        ->middleware('can:lab.order')
        ->name('laboratory-orders.lifecycle');
    Route::patch('laboratory-orders/{id}/verify', [LaboratoryOrderController::class, 'verifyResult'])
        ->middleware('can:lab.result.verify')
        ->name('laboratory-orders.verify-result');
    Route::post('laboratory-orders/{id}/audit-logs/export-jobs', [LaboratoryOrderController::class, 'createAuditLogsCsvExportJob'])
        ->middleware('can:laboratory.orders.audit-logs.view')
        ->name('laboratory-orders.audit-logs.export-jobs.create');
    Route::get('laboratory-orders/{id}/audit-logs/export-jobs', [LaboratoryOrderController::class, 'auditLogsCsvExportJobs'])
        ->middleware('can:laboratory.orders.audit-logs.view')
        ->name('laboratory-orders.audit-logs.export-jobs.index');
    Route::get('laboratory-orders/{id}/audit-logs/export-jobs/{jobId}', [LaboratoryOrderController::class, 'auditLogsCsvExportJob'])
        ->middleware('can:laboratory.orders.audit-logs.view')
        ->name('laboratory-orders.audit-logs.export-jobs.show');
    Route::post('laboratory-orders/{id}/audit-logs/export-jobs/{jobId}/retry', [LaboratoryOrderController::class, 'retryAuditLogsCsvExportJob'])
        ->middleware('can:laboratory.orders.audit-logs.view')
        ->name('laboratory-orders.audit-logs.export-jobs.retry');
    Route::get('laboratory-orders/{id}/audit-logs/export-jobs/{jobId}/download', [LaboratoryOrderController::class, 'downloadAuditLogsCsvExportJob'])
        ->middleware('can:laboratory.orders.audit-logs.view')
        ->name('laboratory-orders.audit-logs.export-jobs.download');
    Route::get('laboratory-orders/{id}/audit-logs/export', [LaboratoryOrderController::class, 'exportAuditLogsCsv'])
        ->middleware('can:laboratory.orders.audit-logs.view')
        ->name('laboratory-orders.audit-logs.export');
    Route::get('laboratory-orders/{id}/audit-logs', [LaboratoryOrderController::class, 'auditLogs'])
        ->middleware('can:laboratory.orders.audit-logs.view')
        ->name('laboratory-orders.audit-logs');

    Route::get('billing', [BillingInvoiceController::class, 'index'])
        ->middleware('can:billing.invoices.read')
        ->name('billing-invoices.index');
    Route::get('billing/cashier-queue', [BillingInvoiceController::class, 'cashierQueue'])
        ->middleware('can:billing.invoices.read')
        ->name('billing-invoices.cashier-queue');
    Route::get('billing/status-counts', [BillingInvoiceController::class, 'statusCounts'])
        ->middleware('can:billing.invoices.read')
        ->name('billing-invoices.status-counts');
    Route::get('billing/charge-capture-candidates', [BillingInvoiceController::class, 'chargeCaptureCandidates'])
        ->middleware('can:billing.invoices.read')
        ->name('billing-invoices.charge-capture-candidates');
    Route::get('billing/financial-controls/summary', [BillingInvoiceController::class, 'financialControlsSummary'])
        ->middleware('can:billing.financial-controls.read')
        ->name('billing-invoices.financial-controls.summary');
    Route::get('billing/financial-controls/revenue-recognition-summary', [BillingInvoiceController::class, 'revenueRecognitionSummary'])
        ->middleware('can:billing.financial-controls.read')
        ->name('billing-invoices.financial-controls.revenue-recognition-summary');
    Route::get('billing/financial-controls/department-options', [BillingInvoiceController::class, 'financialControlsDepartmentOptions'])
        ->middleware('can:billing.financial-controls.read')
        ->name('billing-invoices.financial-controls.department-options');
    Route::get('billing/financial-controls/summary/export', [BillingInvoiceController::class, 'exportFinancialControlsSummaryCsv'])
        ->middleware('can:billing.financial-controls.read')
        ->name('billing-invoices.financial-controls.summary.export');
    Route::post('billing', [BillingInvoiceController::class, 'store'])
        ->middleware('can:billing.invoices.create')
        ->name('billing-invoices.store');
    Route::post('billing/preview', [BillingInvoiceController::class, 'preview'])
        ->middleware('can:billing.invoices.create')
        ->name('billing-invoices.preview');
    Route::get('billing/{id}', [BillingInvoiceController::class, 'show'])
        ->middleware('can:billing.invoices.read')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.show');
    Route::get('billing/{id}/finance-posting', [BillingInvoiceController::class, 'financePostingSummary'])
        ->middleware('can:billing.invoices.read')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.finance-posting');
    Route::patch('billing/{id}', [BillingInvoiceController::class, 'update'])
        ->middleware('can:billing.invoices.update-draft')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.update');
    Route::patch('billing/{id}/status', [BillingInvoiceController::class, 'updateStatus'])
        ->middleware('can:billing.invoices.update-status')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.update-status');
    Route::post('billing/{id}/payments', [BillingInvoiceController::class, 'recordPayment'])
        ->middleware('can:billing.payments.record')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.record-payment');
    Route::post('billing/{id}/payments/{paymentId}/reversals', [BillingInvoiceController::class, 'reversePayment'])
        ->middleware('can:billing.payments.reverse')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->where('paymentId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.reverse-payment');
    Route::get('billing/{id}/payments', [BillingInvoiceController::class, 'payments'])
        ->middleware('can:billing.payments.view-history')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.payments');
    Route::post('billing/{id}/audit-logs/export-jobs', [BillingInvoiceController::class, 'createAuditLogsCsvExportJob'])
        ->middleware('can:billing.invoices.audit-logs.view')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.audit-logs.export-jobs.create');
    Route::get('billing/{id}/audit-logs/export-jobs', [BillingInvoiceController::class, 'auditLogsCsvExportJobs'])
        ->middleware('can:billing.invoices.audit-logs.view')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.audit-logs.export-jobs.index');
    Route::get('billing/{id}/audit-logs/export-jobs/{jobId}', [BillingInvoiceController::class, 'auditLogsCsvExportJob'])
        ->middleware('can:billing.invoices.audit-logs.view')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->where('jobId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.audit-logs.export-jobs.show');
    Route::post('billing/{id}/audit-logs/export-jobs/{jobId}/retry', [BillingInvoiceController::class, 'retryAuditLogsCsvExportJob'])
        ->middleware('can:billing.invoices.audit-logs.view')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->where('jobId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.audit-logs.export-jobs.retry');
    Route::get('billing/{id}/audit-logs/export-jobs/{jobId}/download', [BillingInvoiceController::class, 'downloadAuditLogsCsvExportJob'])
        ->middleware('can:billing.invoices.audit-logs.view')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->where('jobId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.audit-logs.export-jobs.download');
    Route::get('billing/{id}/audit-logs/export', [BillingInvoiceController::class, 'exportAuditLogsCsv'])
        ->middleware('can:billing.invoices.audit-logs.view')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.audit-logs.export');
    Route::get('billing/{id}/audit-logs', [BillingInvoiceController::class, 'auditLogs'])
        ->middleware('can:billing.invoices.audit-logs.view')
        ->where('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]+')
        ->name('billing-invoices.audit-logs');

    Route::get('billing-payment-plans', [BillingPaymentPlanController::class, 'index'])
        ->middleware('can:billing.invoices.read')
        ->name('billing-payment-plans.index');
    Route::post('billing-payment-plans', [BillingPaymentPlanController::class, 'store'])
        ->middleware('can:billing.payments.record')
        ->name('billing-payment-plans.store');
    Route::get('billing-payment-plans/{id}', [BillingPaymentPlanController::class, 'show'])
        ->middleware('can:billing.invoices.read')
        ->name('billing-payment-plans.show');
    Route::post('billing-payment-plans/{id}/payments', [BillingPaymentPlanController::class, 'recordPayment'])
        ->middleware('can:billing.payments.record')
        ->name('billing-payment-plans.record-payment');

    Route::get('billing-corporate-accounts', [BillingCorporateBillingController::class, 'index'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-corporate-accounts.index');
    Route::post('billing-corporate-accounts', [BillingCorporateBillingController::class, 'storeAccount'])
        ->middleware('can:billing.payer-contracts.manage')
        ->name('billing-corporate-accounts.store');
    Route::get('billing-corporate-accounts/{id}', [BillingCorporateBillingController::class, 'showAccount'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-corporate-accounts.show');
    Route::get('billing-corporate-accounts/{id}/runs', [BillingCorporateBillingController::class, 'runs'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-corporate-accounts.runs');
    Route::post('billing-corporate-accounts/{id}/runs', [BillingCorporateBillingController::class, 'storeRun'])
        ->middleware('can:billing.payer-contracts.manage')
        ->name('billing-corporate-accounts.runs.store');
    Route::get('billing-corporate-runs/{runId}', [BillingCorporateBillingController::class, 'showRun'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-corporate-runs.show');
    Route::post('billing-corporate-runs/{runId}/payments', [BillingCorporateBillingController::class, 'recordRunPayment'])
        ->middleware('can:billing.payments.record')
        ->name('billing-corporate-runs.payments.store');

    Route::get('pos/registers', [PosController::class, 'registers'])
        ->middleware('can:pos.registers.read')
        ->name('pos.registers.index');
    Route::post('pos/registers', [PosController::class, 'storeRegister'])
        ->middleware('can:pos.registers.manage')
        ->name('pos.registers.store');
    Route::get('pos/registers/{id}', [PosController::class, 'showRegister'])
        ->middleware('can:pos.registers.read')
        ->name('pos.registers.show');
    Route::patch('pos/registers/{id}', [PosController::class, 'updateRegister'])
        ->middleware('can:pos.registers.manage')
        ->name('pos.registers.update');
    Route::get('pos/sessions', [PosController::class, 'sessions'])
        ->middleware('can:pos.sessions.read')
        ->name('pos.sessions.index');
    Route::post('pos/registers/{id}/sessions', [PosController::class, 'openSession'])
        ->middleware('can:pos.sessions.manage')
        ->name('pos.sessions.store');
    Route::get('pos/sessions/{id}', [PosController::class, 'showSession'])
        ->middleware('can:pos.sessions.read')
        ->name('pos.sessions.show');
    Route::patch('pos/sessions/{id}/close', [PosController::class, 'closeSession'])
        ->middleware('can:pos.sessions.manage')
        ->name('pos.sessions.close');
    Route::get('pos/sales', [PosController::class, 'sales'])
        ->middleware('can:pos.sales.read')
        ->name('pos.sales.index');
    Route::get('pos/cafeteria/catalog', [PosController::class, 'cafeteriaCatalog'])
        ->middleware('can:pos.cafeteria.read')
        ->name('pos.cafeteria.catalog');
    Route::post('pos/cafeteria/catalog', [PosController::class, 'storeCafeteriaCatalogItem'])
        ->middleware('can:pos.cafeteria.manage-catalog')
        ->name('pos.cafeteria.catalog.store');
    Route::get('pos/cafeteria/catalog/{id}', [PosController::class, 'showCafeteriaCatalogItem'])
        ->middleware('can:pos.cafeteria.read')
        ->name('pos.cafeteria.catalog.show');
    Route::patch('pos/cafeteria/catalog/{id}', [PosController::class, 'updateCafeteriaCatalogItem'])
        ->middleware('can:pos.cafeteria.manage-catalog')
        ->name('pos.cafeteria.catalog.update');
    Route::get('pos/pharmacy-otc/catalog', [PosController::class, 'pharmacyOtcCatalog'])
        ->middleware('can:pos.pharmacy-otc.read')
        ->name('pos.pharmacy-otc.catalog');
    Route::post('pos/pharmacy-otc/sales', [PosController::class, 'storePharmacyOtcSale'])
        ->middleware('can:pos.pharmacy-otc.create')
        ->name('pos.pharmacy-otc.sales.store');
    Route::get('pos/lab-quick/candidates', [PosController::class, 'labQuickCandidates'])
        ->middleware('can:pos.lab-quick.read')
        ->name('pos.lab-quick.candidates.index');
    Route::post('pos/lab-quick/sales', [PosController::class, 'storeLabQuickSale'])
        ->middleware('can:pos.lab-quick.create')
        ->name('pos.lab-quick.sales.store');
    Route::get('pos/frontdesk-quick/candidates', [PosController::class, 'frontdeskQuickCandidates'])
        ->middleware('can:pos.frontdesk-quick.read')
        ->name('pos.frontdesk-quick.candidates.index');
    Route::post('pos/frontdesk-quick/sales', [PosController::class, 'storeFrontdeskQuickSale'])
        ->middleware('can:pos.frontdesk-quick.create')
        ->name('pos.frontdesk-quick.sales.store');
    Route::get('pos/frontdesk-quick/verify/{sourceKind}/{orderId}', [PosController::class, 'verifyFrontdeskQuickPayment'])
        ->middleware('can:pos.frontdesk-quick.read')
        ->name('pos.frontdesk-quick.verify');
    Route::post('pos/cafeteria/sales', [PosController::class, 'storeCafeteriaSale'])
        ->middleware('can:pos.cafeteria.create')
        ->name('pos.cafeteria.sales.store');
    Route::post('pos/sales', [PosController::class, 'storeSale'])
        ->middleware('can:pos.sales.create')
        ->name('pos.sales.store');
    Route::get('pos/sales/{id}', [PosController::class, 'showSale'])
        ->middleware('can:pos.sales.read')
        ->name('pos.sales.show');
    Route::post('pos/sales/{id}/void', [PosController::class, 'voidSale'])
        ->middleware('can:pos.sales.void')
        ->name('pos.sales.void');
    Route::post('pos/sales/{id}/refund', [PosController::class, 'refundSale'])
        ->middleware('can:pos.sales.refund')
        ->name('pos.sales.refund');



    Route::get('billing-payer-contracts', [BillingPayerContractController::class, 'index'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-payer-contracts.index');
    Route::get('billing-payer-contracts/status-counts', [BillingPayerContractController::class, 'statusCounts'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-payer-contracts.status-counts');
    Route::post('billing-payer-contracts', [BillingPayerContractController::class, 'store'])
        ->middleware('can:billing.payer-contracts.manage')
        ->name('billing-payer-contracts.store');
    Route::get('billing-payer-contracts/{id}', [BillingPayerContractController::class, 'show'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-payer-contracts.show');
    Route::patch('billing-payer-contracts/{id}', [BillingPayerContractController::class, 'update'])
        ->middleware('can:billing.payer-contracts.manage')
        ->name('billing-payer-contracts.update');
    Route::patch('billing-payer-contracts/{id}/status', [BillingPayerContractController::class, 'updateStatus'])
        ->middleware('can:billing.payer-contracts.manage')
        ->name('billing-payer-contracts.update-status');
    Route::get('billing-payer-contracts/{id}/audit-logs/export', [BillingPayerContractController::class, 'exportAuditLogsCsv'])
        ->middleware('can:billing.payer-contracts.view-audit-logs')
        ->name('billing-payer-contracts.audit-logs.export');
    Route::get('billing-payer-contracts/{id}/audit-logs', [BillingPayerContractController::class, 'auditLogs'])
        ->middleware('can:billing.payer-contracts.view-audit-logs')
        ->name('billing-payer-contracts.audit-logs');

    Route::get('billing-payer-contracts/{id}/price-overrides', [BillingPayerContractController::class, 'priceOverrides'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-payer-contracts.price-overrides.index');
    Route::post('billing-payer-contracts/{id}/price-overrides', [BillingPayerContractController::class, 'storePriceOverride'])
        ->middleware('can:billing.payer-contracts.manage-price-overrides')
        ->name('billing-payer-contracts.price-overrides.store');
    Route::patch('billing-payer-contracts/{id}/price-overrides/{overrideId}', [BillingPayerContractController::class, 'updatePriceOverride'])
        ->middleware('can:billing.payer-contracts.manage-price-overrides')
        ->name('billing-payer-contracts.price-overrides.update');
    Route::patch('billing-payer-contracts/{id}/price-overrides/{overrideId}/status', [BillingPayerContractController::class, 'updatePriceOverrideStatus'])
        ->middleware('can:billing.payer-contracts.manage-price-overrides')
        ->name('billing-payer-contracts.price-overrides.update-status');
    Route::get('billing-payer-contracts/{id}/price-overrides/{overrideId}/audit-logs/export', [BillingPayerContractController::class, 'exportPriceOverrideAuditLogsCsv'])
        ->middleware('can:billing.payer-contracts.view-price-override-audit-logs')
        ->name('billing-payer-contracts.price-overrides.audit-logs.export');
    Route::get('billing-payer-contracts/{id}/price-overrides/{overrideId}/audit-logs', [BillingPayerContractController::class, 'priceOverrideAuditLogs'])
        ->middleware('can:billing.payer-contracts.view-price-override-audit-logs')
        ->name('billing-payer-contracts.price-overrides.audit-logs');

    Route::get('billing-payer-contracts/{id}/authorization-rules', [BillingPayerContractController::class, 'authorizationRules'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-payer-contracts.authorization-rules.index');
    Route::get('billing-payer-contracts/{id}/authorization-rules/summary', [BillingPayerContractController::class, 'authorizationRuleSummary'])
        ->middleware('can:billing.payer-contracts.read')
        ->name('billing-payer-contracts.authorization-rules.summary');
    Route::post('billing-payer-contracts/{id}/authorization-rules', [BillingPayerContractController::class, 'storeAuthorizationRule'])
        ->middleware('can:billing.payer-contracts.manage-authorization-rules')
        ->name('billing-payer-contracts.authorization-rules.store');
    Route::patch('billing-payer-contracts/{id}/authorization-rules/{ruleId}', [BillingPayerContractController::class, 'updateAuthorizationRule'])
        ->middleware('can:billing.payer-contracts.manage-authorization-rules')
        ->name('billing-payer-contracts.authorization-rules.update');
    Route::patch('billing-payer-contracts/{id}/authorization-rules/{ruleId}/status', [BillingPayerContractController::class, 'updateAuthorizationRuleStatus'])
        ->middleware('can:billing.payer-contracts.manage-authorization-rules')
        ->name('billing-payer-contracts.authorization-rules.update-status');
    Route::get('billing-payer-contracts/{id}/authorization-rules/{ruleId}/audit-logs/export', [BillingPayerContractController::class, 'exportAuthorizationRuleAuditLogsCsv'])
        ->middleware('can:billing.payer-contracts.view-authorization-audit-logs')
        ->name('billing-payer-contracts.authorization-rules.audit-logs.export');
    Route::get('billing-payer-contracts/{id}/authorization-rules/{ruleId}/audit-logs', [BillingPayerContractController::class, 'authorizationRuleAuditLogs'])
        ->middleware('can:billing.payer-contracts.view-authorization-audit-logs')
        ->name('billing-payer-contracts.authorization-rules.audit-logs');

    Route::get('pharmacy-orders', [PharmacyOrderController::class, 'index'])
        ->middleware('can:pharmacy.orders.read')
        ->name('pharmacy-orders.index');
    Route::get('pharmacy-orders/status-counts', [PharmacyOrderController::class, 'statusCounts'])
        ->middleware('can:pharmacy.orders.read')
        ->name('pharmacy-orders.status-counts');
    Route::get('pharmacy-orders/availability', [PharmacyOrderController::class, 'availability'])
        ->middleware('can:pharmacy.orders.read')
        ->name('pharmacy-orders.availability');
    Route::get('pharmacy-orders/approved-medicines-catalog', [PharmacyOrderController::class, 'approvedMedicinesCatalog'])
        ->middleware('can:pharmacy.orders.read')
        ->name('pharmacy-orders.approved-medicines-catalog');
    Route::get('pharmacy-orders/duplicate-check', [PharmacyOrderController::class, 'duplicateCheck'])
        ->middleware('can:medication.prescribe')
        ->name('pharmacy-orders.duplicate-check');
    Route::post('pharmacy-orders', [PharmacyOrderController::class, 'store'])
        ->middleware('can:medication.prescribe')
        ->name('pharmacy-orders.store');
    Route::get('pharmacy-orders/{id}', [PharmacyOrderController::class, 'show'])
        ->middleware('can:pharmacy.orders.read')
        ->name('pharmacy-orders.show');
    Route::get('pharmacy-orders/{id}/safety-review', [PharmacyOrderController::class, 'safetyReview'])
        ->middleware('can:pharmacy.orders.read')
        ->name('pharmacy-orders.safety-review');
    Route::patch('pharmacy-orders/{id}', [PharmacyOrderController::class, 'update'])
        ->middleware('can:medication.prescribe')
        ->name('pharmacy-orders.update');
    Route::delete('pharmacy-orders/{id}/draft', [PharmacyOrderController::class, 'discardDraft'])
        ->middleware('can:medication.prescribe')
        ->name('pharmacy-orders.discard-draft');
    Route::patch('pharmacy-orders/{id}/status', [PharmacyOrderController::class, 'updateStatus'])
        ->middleware('can:medication.dispense')
        ->name('pharmacy-orders.update-status');
    Route::post('pharmacy-orders/{id}/lifecycle', [PharmacyOrderController::class, 'applyLifecycleAction'])
        ->middleware('can:medication.prescribe')
        ->name('pharmacy-orders.lifecycle');
    Route::patch('pharmacy-orders/{id}/policy', [PharmacyOrderController::class, 'updatePolicy'])
        ->middleware('can:pharmacy.orders.manage-policy')
        ->name('pharmacy-orders.update-policy');
    Route::patch('pharmacy-orders/{id}/reconciliation', [PharmacyOrderController::class, 'reconcile'])
        ->middleware('can:pharmacy.orders.reconcile')
        ->name('pharmacy-orders.reconcile');
    Route::patch('pharmacy-orders/{id}/verify', [PharmacyOrderController::class, 'verifyDispense'])
        ->middleware('can:pharmacy.orders.verify-dispense')
        ->name('pharmacy-orders.verify-dispense');
    Route::post('pharmacy-orders/{id}/audit-logs/export-jobs', [PharmacyOrderController::class, 'createAuditLogsCsvExportJob'])
        ->middleware('can:pharmacy.orders.audit-logs.view')
        ->name('pharmacy-orders.audit-logs.export-jobs.create');
    Route::get('pharmacy-orders/{id}/audit-logs/export-jobs', [PharmacyOrderController::class, 'auditLogsCsvExportJobs'])
        ->middleware('can:pharmacy.orders.audit-logs.view')
        ->name('pharmacy-orders.audit-logs.export-jobs.index');
    Route::get('pharmacy-orders/{id}/audit-logs/export-jobs/{jobId}', [PharmacyOrderController::class, 'auditLogsCsvExportJob'])
        ->middleware('can:pharmacy.orders.audit-logs.view')
        ->name('pharmacy-orders.audit-logs.export-jobs.show');
    Route::post('pharmacy-orders/{id}/audit-logs/export-jobs/{jobId}/retry', [PharmacyOrderController::class, 'retryAuditLogsCsvExportJob'])
        ->middleware('can:pharmacy.orders.audit-logs.view')
        ->name('pharmacy-orders.audit-logs.export-jobs.retry');
    Route::get('pharmacy-orders/{id}/audit-logs/export-jobs/{jobId}/download', [PharmacyOrderController::class, 'downloadAuditLogsCsvExportJob'])
        ->middleware('can:pharmacy.orders.audit-logs.view')
        ->name('pharmacy-orders.audit-logs.export-jobs.download');
    Route::get('pharmacy-orders/{id}/audit-logs/export', [PharmacyOrderController::class, 'exportAuditLogsCsv'])
        ->middleware('can:pharmacy.orders.audit-logs.view')
        ->name('pharmacy-orders.audit-logs.export');
    Route::get('pharmacy-orders/{id}/audit-logs', [PharmacyOrderController::class, 'auditLogs'])
        ->middleware('can:pharmacy.orders.audit-logs.view')
        ->name('pharmacy-orders.audit-logs');

    // ─── Pharmacy Reports ────────────────────────────────────────
    Route::prefix('pharmacy-reports')->middleware('can:pharmacy.orders.read')->group(function () {
        Route::get('inventory/stock-status', [PharmacyReportsController::class, 'stockStatus'])->name('pharmacy-reports.inventory.stock-status');
        Route::get('inventory/low-stock', [PharmacyReportsController::class, 'lowStock'])->name('pharmacy-reports.inventory.low-stock');
        Route::get('inventory/out-of-stock', [PharmacyReportsController::class, 'outOfStock'])->name('pharmacy-reports.inventory.out-of-stock');
        Route::get('inventory/near-expiry', [PharmacyReportsController::class, 'nearExpiry'])->name('pharmacy-reports.inventory.near-expiry');
        Route::get('inventory/expired', [PharmacyReportsController::class, 'expired'])->name('pharmacy-reports.inventory.expired');
        Route::get('dispensing/dispensed-medicines', [PharmacyReportsController::class, 'dispensedMedicines'])->name('pharmacy-reports.dispensing.dispensed-medicines');
        Route::get('dispensing/batch-tracking', [PharmacyReportsController::class, 'batchTracking'])->name('pharmacy-reports.dispensing.batch-tracking');
        Route::get('dispensing/by-clinician', [PharmacyReportsController::class, 'medicinesByClinician'])->name('pharmacy-reports.dispensing.by-clinician');
        Route::get('compliance/controlled-drugs', [PharmacyReportsController::class, 'controlledDrugsRegister'])->name('pharmacy-reports.compliance.controlled-drugs');
        Route::get('compliance/insurance-claims', [PharmacyReportsController::class, 'insuranceClaims'])->name('pharmacy-reports.compliance.insurance-claims');
        Route::get('analytics/prescription-trends', [PharmacyReportsController::class, 'prescriptionTrends'])->name('pharmacy-reports.analytics.prescription-trends');
        Route::get('analytics/medicine-consumption', [PharmacyReportsController::class, 'medicineConsumption'])->name('pharmacy-reports.analytics.medicine-consumption');
        Route::get('dashboard-kpis', [PharmacyReportsController::class, 'dashboardKpis'])->name('pharmacy-reports.dashboard-kpis');
    });

    Route::get('radiology-orders', [RadiologyOrderController::class, 'index'])
        ->middleware('can:radiology.orders.read')
        ->name('radiology-orders.index');
    Route::get('radiology-orders/status-counts', [RadiologyOrderController::class, 'statusCounts'])
        ->middleware('can:radiology.orders.read')
        ->name('radiology-orders.status-counts');
    Route::get('radiology-orders/duplicate-check', [RadiologyOrderController::class, 'duplicateCheck'])
        ->middleware('can:imaging.order')
        ->name('radiology-orders.duplicate-check');
    Route::post('radiology-orders', [RadiologyOrderController::class, 'store'])
        ->middleware('can:imaging.order')
        ->name('radiology-orders.store');
    Route::get('radiology-orders/{id}', [RadiologyOrderController::class, 'show'])
        ->middleware('can:radiology.orders.read')
        ->name('radiology-orders.show');
    // `radiology.orders.update` is granted to no role in config/roles.php, so
    // amending a radiology order was unreachable for every user. Guarded like
    // its laboratory twin (PATCH laboratory-orders/{id} → `lab.order`): whoever
    // may place the order may correct it.
    Route::patch('radiology-orders/{id}', [RadiologyOrderController::class, 'update'])
        ->middleware('can:imaging.order')
        ->name('radiology-orders.update');
    Route::post('radiology-orders/{id}/sign', [RadiologyOrderController::class, 'sign'])
        ->middleware('can:imaging.order')
        ->name('radiology-orders.sign');
    Route::delete('radiology-orders/{id}/draft', [RadiologyOrderController::class, 'discardDraft'])
        ->middleware('can:imaging.order')
        ->name('radiology-orders.discard-draft');
    Route::patch('radiology-orders/{id}/status', [RadiologyOrderController::class, 'updateStatus'])
        ->middleware('can:imaging.perform')
        ->name('radiology-orders.update-status');
    Route::post('radiology-orders/{id}/lifecycle', [RadiologyOrderController::class, 'applyLifecycleAction'])
        ->middleware('can:imaging.order')
        ->name('radiology-orders.lifecycle');
    // Releases a completed report to the patient chart. Kept behind
    // `imaging.result.verify`, which only RADIOLOGY.SUPERVISOR holds — the same
    // seniority split that keeps lab result verification with supervisors.
    Route::patch('radiology-orders/{id}/verify', [RadiologyOrderController::class, 'verifyResult'])
        ->middleware('can:imaging.result.verify')
        ->name('radiology-orders.verify-result');
    // The ability roles.php actually grants is `radiology.orders.audit-logs.view`;
    // these two demanded `radiology.orders.view-audit-logs`, the same words in a
    // different order, which no role holds — so radiology audit logs were closed
    // to everyone. Matches the laboratory twin's `laboratory.orders.audit-logs.view`.
    Route::get('radiology-orders/{id}/audit-logs/export', [RadiologyOrderController::class, 'exportAuditLogsCsv'])
        ->middleware('can:radiology.orders.audit-logs.view')
        ->name('radiology-orders.audit-logs.export');
    Route::get('radiology-orders/{id}/audit-logs', [RadiologyOrderController::class, 'auditLogs'])
        ->middleware('can:radiology.orders.audit-logs.view')
        ->name('radiology-orders.audit-logs');

    Route::get('clinical-procedure-orders', [ClinicalProcedureOrderController::class, 'index'])
        ->middleware('can:clinical-procedure.orders.read')
        ->name('clinical-procedure-orders.index');
    Route::get('clinical-procedure-orders/status-counts', [ClinicalProcedureOrderController::class, 'statusCounts'])
        ->middleware('can:clinical-procedure.orders.read')
        ->name('clinical-procedure-orders.status-counts');
    Route::get('clinical-procedure-orders/duplicate-check', [ClinicalProcedureOrderController::class, 'duplicateCheck'])
        ->middleware('can:clinical-procedure.order')
        ->name('clinical-procedure-orders.duplicate-check');
    Route::post('clinical-procedure-orders', [ClinicalProcedureOrderController::class, 'store'])
        ->middleware('can:clinical-procedure.order')
        ->name('clinical-procedure-orders.store');
    Route::get('clinical-procedure-orders/{id}', [ClinicalProcedureOrderController::class, 'show'])
        ->middleware('can:clinical-procedure.orders.read')
        ->name('clinical-procedure-orders.show');
    Route::patch('clinical-procedure-orders/{id}', [ClinicalProcedureOrderController::class, 'update'])
        ->middleware('can:clinical-procedure.orders.update')
        ->name('clinical-procedure-orders.update');
    Route::post('clinical-procedure-orders/{id}/sign', [ClinicalProcedureOrderController::class, 'sign'])
        ->middleware('can:clinical-procedure.order')
        ->name('clinical-procedure-orders.sign');
    Route::delete('clinical-procedure-orders/{id}/draft', [ClinicalProcedureOrderController::class, 'discardDraft'])
        ->middleware('can:clinical-procedure.order')
        ->name('clinical-procedure-orders.discard-draft');
    Route::patch('clinical-procedure-orders/{id}/status', [ClinicalProcedureOrderController::class, 'updateStatus'])
        ->middleware('can:clinical-procedure.perform')
        ->name('clinical-procedure-orders.update-status');
    Route::post('clinical-procedure-orders/{id}/lifecycle', [ClinicalProcedureOrderController::class, 'applyLifecycleAction'])
        ->middleware('can:clinical-procedure.order')
        ->name('clinical-procedure-orders.lifecycle');
    Route::get('clinical-procedure-orders/{id}/audit-logs/export', [ClinicalProcedureOrderController::class, 'exportAuditLogsCsv'])
        ->middleware('can:clinical-procedure.orders.view-audit-logs')
        ->name('clinical-procedure-orders.audit-logs.export');
    Route::get('clinical-procedure-orders/{id}/audit-logs', [ClinicalProcedureOrderController::class, 'auditLogs'])
        ->middleware('can:clinical-procedure.orders.view-audit-logs')
        ->name('clinical-procedure-orders.audit-logs');

    Route::get('emergency-triage-cases', [EmergencyTriageCaseController::class, 'index'])
        ->middleware('can:emergency.triage.read')
        ->name('emergency-triage-cases.index');
    Route::get('emergency-triage-cases/status-counts', [EmergencyTriageCaseController::class, 'statusCounts'])
        ->middleware('can:emergency.triage.read')
        ->name('emergency-triage-cases.status-counts');
    Route::post('emergency-triage-cases', [EmergencyTriageCaseController::class, 'store'])
        ->middleware('can:emergency.triage.create')
        ->name('emergency-triage-cases.store');
    Route::get('emergency-triage-cases/{id}', [EmergencyTriageCaseController::class, 'show'])
        ->middleware('can:emergency.triage.read')
        ->name('emergency-triage-cases.show');
    Route::patch('emergency-triage-cases/{id}', [EmergencyTriageCaseController::class, 'update'])
        ->middleware('can:emergency.triage.update')
        ->name('emergency-triage-cases.update');
    Route::patch('emergency-triage-cases/{id}/status', [EmergencyTriageCaseController::class, 'updateStatus'])
        ->middleware('can:emergency.triage.update-status')
        ->name('emergency-triage-cases.update-status');
    Route::get('emergency-triage-cases/{id}/transfers', [EmergencyTriageCaseController::class, 'transfers'])
        ->middleware('can:emergency.triage.read')
        ->name('emergency-triage-cases.transfers.index');
    Route::get('emergency-triage-cases/{id}/transfer-status-counts', [EmergencyTriageCaseController::class, 'transferStatusCounts'])
        ->middleware('can:emergency.triage.read')
        ->name('emergency-triage-cases.transfer-status-counts');
    Route::post('emergency-triage-cases/{id}/transfers', [EmergencyTriageCaseController::class, 'storeTransfer'])
        ->middleware('can:emergency.triage.manage-transfers')
        ->name('emergency-triage-cases.transfers.store');
    Route::patch('emergency-triage-cases/{id}/transfers/{transferId}', [EmergencyTriageCaseController::class, 'updateTransfer'])
        ->middleware('can:emergency.triage.manage-transfers')
        ->name('emergency-triage-cases.transfers.update');
    Route::patch('emergency-triage-cases/{id}/transfers/{transferId}/status', [EmergencyTriageCaseController::class, 'updateTransferStatus'])
        ->middleware('can:emergency.triage.manage-transfers')
        ->name('emergency-triage-cases.transfers.update-status');
    Route::get('emergency-triage-cases/{id}/transfers/{transferId}/audit-logs/export', [EmergencyTriageCaseController::class, 'exportTransferAuditLogsCsv'])
        ->middleware('can:emergency.triage.view-transfer-audit-logs')
        ->name('emergency-triage-cases.transfers.audit-logs.export');
    Route::get('emergency-triage-cases/{id}/transfers/{transferId}/audit-logs', [EmergencyTriageCaseController::class, 'transferAuditLogs'])
        ->middleware('can:emergency.triage.view-transfer-audit-logs')
        ->name('emergency-triage-cases.transfers.audit-logs');
    Route::get('emergency-triage-cases/{id}/audit-logs/export', [EmergencyTriageCaseController::class, 'exportAuditLogsCsv'])
        ->middleware('can:emergency.triage.view-audit-logs')
        ->name('emergency-triage-cases.audit-logs.export');
    Route::get('emergency-triage-cases/{id}/audit-logs', [EmergencyTriageCaseController::class, 'auditLogs'])
        ->middleware('can:emergency.triage.view-audit-logs')
        ->name('emergency-triage-cases.audit-logs');

    Route::get('service-requests', [ServiceRequestController::class, 'index'])
        ->middleware('can:service.requests.read')
        ->name('service-requests.index');
    Route::get('service-requests/status-counts', [ServiceRequestController::class, 'statusCounts'])
        ->middleware('can:service.requests.read')
        ->name('service-requests.status-counts');
    Route::get('service-requests/export/csv', [ServiceRequestController::class, 'exportCsv'])
        ->middleware('can:service.requests.export')
        ->name('service-requests.export.csv');
    Route::get('service-requests/department-options', [ServiceRequestController::class, 'departmentOptions'])
        ->middleware('can:service.requests.read')
        ->name('service-requests.department-options');
    Route::post('service-requests', [ServiceRequestController::class, 'store'])
        ->middleware('can:service.requests.create')
        ->name('service-requests.store');
    Route::post('service-requests/{id}/items', [ServiceRequestController::class, 'storeItems'])
        ->middleware('can:service.requests.create')
        ->name('service-requests.items.store');
    Route::get('service-requests/{id}/audit-events', [ServiceRequestController::class, 'auditEvents'])
        ->middleware('can:service.requests.audit-logs.read')
        ->name('service-requests.audit-events.index');
    Route::get('service-requests/{id}', [ServiceRequestController::class, 'show'])
        ->middleware('can:service.requests.read')
        ->name('service-requests.show');
    Route::patch('service-requests/{id}/status', [ServiceRequestController::class, 'updateStatus'])
        ->middleware('can:service.requests.update-status')
        ->name('service-requests.update-status');

    Route::get('nurse-queue', [\App\Modules\ServiceRequest\Presentation\Http\Controllers\NurseQueueController::class, 'index'])
        ->middleware('can:service.requests.read')
        ->name('nurse-queue.index');
    Route::post('nurse-queue/{encounterId}/assess', [\App\Modules\ServiceRequest\Presentation\Http\Controllers\NurseQueueController::class, 'assess'])
        ->middleware('can:service.requests.create')
        ->name('nurse-queue.assess');

    Route::get('claims-insurance', [ClaimsInsuranceCaseController::class, 'index'])
        ->middleware('can:claims.insurance.read')
        ->name('claims-insurance.index');
    Route::get('claims-insurance/status-counts', [ClaimsInsuranceCaseController::class, 'statusCounts'])
        ->middleware('can:claims.insurance.read')
        ->name('claims-insurance.status-counts');
    Route::post('claims-insurance', [ClaimsInsuranceCaseController::class, 'store'])
        ->middleware('can:claims.insurance.create')
        ->name('claims-insurance.store');
    Route::get('claims-insurance/{id}', [ClaimsInsuranceCaseController::class, 'show'])
        ->middleware('can:claims.insurance.read')
        ->name('claims-insurance.show');
    Route::patch('claims-insurance/{id}', [ClaimsInsuranceCaseController::class, 'update'])
        ->middleware('can:claims.insurance.update')
        ->name('claims-insurance.update');
    Route::patch('claims-insurance/{id}/status', [ClaimsInsuranceCaseController::class, 'updateStatus'])
        ->middleware('can:claims.insurance.update-status')
        ->name('claims-insurance.update-status');
    Route::patch('claims-insurance/{id}/reconciliation', [ClaimsInsuranceCaseController::class, 'reconcile'])
        ->middleware('can:claims.insurance.update-status')
        ->name('claims-insurance.reconciliation');
    Route::patch('claims-insurance/{id}/reconciliation-follow-up', [ClaimsInsuranceCaseController::class, 'updateReconciliationFollowUp'])
        ->middleware('can:claims.insurance.update-status')
        ->name('claims-insurance.reconciliation-follow-up');
    Route::get('claims-insurance/{id}/audit-logs/export', [ClaimsInsuranceCaseController::class, 'exportAuditLogsCsv'])
        ->middleware('can:claims.insurance.view-audit-logs')
        ->name('claims-insurance.audit-logs.export');
    Route::get('claims-insurance/{id}/audit-logs', [ClaimsInsuranceCaseController::class, 'auditLogs'])
        ->middleware('can:claims.insurance.view-audit-logs')
        ->name('claims-insurance.audit-logs');

    Route::get('inventory-procurement/items', [InventoryProcurementController::class, 'items'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.items.index');
    Route::post('inventory-procurement/items', [InventoryProcurementController::class, 'storeItem'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.items.store');
    Route::get('inventory-procurement/items/import-template', [InventoryProcurementController::class, 'downloadInventoryItemsImportTemplate'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.items.import-template');
    Route::post('inventory-procurement/items/import', [InventoryProcurementController::class, 'importItems'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.items.import');
    Route::post('inventory-procurement/items/bulk-sync-from-catalog', [InventoryProcurementController::class, 'bulkSyncFromCatalog'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.items.bulk-sync-from-catalog');
    Route::get('inventory-procurement/items/{id}', [InventoryProcurementController::class, 'showItem'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.items.show');
    Route::patch('inventory-procurement/items/{id}', [InventoryProcurementController::class, 'updateItem'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.items.update');
    Route::patch('inventory-procurement/items/{id}/status', [InventoryProcurementController::class, 'updateItemStatus'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.items.update-status');
    Route::get('inventory-procurement/items/{id}/audit-logs/export', [InventoryProcurementController::class, 'exportItemAuditLogsCsv'])
        ->middleware('can:inventory.procurement.view-audit-logs')
        ->name('inventory-procurement.items.audit-logs.export');
    Route::get('inventory-procurement/items/{id}/audit-logs', [InventoryProcurementController::class, 'itemAuditLogs'])
        ->middleware('can:inventory.procurement.view-audit-logs')
        ->name('inventory-procurement.items.audit-logs');
    Route::get('inventory-procurement/items/{itemId}/units', [InventoryItemUnitController::class, 'index'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.items.units.index');
    Route::post('inventory-procurement/items/{itemId}/units', [InventoryItemUnitController::class, 'store'])
        ->middleware('can:inventory.procurement.manage-item-units')
        ->name('inventory-procurement.items.units.store');
    Route::patch('inventory-procurement/items/{itemId}/units/{unitId}', [InventoryItemUnitController::class, 'update'])
        ->middleware('can:inventory.procurement.manage-item-units')
        ->name('inventory-procurement.items.units.update');
    Route::delete('inventory-procurement/items/{itemId}/units/{unitId}', [InventoryItemUnitController::class, 'destroy'])
        ->middleware('can:inventory.procurement.manage-item-units')
        ->name('inventory-procurement.items.units.destroy');
    Route::get('inventory-procurement/items/{itemId}/unit-prices', [InventoryItemUnitController::class, 'prices'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.items.unit-prices.index');
    Route::post('inventory-procurement/items/{itemId}/unit-prices', [InventoryItemUnitController::class, 'storePrice'])
        ->middleware('can:inventory.procurement.manage-unit-prices')
        ->name('inventory-procurement.items.unit-prices.store');
    Route::delete('inventory-procurement/items/{itemId}/unit-prices/{priceId}', [InventoryItemUnitController::class, 'destroyPrice'])
        ->middleware('can:inventory.procurement.manage-unit-prices')
        ->name('inventory-procurement.items.unit-prices.destroy');
    Route::get('inventory-procurement/stock-alert-counts', [InventoryProcurementController::class, 'stockAlertCounts'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.stock-alert-counts');
    Route::get('inventory-procurement/stock-movements', [InventoryProcurementController::class, 'stockMovements'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.stock-movements.index');
    Route::get('inventory-procurement/stock-movements/summary', [InventoryProcurementController::class, 'stockMovementSummary'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.stock-movements.summary');
    Route::get('inventory-procurement/stock-movements/export', [InventoryProcurementController::class, 'exportStockMovementsCsv'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.stock-movements.export');
    Route::get('inventory-procurement/stock-movements/reasons', [\App\Modules\InventoryProcurement\Presentation\Http\Controllers\InventoryStockMovementReasonController::class, 'index'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.stock-movements.reasons');
    Route::get('inventory-procurement/stock-movements/{id}', [InventoryProcurementController::class, 'showStockMovement'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.stock-movements.show');
    Route::post('inventory-procurement/stock-movements', [InventoryProcurementController::class, 'storeStockMovement'])
        ->middleware('can:inventory.procurement.create-movement')
        ->name('inventory-procurement.stock-movements.store');
    Route::post('inventory-procurement/stock-movements/reconcile', [InventoryProcurementController::class, 'reconcileStock'])
        ->middleware('can:inventory.procurement.reconcile-stock')
        ->name('inventory-procurement.stock-movements.reconcile');
    Route::get('inventory-procurement/reports/opening-stock', [InventoryProcurementController::class, 'openingStockReport'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.reports.opening-stock');
    Route::post('inventory-procurement/stock-movements/{id}/correct', [InventoryProcurementController::class, 'correctStockMovement'])
        ->middleware('can:inventory.procurement.correct-movement')
        ->name('inventory-procurement.stock-movements.correct');
    Route::get('inventory-procurement/procurement-requests', [InventoryProcurementController::class, 'procurementRequests'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.procurement-requests.index');
    Route::get('inventory-procurement/procurement-requests/active', [InventoryProcurementController::class, 'activeProcurementRequests'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.procurement-requests.active');
    Route::get('inventory-procurement/procurement-requests/export', [InventoryProcurementController::class, 'exportProcurementRequestsCsv'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.procurement-requests.export');
    Route::post('inventory-procurement/procurement-requests', [InventoryProcurementController::class, 'storeProcurementRequest'])
        ->middleware('can:inventory.procurement.create-request')
        ->name('inventory-procurement.procurement-requests.store');
    Route::patch('inventory-procurement/procurement-requests/{id}/status', [InventoryProcurementController::class, 'updateProcurementRequestStatus'])
        ->middleware('can:inventory.procurement.update-request-status')
        ->name('inventory-procurement.procurement-requests.update-status');
    Route::post('inventory-procurement/procurement-requests/{id}/place-order', [InventoryProcurementController::class, 'placeProcurementOrder'])
        ->middleware('can:inventory.procurement.update-request-status')
        ->name('inventory-procurement.procurement-requests.place-order');
    Route::post('inventory-procurement/procurement-requests/{id}/receive', [InventoryProcurementController::class, 'receiveProcurementRequest'])
        ->middleware(['can:inventory.procurement.update-request-status', 'can:inventory.procurement.create-movement'])
        ->name('inventory-procurement.procurement-requests.receive');
    Route::get('inventory-procurement/procurement-requests/{id}/audit-logs/export', [InventoryProcurementController::class, 'exportProcurementRequestAuditLogsCsv'])
        ->middleware('can:inventory.procurement.view-audit-logs')
        ->name('inventory-procurement.procurement-requests.audit-logs.export');
    Route::get('inventory-procurement/procurement-requests/{id}/audit-logs', [InventoryProcurementController::class, 'procurementRequestAuditLogs'])
        ->middleware('can:inventory.procurement.view-audit-logs')
        ->name('inventory-procurement.procurement-requests.audit-logs');
    Route::get('inventory-procurement/suppliers', [InventorySupplierController::class, 'index'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.suppliers.index');
    Route::get('inventory-procurement/suppliers/status-counts', [InventorySupplierController::class, 'statusCounts'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.suppliers.status-counts');
    Route::post('inventory-procurement/suppliers', [InventorySupplierController::class, 'store'])
        ->middleware('can:inventory.procurement.manage-suppliers')
        ->name('inventory-procurement.suppliers.store');
    Route::get('inventory-procurement/suppliers/{id}', [InventorySupplierController::class, 'show'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.suppliers.show');
    Route::patch('inventory-procurement/suppliers/{id}', [InventorySupplierController::class, 'update'])
        ->middleware('can:inventory.procurement.manage-suppliers')
        ->name('inventory-procurement.suppliers.update');
    Route::patch('inventory-procurement/suppliers/{id}/status', [InventorySupplierController::class, 'updateStatus'])
        ->middleware('can:inventory.procurement.manage-suppliers')
        ->name('inventory-procurement.suppliers.update-status');
    Route::get('inventory-procurement/suppliers/{id}/audit-logs/export', [InventorySupplierController::class, 'exportAuditLogsCsv'])
        ->middleware('can:inventory.procurement.view-audit-logs')
        ->name('inventory-procurement.suppliers.audit-logs.export');
    Route::get('inventory-procurement/suppliers/{id}/audit-logs', [InventorySupplierController::class, 'auditLogs'])
        ->middleware('can:inventory.procurement.view-audit-logs')
        ->name('inventory-procurement.suppliers.audit-logs');
    Route::get('inventory-procurement/warehouses', [InventoryWarehouseController::class, 'index'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.warehouses.index');
    Route::get('inventory-procurement/warehouses/status-counts', [InventoryWarehouseController::class, 'statusCounts'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.warehouses.status-counts');
    Route::post('inventory-procurement/warehouses', [InventoryWarehouseController::class, 'store'])
        ->middleware('can:inventory.procurement.manage-warehouses')
        ->name('inventory-procurement.warehouses.store');
    Route::get('inventory-procurement/warehouses/{id}', [InventoryWarehouseController::class, 'show'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.warehouses.show');
    Route::patch('inventory-procurement/warehouses/{id}', [InventoryWarehouseController::class, 'update'])
        ->middleware('can:inventory.procurement.manage-warehouses')
        ->name('inventory-procurement.warehouses.update');
    Route::patch('inventory-procurement/warehouses/{id}/status', [InventoryWarehouseController::class, 'updateStatus'])
        ->middleware('can:inventory.procurement.manage-warehouses')
        ->name('inventory-procurement.warehouses.update-status');
    Route::get('inventory-procurement/warehouses/{id}/audit-logs/export', [InventoryWarehouseController::class, 'exportAuditLogsCsv'])
        ->middleware('can:inventory.procurement.view-audit-logs')
        ->name('inventory-procurement.warehouses.audit-logs.export');
    Route::get('inventory-procurement/warehouses/{id}/audit-logs', [InventoryWarehouseController::class, 'auditLogs'])
        ->middleware('can:inventory.procurement.view-audit-logs')
        ->name('inventory-procurement.warehouses.audit-logs');

    // ─── Batches ──────────────────────────────────────────────
    Route::get('inventory-procurement/batches', [InventoryExtendedController::class, 'batches'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.batches.index');
    Route::post('inventory-procurement/batches', [InventoryExtendedController::class, 'storeBatch'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.batches.store');

    // ─── Department Requisitions ──────────────────────────────
    Route::get('inventory-procurement/department-requisitions', [InventoryExtendedController::class, 'departmentRequisitions'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.department-requisitions.index');
    Route::get('inventory-procurement/department-requisitions/context', [InventoryExtendedController::class, 'departmentRequisitionContext'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.department-requisitions.context');
    Route::get('inventory-procurement/department-requisitions/{id}', [InventoryExtendedController::class, 'departmentRequisition'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.department-requisitions.show');
    Route::get('inventory-procurement/shortage-queue', [InventoryExtendedController::class, 'shortageQueue'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.shortage-queue');
    Route::get('inventory-procurement/department-stock', [InventoryExtendedController::class, 'departmentStock'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.department-stock.index');
    Route::get('inventory-procurement/department-stock-balances', [InventoryExtendedController::class, 'departmentStockBalances'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.department-stock-balances.index');
    Route::get('inventory-procurement/department-stock-balances/{departmentId}/movements', [InventoryExtendedController::class, 'departmentStockMovements'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.department-stock-balances.movements');
    Route::post('inventory-procurement/department-stock-balances/{departmentId}/return', [InventoryExtendedController::class, 'departmentStockReturn'])
        ->middleware('can:inventory.procurement.create-request')
        ->name('inventory-procurement.department-stock-balances.return');
    Route::post('inventory-procurement/department-stock-balances/{departmentId}/wastage', [InventoryExtendedController::class, 'departmentStockWastage'])
        ->middleware('can:inventory.procurement.create-request')
        ->name('inventory-procurement.department-stock-balances.wastage');
    Route::post('inventory-procurement/department-requisitions', [InventoryExtendedController::class, 'storeDepartmentRequisition'])
        ->middleware('can:inventory.procurement.create-request')
        ->name('inventory-procurement.department-requisitions.store');
    Route::patch('inventory-procurement/department-requisitions/{id}/status', [InventoryExtendedController::class, 'updateDepartmentRequisitionStatus'])
        ->middleware('can:inventory.procurement.update-request-status')
        ->name('inventory-procurement.department-requisitions.update-status');

    // ─── Reference Data ───────────────────────────────────────
    Route::get('inventory-procurement/reference-data', [InventoryExtendedController::class, 'referenceData'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.reference-data');

    // ─── Category / Subcategory Admin (Inventory_MasterData_Alignment_Plan.md Phase 5) ───
    Route::get('inventory-procurement/categories', [InventoryCategoryAdminController::class, 'index'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.categories.index');
    Route::patch('inventory-procurement/categories/{id}', [InventoryCategoryAdminController::class, 'update'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.categories.update');
    Route::post('inventory-procurement/categories/{categoryId}/subcategories', [InventoryCategoryAdminController::class, 'storeSubcategory'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.categories.subcategories.store');
    Route::patch('inventory-procurement/categories/{categoryId}/subcategories/{id}', [InventoryCategoryAdminController::class, 'updateSubcategory'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.categories.subcategories.update');

    // ─── Clinical Catalog Search ────────────────────────────────
    Route::get('inventory-procurement/clinical-catalog/search', [InventoryExtendedController::class, 'clinicalCatalogSearch'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.clinical-catalog.search');

    // ─── Bulk Sync from Clinical Catalog ─────────────────────
    Route::post('inventory-procurement/items/bulk-create-from-catalog', [InventoryExtendedController::class, 'bulkCreateFromCatalog'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.items.bulk-create-from-catalog');

    // ─── Supplier Lead Times ──────────────────────────────────
    Route::get('inventory-procurement/supplier-lead-times', [InventoryExtendedController::class, 'supplierLeadTimes'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.supplier-lead-times.index');
    Route::post('inventory-procurement/supplier-lead-times', [InventoryExtendedController::class, 'storeSupplierLeadTime'])
        ->middleware('can:inventory.procurement.manage-suppliers')
        ->name('inventory-procurement.supplier-lead-times.store');
    Route::patch('inventory-procurement/supplier-lead-times/{id}/delivery', [InventoryExtendedController::class, 'recordSupplierDelivery'])
        ->middleware('can:inventory.procurement.manage-suppliers')
        ->name('inventory-procurement.supplier-lead-times.record-delivery');
    Route::get('inventory-procurement/suppliers/{supplierId}/performance', [InventoryExtendedController::class, 'supplierPerformance'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.suppliers.performance');

    // ─── Warehouse Transfers ──────────────────────────────────
    Route::get('inventory-procurement/warehouse-transfers', [InventoryExtendedController::class, 'warehouseTransfers'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.warehouse-transfers.index');
    Route::post('inventory-procurement/warehouse-transfers', [InventoryExtendedController::class, 'storeWarehouseTransfer'])
        ->middleware('can:inventory.procurement.manage-warehouses')
        ->name('inventory-procurement.warehouse-transfers.store');
    Route::get('inventory-procurement/warehouse-transfers/{id}', [InventoryExtendedController::class, 'showWarehouseTransfer'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.warehouse-transfers.show');
    Route::patch('inventory-procurement/warehouse-transfers/{id}/status', [InventoryExtendedController::class, 'updateWarehouseTransferStatus'])
        ->middleware('can:inventory.procurement.manage-warehouses')
        ->name('inventory-procurement.warehouse-transfers.update-status');
    Route::patch('inventory-procurement/warehouse-transfers/{id}/receipt-variance-review', [InventoryExtendedController::class, 'updateWarehouseTransferVarianceReview'])
        ->middleware('can:inventory.procurement.manage-warehouses')
        ->name('inventory-procurement.warehouse-transfers.update-receipt-variance-review');

    // ─── Dispensing Claim Links ───────────────────────────────
    Route::get('inventory-procurement/dispensing-claim-links', [InventoryExtendedController::class, 'dispensingClaimLinks'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.dispensing-claim-links.index');
    Route::post('inventory-procurement/dispensing-claim-links', [InventoryExtendedController::class, 'storeDispensingClaimLink'])
        ->middleware('can:inventory.procurement.create-movement')
        ->name('inventory-procurement.dispensing-claim-links.store');
    Route::patch('inventory-procurement/dispensing-claim-links/{id}/status', [InventoryExtendedController::class, 'updateDispensingClaimStatus'])
        ->middleware('can:inventory.procurement.create-movement')
        ->name('inventory-procurement.dispensing-claim-links.update-status');

    // ─── MSD E-Ordering ──────────────────────────────────────
    Route::get('inventory-procurement/msd-orders', [InventoryExtendedController::class, 'msdOrders'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.msd-orders.index');
    Route::post('inventory-procurement/msd-orders', [InventoryExtendedController::class, 'storeMsdOrder'])
        ->middleware('can:inventory.procurement.create-request')
        ->name('inventory-procurement.msd-orders.store');
    Route::patch('inventory-procurement/msd-orders/{id}/sync-status', [InventoryExtendedController::class, 'syncMsdOrderStatus'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.msd-orders.sync-status');
    Route::get('inventory-procurement/msd-health-check', [InventoryExtendedController::class, 'msdHealthCheck'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.msd-health-check');

    // ─── Barcode Lookup ──────────────────────────────────────
    Route::get('inventory-procurement/barcode-lookup', [InventoryExtendedController::class, 'lookupByBarcode'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.barcode-lookup');

    // ─── Department Item Catalog ───────────────────────────
    Route::get('inventory-procurement/department-item-catalog/{departmentId}', [InventoryExtendedController::class, 'getDepartmentItemCatalog'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.department-item-catalog.show');
    Route::put('inventory-procurement/department-item-catalog/{departmentId}', [InventoryExtendedController::class, 'updateDepartmentItemCatalog'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.department-item-catalog.update');
    Route::post('inventory-procurement/department-item-catalog/{departmentId}', [InventoryExtendedController::class, 'syncDepartmentItemCatalog'])
        ->middleware('can:inventory.procurement.manage-items')
        ->name('inventory-procurement.department-item-catalog.sync');

    // ─── Department Default Warehouses ────────────────────
    Route::get('inventory-procurement/department-default-warehouses', [InventoryExtendedController::class, 'departmentDefaultWarehouses'])
        ->middleware('can:inventory.procurement.manage-warehouses')
        ->name('inventory-procurement.department-default-warehouses.index');
    Route::patch('inventory-procurement/department-default-warehouses/{departmentId}', [InventoryExtendedController::class, 'updateDepartmentDefaultWarehouse'])
        ->middleware('can:inventory.procurement.manage-warehouses')
        ->name('inventory-procurement.department-default-warehouses.update');
    // ─── Inventory Analytics ─────────────────────────────────
    Route::get('inventory-procurement/analytics/consumption-trends', [InventoryAnalyticsController::class, 'consumptionTrends'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.analytics.consumption-trends');
    Route::get('inventory-procurement/analytics/abc-ven-matrix', [InventoryAnalyticsController::class, 'abcVenMatrix'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.analytics.abc-ven-matrix');
    Route::get('inventory-procurement/analytics/expiry-wastage', [InventoryAnalyticsController::class, 'expiryWastage'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.analytics.expiry-wastage');
     Route::get('inventory-procurement/analytics/stock-turnover', [InventoryAnalyticsController::class, 'stockTurnover'])
        ->middleware('can:inventory.procurement.read')
        ->name('inventory-procurement.analytics.stock-turnover');

    // Phase 1: Department-Level RBAC Implementation
    // These endpoints enforce department-scoped inventory access with temporal control
    Route::get('inventory-department/items', [InventoryDepartmentAccessController::class, 'listItems'])
        ->middleware('can:inventory.view-own-items')
        ->name('inventory-department.items.index');
    Route::get('inventory-department/items/{itemId}', [InventoryDepartmentAccessController::class, 'getItem'])
        ->middleware('can:inventory.view-own-items')
        ->name('inventory-department.items.show');
    Route::post('inventory-department/requisitions', [InventoryDepartmentAccessController::class, 'createRequisition'])
        ->middleware('can:inventory.create-requisition-own-department')
        ->name('inventory-department.requisitions.store');
    Route::get('inventory-department/requisitions', [InventoryDepartmentAccessController::class, 'listRequisitions'])
        ->middleware('can:inventory.view-requisition-own')
        ->name('inventory-department.requisitions.index');
    Route::get('inventory-department/requisitions/{requisitionId}', [InventoryDepartmentAccessController::class, 'getRequisition'])
        ->middleware('can:inventory.view-requisition-own')
        ->name('inventory-department.requisitions.show');

    // Phase 2: Multi-Role Approval Workflows
    // These endpoints implement multi-step approval workflows with segregation of duties
    Route::get('inventory-department/approvals/pending', [InventoryDepartmentAccessController::class, 'listPendingApprovals'])
        ->middleware('can:inventory.approve-requisition-own-department')
        ->name('inventory-department.approvals.pending');
    Route::post('inventory-department/approvals/{workflowInstanceId}/approve', [InventoryDepartmentAccessController::class, 'approveRequisition'])
        ->middleware('can:inventory.approve-requisition-own-department')
        ->name('inventory-department.approvals.approve');
    Route::post('inventory-department/approvals/{workflowInstanceId}/reject', [InventoryDepartmentAccessController::class, 'rejectRequisition'])
        ->middleware('can:inventory.approve-requisition-own-department')
        ->name('inventory-department.approvals.reject');
    Route::post('inventory-department/approvals/{workflowInstanceId}/recall', [InventoryDepartmentAccessController::class, 'recallRequisition'])
        ->middleware('can:inventory.create-requisition-own-department')
        ->name('inventory-department.approvals.recall');

    Route::get('theatre-procedures', [TheatreProcedureController::class, 'index'])
        ->middleware('can:theatre.procedures.read')
        ->name('theatre-procedures.index');
    Route::get('theatre-procedures/status-counts', [TheatreProcedureController::class, 'statusCounts'])
        ->middleware('can:theatre.procedures.read')
        ->name('theatre-procedures.status-counts');
    Route::get('theatre-procedures/duplicate-check', [TheatreProcedureController::class, 'duplicateCheck'])
        ->middleware('can:theatre.procedures.create')
        ->name('theatre-procedures.duplicate-check');
    Route::get('theatre-procedures/clinician-directory', [TheatreProcedureController::class, 'clinicianDirectory'])
        ->middleware('can:theatre.procedures.read')
        ->name('theatre-procedures.clinician-directory');
    Route::get('theatre-procedures/room-registry', [TheatreProcedureController::class, 'roomRegistry'])
        ->middleware('can:theatre.procedures.read')
        ->name('theatre-procedures.room-registry');
    Route::post('theatre-procedures', [TheatreProcedureController::class, 'store'])
        ->middleware('can:theatre.procedures.create')
        ->name('theatre-procedures.store');
    Route::get('theatre-procedures/{id}', [TheatreProcedureController::class, 'show'])
        ->middleware('can:theatre.procedures.read')
        ->name('theatre-procedures.show');
    Route::patch('theatre-procedures/{id}', [TheatreProcedureController::class, 'update'])
        ->middleware('can:theatre.procedures.create')
        ->name('theatre-procedures.update');
    Route::post('theatre-procedures/{id}/sign', [TheatreProcedureController::class, 'sign'])
        ->middleware('can:theatre.procedures.create')
        ->name('theatre-procedures.sign');
    Route::delete('theatre-procedures/{id}/draft', [TheatreProcedureController::class, 'discardDraft'])
        ->middleware('can:theatre.procedures.create')
        ->name('theatre-procedures.discard-draft');
    Route::patch('theatre-procedures/{id}/status', [TheatreProcedureController::class, 'updateStatus'])
        ->middleware('can:theatre.procedures.update-status')
        ->name('theatre-procedures.update-status');
    Route::post('theatre-procedures/{id}/lifecycle', [TheatreProcedureController::class, 'applyLifecycleAction'])
        ->middleware('can:theatre.procedures.create')
        ->name('theatre-procedures.lifecycle');
    Route::get('theatre-procedures/{id}/resource-allocations', [TheatreProcedureController::class, 'resourceAllocations'])
        ->middleware('can:theatre.procedures.read')
        ->name('theatre-procedures.resource-allocations.index');
    Route::get('theatre-procedures/{id}/resource-allocation-status-counts', [TheatreProcedureController::class, 'resourceAllocationStatusCounts'])
        ->middleware('can:theatre.procedures.read')
        ->name('theatre-procedures.resource-allocation-status-counts');
    Route::post('theatre-procedures/{id}/resource-allocations', [TheatreProcedureController::class, 'storeResourceAllocation'])
        ->middleware('can:theatre.procedures.manage-resources')
        ->name('theatre-procedures.resource-allocations.store');
    Route::patch('theatre-procedures/{id}/resource-allocations/{allocationId}', [TheatreProcedureController::class, 'updateResourceAllocation'])
        ->middleware('can:theatre.procedures.manage-resources')
        ->name('theatre-procedures.resource-allocations.update');
    Route::patch('theatre-procedures/{id}/resource-allocations/{allocationId}/status', [TheatreProcedureController::class, 'updateResourceAllocationStatus'])
        ->middleware('can:theatre.procedures.manage-resources')
        ->name('theatre-procedures.resource-allocations.update-status');
    Route::get('theatre-procedures/{id}/resource-allocations/{allocationId}/audit-logs/export', [TheatreProcedureController::class, 'exportResourceAllocationAuditLogsCsv'])
        ->middleware('can:theatre.procedures.view-resource-audit-logs')
        ->name('theatre-procedures.resource-allocations.audit-logs.export');
    Route::get('theatre-procedures/{id}/resource-allocations/{allocationId}/audit-logs', [TheatreProcedureController::class, 'resourceAllocationAuditLogs'])
        ->middleware('can:theatre.procedures.view-resource-audit-logs')
        ->name('theatre-procedures.resource-allocations.audit-logs');
    Route::get('theatre-procedures/{id}/audit-logs/export', [TheatreProcedureController::class, 'exportAuditLogsCsv'])
        ->middleware('can:theatre.procedures.view-audit-logs')
        ->name('theatre-procedures.audit-logs.export');
    Route::get('theatre-procedures/{id}/audit-logs', [TheatreProcedureController::class, 'auditLogs'])
        ->middleware('can:theatre.procedures.view-audit-logs')
        ->name('theatre-procedures.audit-logs');

    Route::get('patient-vitals/overdue-summary', [PatientVitalSetController::class, 'overdueSummary'])
        ->middleware('can:inpatient.ward.read')
        ->name('patient-vitals.overdue-summary');
    Route::post('patient-vitals', [PatientVitalSetController::class, 'store'])
        ->middleware('can:patient.vitals.record')
        ->name('patient-vitals.store');
    Route::get('patient-vitals/patient/{patientId}', [PatientVitalSetController::class, 'latestForPatient'])
        ->middleware('can:patients.read')
        ->name('patient-vitals.latest-for-patient');
    Route::post('patient-vitals/chart', [PatientVitalSetController::class, 'storeForChart'])
        ->middleware('can:patient.vitals.record')
        ->name('patient-vitals.store-for-chart');
    Route::patch('patient-vitals/{id}', [PatientVitalSetController::class, 'update'])
        ->middleware('can:patient.vitals.record')
        ->name('patient-vitals.update');

    Route::get('inpatient-ward/census', [InpatientWardController::class, 'census'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.census.index');
    Route::get('inpatient-ward/ward-beds', [FacilityResourceRegistryController::class, 'wardBeds'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.ward-beds.index');
    Route::get('inpatient-ward/tasks', [InpatientWardController::class, 'tasks'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.tasks.index');
    Route::get('inpatient-ward/task-status-counts', [InpatientWardController::class, 'taskStatusCounts'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.task-status-counts');
    Route::get('inpatient-ward/follow-up-rail', [InpatientWardController::class, 'followUpRail'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.follow-up-rail');
    Route::post('inpatient-ward/tasks', [InpatientWardController::class, 'storeTask'])
        ->middleware('can:inpatient.ward.create-task')
        ->name('inpatient-ward.tasks.store');
    Route::patch('inpatient-ward/tasks/{id}', [InpatientWardController::class, 'updateTask'])
        ->middleware('can:inpatient.ward.update-task-status')
        ->name('inpatient-ward.tasks.update');
    Route::patch('inpatient-ward/tasks/{id}/status', [InpatientWardController::class, 'updateTaskStatus'])
        ->middleware('can:inpatient.ward.update-task-status')
        ->name('inpatient-ward.tasks.update-status');
    Route::get('inpatient-ward/round-notes', [InpatientWardController::class, 'roundNotes'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.round-notes.index');
    Route::post('inpatient-ward/round-notes', [InpatientWardController::class, 'storeRoundNote'])
        ->middleware('can:inpatient.ward.create-round-note')
        ->name('inpatient-ward.round-notes.store');
    Route::patch('inpatient-ward/round-notes/{id}/acknowledge', [InpatientWardController::class, 'acknowledgeRoundNote'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.round-notes.acknowledge');
    Route::get('inpatient-ward/care-plans', [InpatientWardController::class, 'carePlans'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.care-plans.index');
    Route::get('inpatient-ward/care-plan-status-counts', [InpatientWardController::class, 'carePlanStatusCounts'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.care-plan-status-counts');
    Route::post('inpatient-ward/care-plans', [InpatientWardController::class, 'storeCarePlan'])
        ->middleware('can:inpatient.ward.create-care-plan')
        ->name('inpatient-ward.care-plans.store');
    Route::patch('inpatient-ward/care-plans/{id}', [InpatientWardController::class, 'updateCarePlan'])
        ->middleware('can:inpatient.ward.update-care-plan')
        ->name('inpatient-ward.care-plans.update');
    Route::patch('inpatient-ward/care-plans/{id}/status', [InpatientWardController::class, 'updateCarePlanStatus'])
        ->middleware('can:inpatient.ward.update-care-plan-status')
        ->name('inpatient-ward.care-plans.update-status');
    Route::get('inpatient-ward/care-plans/{id}/audit-logs/export', [InpatientWardController::class, 'exportCarePlanAuditLogsCsv'])
        ->middleware('can:inpatient.ward.view-audit-logs')
        ->name('inpatient-ward.care-plans.audit-logs.export');
    Route::get('inpatient-ward/care-plans/{id}/audit-logs', [InpatientWardController::class, 'carePlanAuditLogs'])
        ->middleware('can:inpatient.ward.view-audit-logs')
        ->name('inpatient-ward.care-plans.audit-logs');
    Route::get('inpatient-ward/discharge-checklists', [InpatientWardController::class, 'dischargeChecklists'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.discharge-checklists.index');
    Route::get('inpatient-ward/discharge-checklist-status-counts', [InpatientWardController::class, 'dischargeChecklistStatusCounts'])
        ->middleware('can:inpatient.ward.read')
        ->name('inpatient-ward.discharge-checklist-status-counts');
    Route::post('inpatient-ward/discharge-checklists', [InpatientWardController::class, 'storeDischargeChecklist'])
        ->middleware('can:inpatient.ward.manage-discharge-checklist')
        ->name('inpatient-ward.discharge-checklists.store');
    Route::patch('inpatient-ward/discharge-checklists/{id}', [InpatientWardController::class, 'updateDischargeChecklist'])
        ->middleware('can:inpatient.ward.manage-discharge-checklist')
        ->name('inpatient-ward.discharge-checklists.update');
    Route::patch('inpatient-ward/discharge-checklists/{id}/status', [InpatientWardController::class, 'updateDischargeChecklistStatus'])
        ->middleware('can:inpatient.ward.manage-discharge-checklist')
        ->name('inpatient-ward.discharge-checklists.update-status');
    Route::get('inpatient-ward/discharge-checklists/{id}/audit-logs/export', [InpatientWardController::class, 'exportDischargeChecklistAuditLogsCsv'])
        ->middleware('can:inpatient.ward.view-audit-logs')
        ->name('inpatient-ward.discharge-checklists.audit-logs.export');
    Route::get('inpatient-ward/discharge-checklists/{id}/audit-logs', [InpatientWardController::class, 'dischargeChecklistAuditLogs'])
        ->middleware('can:inpatient.ward.view-audit-logs')
        ->name('inpatient-ward.discharge-checklists.audit-logs');
    Route::get('inpatient-ward/tasks/{id}/audit-logs/export', [InpatientWardController::class, 'exportTaskAuditLogsCsv'])
        ->middleware('can:inpatient.ward.view-audit-logs')
        ->name('inpatient-ward.tasks.audit-logs.export');
    Route::get('inpatient-ward/tasks/{id}/audit-logs', [InpatientWardController::class, 'taskAuditLogs'])
        ->middleware('can:inpatient.ward.view-audit-logs')
        ->name('inpatient-ward.tasks.audit-logs');

    Route::get('staff', [StaffProfileController::class, 'index'])
        ->middleware('can:staff.read')
        ->name('staff.index');
    Route::get('staff/clinical-directory', [StaffProfileController::class, 'clinicalDirectory'])
        ->middleware('can:staff.clinical-directory.read')
        ->name('staff.clinical-directory.index');
    Route::get('staff/status-counts', [StaffProfileController::class, 'statusCounts'])
        ->middleware('can:staff.read')
        ->name('staff.status-counts');
    Route::get('staff/linkable-users', [StaffProfileController::class, 'eligibleUsers'])
        ->middleware('can:staff.create')
        ->name('staff.linkable-users.index');
    Route::get('staff/linkable-users/{userId}', [StaffProfileController::class, 'showEligibleUser'])
        ->middleware('can:staff.create')
        ->name('staff.linkable-users.show');
    Route::get('staff/credentialing-alerts', [StaffCredentialingController::class, 'alerts'])
        ->middleware('can:staff.credentialing.read')
        ->name('staff.credentialing-alerts.index');
    Route::get('staff/credentialing/summaries', [StaffCredentialingController::class, 'summaries'])
        ->middleware('can:staff.credentialing.read')
        ->name('staff.credentialing.summaries');
    Route::get('staff/department-options', [StaffProfileController::class, 'departmentOptions'])
        ->middleware('can:staff.read')
        ->name('staff.department-options');
    Route::get('staff/attendance/logs', [AttendanceController::class, 'logs'])
        ->middleware('can:staff.attendance.read')
        ->name('staff.attendance.logs');
    Route::get('staff/attendance/logs/export', [AttendanceController::class, 'exportCsv'])
        ->middleware('can:staff.attendance.read')
        ->name('staff.attendance.logs.export');
    Route::get('staff/attendance/devices', [AttendanceController::class, 'devices'])
        ->middleware('can:staff.attendance.read')
        ->name('staff.attendance.devices');
    Route::post('staff/attendance/devices', [AttendanceController::class, 'store'])
        ->middleware('can:staff.attendance.update')
        ->name('staff.attendance.devices.store');
    Route::put('staff/attendance/devices/{id}', [AttendanceController::class, 'update'])
        ->middleware('can:staff.attendance.update')
        ->name('staff.attendance.devices.update');
    Route::delete('staff/attendance/devices/{id}', [AttendanceController::class, 'destroy'])
        ->middleware('can:staff.attendance.update')
        ->name('staff.attendance.devices.destroy');
    Route::post('staff/attendance/devices/{id}/test-connection', [AttendanceController::class, 'testConnection'])
        ->middleware('can:staff.attendance.update')
        ->name('staff.attendance.devices.test-connection');
    Route::post('attendance/pull', [AttendanceController::class, 'pull'])
        ->middleware('can:staff.attendance.update')
        ->name('attendance.pull');
    Route::post('attendance/clear', [AttendanceController::class, 'clear'])
        ->middleware('can:staff.attendance.update')
        ->name('attendance.clear');
    Route::get('attendance/agent/status', [AttendanceController::class, 'agentStatus'])
        ->middleware('can:staff.attendance.read')
        ->name('attendance.agent.status');
    Route::delete('staff/attendance/logs', [AttendanceController::class, 'bulkDestroy'])
        ->middleware('can:staff.attendance.update')
        ->name('staff.attendance.logs.bulk-destroy');

    Route::get('staff/privileges/coverage-board', [StaffPrivilegeGrantController::class, 'coverageBoard'])
        ->middleware('can:staff.read')
        ->middleware('can:staff.privileges.read')
        ->name('staff.privileges.coverage-board');
    Route::post('staff', [StaffProfileController::class, 'store'])
        ->middleware('can:staff.create')
        ->name('staff.store');
    Route::get('staff/{id}/queue-position', [StaffProfileController::class, 'queuePosition'])
        ->middleware('can:staff.read')
        ->name('staff.queue-position');
    Route::get('staff/{id}', [StaffProfileController::class, 'show'])
        ->middleware('can:staff.read')
        ->name('staff.show');
    Route::patch('staff/{id}', [StaffProfileController::class, 'update'])
        ->middleware('can:staff.employment.update')
        ->name('staff.update');
    Route::patch('staff/{id}/status', [StaffProfileController::class, 'updateStatus'])
        ->middleware('can:staff.update-status')
        ->name('staff.update-status');
    Route::get('staff/{id}/audit-logs/export', [StaffProfileController::class, 'exportAuditLogsCsv'])
        ->middleware('can:staff.view-audit-logs')
        ->name('staff.audit-logs.export');
    Route::get('staff/{id}/audit-logs', [StaffProfileController::class, 'auditLogs'])
        ->middleware('can:staff.view-audit-logs')
        ->name('staff.audit-logs');
    Route::get('staff/{id}/documents', [StaffDocumentController::class, 'index'])
        ->middleware('can:staff.documents.read')
        ->name('staff.documents.index');
    Route::post('staff/{id}/documents', [StaffDocumentController::class, 'store'])
        ->middleware('can:staff.documents.create')
        ->name('staff.documents.store');
    Route::get('staff/{id}/documents/{documentId}', [StaffDocumentController::class, 'show'])
        ->middleware('can:staff.documents.read')
        ->name('staff.documents.show');
    Route::patch('staff/{id}/documents/{documentId}', [StaffDocumentController::class, 'update'])
        ->middleware('can:staff.documents.update')
        ->name('staff.documents.update');
    Route::patch('staff/{id}/documents/{documentId}/verification', [StaffDocumentController::class, 'updateVerification'])
        ->middleware('can:staff.documents.verify')
        ->name('staff.documents.update-verification');
    Route::patch('staff/{id}/documents/{documentId}/status', [StaffDocumentController::class, 'updateStatus'])
        ->middleware('can:staff.documents.update-status')
        ->name('staff.documents.update-status');
    Route::get('staff/{id}/documents/{documentId}/download', [StaffDocumentController::class, 'download'])
        ->middleware('can:staff.documents.read')
        ->name('staff.documents.download');
    Route::get('staff/{id}/documents/{documentId}/audit-logs', [StaffDocumentController::class, 'auditLogs'])
        ->middleware('can:staff.documents.view-audit-logs')
        ->name('staff.documents.audit-logs');
    Route::get('staff/{id}/credentialing/summary', [StaffCredentialingController::class, 'summary'])
        ->middleware('can:staff.credentialing.read')
        ->name('staff.credentialing.summary');
    Route::get('staff/{id}/credentialing/regulatory-profile', [StaffCredentialingController::class, 'showRegulatoryProfile'])
        ->middleware('can:staff.credentialing.read')
        ->name('staff.credentialing.regulatory-profile.show');
    Route::post('staff/{id}/credentialing/regulatory-profile', [StaffCredentialingController::class, 'storeRegulatoryProfile'])
        ->middleware('can:staff.credentialing.manage-profile')
        ->name('staff.credentialing.regulatory-profile.store');
    Route::patch('staff/{id}/credentialing/regulatory-profile', [StaffCredentialingController::class, 'updateRegulatoryProfile'])
        ->middleware('can:staff.credentialing.manage-profile')
        ->name('staff.credentialing.regulatory-profile.update');
    Route::get('staff/{id}/credentialing/registrations', [StaffCredentialingController::class, 'registrations'])
        ->middleware('can:staff.credentialing.read')
        ->name('staff.credentialing.registrations.index');
    Route::post('staff/{id}/credentialing/registrations', [StaffCredentialingController::class, 'storeRegistration'])
        ->middleware('can:staff.credentialing.manage-registrations')
        ->name('staff.credentialing.registrations.store');
    Route::get('staff/{id}/credentialing/registrations/{registrationId}', [StaffCredentialingController::class, 'showRegistration'])
        ->middleware('can:staff.credentialing.read')
        ->name('staff.credentialing.registrations.show');
    Route::patch('staff/{id}/credentialing/registrations/{registrationId}', [StaffCredentialingController::class, 'updateRegistration'])
        ->middleware('can:staff.credentialing.manage-registrations')
        ->name('staff.credentialing.registrations.update');
    Route::patch('staff/{id}/credentialing/registrations/{registrationId}/verification', [StaffCredentialingController::class, 'updateRegistrationVerification'])
        ->middleware('can:staff.credentialing.verify')
        ->name('staff.credentialing.registrations.update-verification');
    Route::get('staff/{id}/credentialing/audit-logs', [StaffCredentialingController::class, 'auditLogs'])
        ->middleware('can:staff.credentialing.view-audit-logs')
        ->name('staff.credentialing.audit-logs');
    Route::get('staff/{id}/specialties', [StaffProfileSpecialtyController::class, 'index'])
        ->middleware('can:staff.specialties.read')
        ->name('staff.specialties.index');
    Route::patch('staff/{id}/specialties', [StaffProfileSpecialtyController::class, 'sync'])
        ->middleware('can:staff.specialties.manage')
        ->name('staff.specialties.sync');
    Route::get('staff/{id}/privileges', [StaffPrivilegeGrantController::class, 'index'])
        ->middleware('can:staff.privileges.read')
        ->name('staff.privileges.index');
    Route::post('staff/{id}/privileges', [StaffPrivilegeGrantController::class, 'store'])
        ->middleware('can:staff.privileges.create')
        ->name('staff.privileges.store');
    Route::get('staff/{id}/privileges/{privilegeId}', [StaffPrivilegeGrantController::class, 'show'])
        ->middleware('can:staff.privileges.read')
        ->name('staff.privileges.show');
    Route::patch('staff/{id}/privileges/{privilegeId}', [StaffPrivilegeGrantController::class, 'update'])
        ->middleware('can:staff.privileges.update')
        ->name('staff.privileges.update');
    Route::patch('staff/{id}/privileges/{privilegeId}/status', [StaffPrivilegeGrantController::class, 'updateStatus'])
        ->middleware('can:staff.privileges.update-status')
        ->name('staff.privileges.update-status');
    Route::get('staff/{id}/privileges/{privilegeId}/audit-logs/export', [StaffPrivilegeGrantController::class, 'exportAuditLogsCsv'])
        ->middleware('can:staff.privileges.view-audit-logs')
        ->name('staff.privileges.audit-logs.export');
    Route::get('staff/{id}/privileges/{privilegeId}/audit-logs', [StaffPrivilegeGrantController::class, 'auditLogs'])
        ->middleware('can:staff.privileges.view-audit-logs')
        ->name('staff.privileges.audit-logs');

    Route::get('privilege-catalogs', [ClinicalPrivilegeCatalogController::class, 'index'])
        ->middleware('can:staff.privileges.read')
        ->name('privilege-catalogs.index');
    Route::post('privilege-catalogs', [ClinicalPrivilegeCatalogController::class, 'store'])
        ->middleware('can:staff.privileges.create')
        ->name('privilege-catalogs.store');
    Route::patch('privilege-catalogs/{id}', [ClinicalPrivilegeCatalogController::class, 'update'])
        ->middleware('can:staff.privileges.update')
        ->name('privilege-catalogs.update');
    Route::patch('privilege-catalogs/{id}/status', [ClinicalPrivilegeCatalogController::class, 'updateStatus'])
        ->middleware('can:staff.privileges.update-status')
        ->name('privilege-catalogs.update-status');
    Route::get('privilege-catalogs/{id}/audit-logs', [ClinicalPrivilegeCatalogController::class, 'auditLogs'])
        ->middleware('can:staff.privileges.view-audit-logs')
        ->name('privilege-catalogs.audit-logs');

    Route::get('specialties', [ClinicalSpecialtyController::class, 'index'])
        ->middleware('can:specialties.read')
        ->name('specialties.index');
    Route::post('specialties', [ClinicalSpecialtyController::class, 'store'])
        ->middleware('can:specialties.create')
        ->name('specialties.store');
    Route::get('specialties/{id}', [ClinicalSpecialtyController::class, 'show'])
        ->middleware('can:specialties.read')
        ->name('specialties.show');
    Route::patch('specialties/{id}', [ClinicalSpecialtyController::class, 'update'])
        ->middleware('can:specialties.update')
        ->name('specialties.update');
    Route::patch('specialties/{id}/status', [ClinicalSpecialtyController::class, 'updateStatus'])
        ->middleware('can:specialties.update-status')
        ->name('specialties.update-status');
    Route::get('specialties/{id}/audit-logs', [ClinicalSpecialtyController::class, 'auditLogs'])
        ->middleware('can:specialties.view-audit-logs')
        ->name('specialties.audit-logs');
    Route::get('specialties/{id}/assigned-staff', [ClinicalSpecialtyController::class, 'assignedStaff'])
        ->middleware('can:staff.specialties.read')
        ->name('specialties.assigned-staff');

    Route::get('departments', [DepartmentController::class, 'index'])
        ->middleware('can:departments.read')
        ->name('departments.index');
    Route::get('departments/status-counts', [DepartmentController::class, 'statusCounts'])
        ->middleware('can:departments.read')
        ->name('departments.status-counts');
    Route::post('departments', [DepartmentController::class, 'store'])
        ->middleware('can:departments.create')
        ->name('departments.store');
    Route::get('departments/{id}', [DepartmentController::class, 'show'])
        ->middleware('can:departments.read')
        ->name('departments.show');
    Route::patch('departments/{id}', [DepartmentController::class, 'update'])
        ->middleware('can:departments.update')
        ->name('departments.update');
    Route::patch('departments/{id}/status', [DepartmentController::class, 'updateStatus'])
        ->middleware('can:departments.update-status')
        ->name('departments.update-status');
    Route::get('departments/{id}/audit-logs/export', [DepartmentController::class, 'exportAuditLogsCsv'])
        ->middleware('can:departments.view-audit-logs')
        ->name('departments.audit-logs.export');
    Route::get('departments/{id}/audit-logs', [DepartmentController::class, 'auditLogs'])
        ->middleware('can:departments.view-audit-logs')
        ->name('departments.audit-logs');

    // Notification Center
    Route::get('notifications', [\App\Modules\Notifications\Presentation\Http\Controllers\NotificationController::class, 'index'])
        ->middleware('auth')
        ->name('notifications.index');
    Route::get('notifications/unread-count', [\App\Modules\Notifications\Presentation\Http\Controllers\NotificationController::class, 'unreadCount'])
        ->middleware('auth')
        ->name('notifications.unread-count');
    Route::patch('notifications/{id}/read', [\App\Modules\Notifications\Presentation\Http\Controllers\NotificationController::class, 'markAsRead'])
        ->middleware('auth')
        ->name('notifications.mark-as-read');
    Route::patch('notifications/read-all', [\App\Modules\Notifications\Presentation\Http\Controllers\NotificationController::class, 'markAllAsRead'])
        ->middleware('auth')
        ->name('notifications.mark-all-as-read');
    Route::delete('notifications/{id}', [\App\Modules\Notifications\Presentation\Http\Controllers\NotificationController::class, 'dismiss'])
        ->middleware('auth')
        ->name('notifications.dismiss');

    // ============================================================
    // Reception, Clinician, and Nursing workspace routes moved
    // (2026-08-10) to routes/api-workspaces.php — see that file's header
    // comment. Nothing was deleted; this is a pure reorganization: legacy/
    // generic routes stay in this file (routes/api.php), fresh
    // workspace-scoped routes live in routes/api-workspaces.php going
    // forward, loaded by App\Providers\WorkspaceRouteServiceProvider.
    // ============================================================
});


Route::middleware('agent.token')->prefix('v1')->group(function (): void {
    Route::post('attendance/agent/push-logs', [AttendanceController::class, 'pushLogs'])
        ->middleware('throttle:10,1')
        ->name('attendance.agent.push-logs');
    Route::post('attendance/agent/heartbeat', [AttendanceController::class, 'heartbeat'])
        ->middleware('throttle:10,1')
        ->name('attendance.agent.heartbeat');
});
