<?php

use App\Models\User;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires authentication for platform cross tenant patient admin endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/patients?targetTenantCode=EAH&reason=Support%20investigation')
        ->assertUnauthorized();
});

it('requires authentication for platform cross tenant admin audit logs endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/cross-tenant-audit-logs')
        ->assertUnauthorized();
});

it('requires authentication for platform cross tenant admission admin endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/admissions?targetTenantCode=EAH&reason=Admission%20investigation')
        ->assertUnauthorized();
});

it('requires authentication for platform cross tenant appointment admin endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/appointments?targetTenantCode=EAH&reason=Appointment%20investigation')
        ->assertUnauthorized();
});

it('requires authentication for platform cross tenant billing invoice admin endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/billing-invoices?targetTenantCode=EAH&reason=Finance%20investigation')
        ->assertUnauthorized();
});

it('requires authentication for platform cross tenant laboratory order admin endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/laboratory-orders?targetTenantCode=EAH&reason=Lab%20investigation')
        ->assertUnauthorized();
});

it('requires authentication for platform cross tenant pharmacy order admin endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/pharmacy-orders?targetTenantCode=EAH&reason=Pharmacy%20investigation')
        ->assertUnauthorized();
});

it('requires authentication for platform cross tenant medical record admin endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/medical-records?targetTenantCode=EAH&reason=Clinical%20investigation')
        ->assertUnauthorized();
});

it('requires authentication for platform cross tenant staff admin endpoint', function (): void {
    $this->getJson('/api/v1/platform/admin/staff?targetTenantCode=EAH&reason=Staff%20investigation')
        ->assertUnauthorized();
});

it('forbids platform cross tenant patient admin endpoint without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/patients?targetTenantCode=EAH&reason=Support%20investigation')
        ->assertForbidden();
});

it('forbids platform cross tenant admission admin endpoint without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/admissions?targetTenantCode=EAH&reason=Admission%20investigation')
        ->assertForbidden();
});

it('forbids platform cross tenant appointment admin endpoint without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/appointments?targetTenantCode=EAH&reason=Appointment%20investigation')
        ->assertForbidden();
});

it('forbids platform cross tenant billing invoice admin endpoint without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/billing-invoices?targetTenantCode=EAH&reason=Finance%20investigation')
        ->assertForbidden();
});

it('forbids platform cross tenant laboratory order admin endpoint without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/laboratory-orders?targetTenantCode=EAH&reason=Lab%20investigation')
        ->assertForbidden();
});

it('forbids platform cross tenant pharmacy order admin endpoint without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/pharmacy-orders?targetTenantCode=EAH&reason=Pharmacy%20investigation')
        ->assertForbidden();
});

it('forbids platform cross tenant medical record admin endpoint without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/medical-records?targetTenantCode=EAH&reason=Clinical%20investigation')
        ->assertForbidden();
});

it('forbids platform cross tenant staff admin endpoint without permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/staff?targetTenantCode=EAH&reason=Staff%20investigation')
        ->assertForbidden();
});

it('forbids platform cross tenant admin audit logs endpoint without audit permission', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-logs')
        ->assertForbidden();
});

it('validates required parameters for platform cross tenant patient admin endpoint', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/patients')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targetTenantCode', 'reason']);
});

it('validates required parameters for platform cross tenant admission admin endpoint', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/admissions')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targetTenantCode', 'reason']);
});

it('validates required parameters for platform cross tenant appointment admin endpoint', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/appointments')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targetTenantCode', 'reason']);
});

it('validates required parameters for platform cross tenant billing invoice admin endpoint', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/billing-invoices')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targetTenantCode', 'reason']);
});

it('validates required parameters for platform cross tenant laboratory order admin endpoint', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/laboratory-orders')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targetTenantCode', 'reason']);
});

it('validates required parameters for platform cross tenant pharmacy order admin endpoint', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/pharmacy-orders')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targetTenantCode', 'reason']);
});

it('validates required parameters for platform cross tenant medical record admin endpoint', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/medical-records')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targetTenantCode', 'reason']);
});

it('validates required parameters for platform cross tenant staff admin endpoint', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/staff')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targetTenantCode', 'reason']);
});

it('returns 404 and writes audit log when target tenant is not found', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/patients?targetTenantCode=NOPE&reason=Support%20lookup')
        ->assertNotFound();

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->first();

    expect($log)->not->toBeNull();
    expect($log->action)->toBe('platform-admin.patients.search');
    expect($log->operation_type)->toBe('read');
    expect($log->outcome)->toBe('not_found');
    expect($log->target_tenant_code)->toBe('NOPE');
    expect($log->actor_id)->toBe($user->id);
    expect($log->reason)->toBe('Support lookup');
});

it('returns only patients from explicitly requested tenant and writes success audit log', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    [$tenantAId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-ADM',
        facilityName: 'Nairobi Admin Facility',
    );

    [$tenantBId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-ADM',
        facilityName: 'Dar Admin Facility',
    );

    $visible = seedTenantPatient($tenantAId, 'PT20260225XTA001', 'Cross', 'Tenant', 'Visible', '+255700400001');
    seedTenantPatient($tenantBId, 'PT20260225XTA002', 'Cross', 'Tenant', 'Hidden', '+255700400002');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/patients?targetTenantCode=EAH&reason=Support%20investigation&q=Cross')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.targetTenant.code', 'EAH')
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.lastName', 'Visible');

    expect($response->json('data'))->toHaveCount(1);

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($log)->not->toBeNull();
    expect($log->outcome)->toBe('success');
    expect($log->target_tenant_id)->toBe($tenantAId);
    expect($log->target_tenant_code)->toBe('EAH');
    expect($log->reason)->toBe('Support investigation');
});

it('allows platform admin patient endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-PLAT',
        facilityName: 'Dar Platform Admin Facility',
    );

    $patient = seedTenantPatient($tenantId, 'PT20260225XTA003', 'Exempt', 'Route', 'Patient', '+255700400003');

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/patients?targetTenantCode=TZH&reason=Platform%20audit%20review')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $patient->id);
});

it('returns only admissions from explicitly requested tenant and writes success audit log', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    [$tenantAId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-AADM',
        facilityName: 'Nairobi Admission Admin',
    );

    [$tenantBId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-AADM',
        facilityName: 'Dar Admission Admin',
    );

    $tenantAPatient = seedTenantPatient($tenantAId, 'PT20260225XTAA01', 'Adm', 'Alpha', 'Patient', '+255700900001');
    $tenantBPatient = seedTenantPatient($tenantBId, 'PT20260225XTAA02', 'Adm', 'Beta', 'Patient', '+255700900002');

    $visible = seedTenantAdmission(
        tenantId: $tenantAId,
        facilityId: null,
        patientId: $tenantAPatient->id,
        admissionNumber: 'ADM20260225XTAA01',
        ward: 'Ward-A',
        status: 'admitted',
    );
    seedTenantAdmission(
        tenantId: $tenantBId,
        facilityId: null,
        patientId: $tenantBPatient->id,
        admissionNumber: 'ADM20260225XTAA02',
        ward: 'Ward-B',
        status: 'discharged',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/admissions?targetTenantCode=EAH&reason=Admission%20investigation&q=Ward-A&ward=Ward-A&status=admitted')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.targetTenant.code', 'EAH')
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.ward', 'Ward-A')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.admissionNumber', 'ADM20260225XTAA01')
        ->assertJsonPath('data.0.ward', 'Ward-A');

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('platform-admin.admissions.search');
    expect($log->operation_type)->toBe('read');
    expect($log->outcome)->toBe('success');
    expect($log->target_tenant_id)->toBe($tenantAId);
    expect($log->target_resource_type)->toBe('admission');
    expect($log->reason)->toBe('Admission investigation');
});

it('returns only appointments from explicitly requested tenant and writes success audit log', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    [$tenantAId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-XAPT',
        facilityName: 'Nairobi Appointment Admin',
    );

    [$tenantBId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-XAPT',
        facilityName: 'Dar Appointment Admin',
    );

    $tenantAPatient = seedTenantPatient($tenantAId, 'PT20260225XTAP01', 'Appt', 'Alpha', 'Patient', '+255700910001');
    $tenantBPatient = seedTenantPatient($tenantBId, 'PT20260225XTAP02', 'Appt', 'Beta', 'Patient', '+255700910002');

    $visible = seedTenantAppointment(
        tenantId: $tenantAId,
        facilityId: null,
        patientId: $tenantAPatient->id,
        appointmentNumber: 'APT20260225XTAP01',
        reason: 'Follow-up visit',
        status: 'scheduled',
    );
    seedTenantAppointment(
        tenantId: $tenantBId,
        facilityId: null,
        patientId: $tenantBPatient->id,
        appointmentNumber: 'APT20260225XTAP02',
        reason: 'Hidden tenant appointment',
        status: 'completed',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/appointments?targetTenantCode=EAH&reason=Appointment%20investigation&q=Follow-up&status=scheduled')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.targetTenant.code', 'EAH')
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.status', 'scheduled')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.appointmentNumber', 'APT20260225XTAP01')
        ->assertJsonPath('data.0.reason', 'Follow-up visit');

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('platform-admin.appointments.search');
    expect($log->operation_type)->toBe('read');
    expect($log->outcome)->toBe('success');
    expect($log->target_tenant_id)->toBe($tenantAId);
    expect($log->target_resource_type)->toBe('appointment');
    expect($log->reason)->toBe('Appointment investigation');
});

it('allows platform admin appointment endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-AEX2',
        facilityName: 'Dar Appointment Exempt',
    );

    $patient = seedTenantPatient($tenantId, 'PT20260225XTAP03', 'Appt', 'Exempt', 'Patient', '+255700910003');
    $appointment = seedTenantAppointment(
        tenantId: $tenantId,
        facilityId: null,
        patientId: $patient->id,
        appointmentNumber: 'APT20260225XTAP03',
        reason: 'Exempt appointment review',
        status: 'scheduled',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/appointments?targetTenantCode=TZH&reason=Appointment%20audit%20review')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $appointment->id);
});

it('allows platform admin admission endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-AEX',
        facilityName: 'Dar Admission Exempt',
    );

    $patient = seedTenantPatient($tenantId, 'PT20260225XTAA03', 'Adm', 'Exempt', 'Patient', '+255700900003');
    $admission = seedTenantAdmission(
        tenantId: $tenantId,
        facilityId: null,
        patientId: $patient->id,
        admissionNumber: 'ADM20260225XTAA03',
        ward: 'Ward-C',
        status: 'admitted',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/admissions?targetTenantCode=TZH&reason=Admission%20audit%20review')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $admission->id);
});

it('returns only billing invoices from explicitly requested tenant and writes success audit log', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    [$tenantAId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-BADM',
        facilityName: 'Nairobi Billing Admin',
    );

    [$tenantBId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-BADM',
        facilityName: 'Dar Billing Admin',
    );

    $tenantAPatient = seedTenantPatient($tenantAId, 'PT20260225XTB001', 'Finance', 'Alpha', 'Patient', '+255700500001');
    $tenantBPatient = seedTenantPatient($tenantBId, 'PT20260225XTB002', 'Finance', 'Beta', 'Patient', '+255700500002');

    $visible = seedTenantBillingInvoice(
        tenantId: $tenantAId,
        facilityId: null,
        patientId: $tenantAPatient->id,
        invoiceNumber: 'INV20260225XTB001',
        notes: 'Finance visible invoice',
        currencyCode: 'KES',
        status: 'draft',
    );
    seedTenantBillingInvoice(
        tenantId: $tenantBId,
        facilityId: null,
        patientId: $tenantBPatient->id,
        invoiceNumber: 'INV20260225XTB002',
        notes: 'Finance hidden invoice',
        currencyCode: 'TZS',
        status: 'paid',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/billing-invoices?targetTenantCode=EAH&reason=Finance%20investigation&q=Finance&currencyCode=kes')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.targetTenant.code', 'EAH')
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.currencyCode', 'KES')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.invoiceNumber', 'INV20260225XTB001')
        ->assertJsonPath('data.0.notes', 'Finance visible invoice');

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('platform-admin.billing-invoices.search');
    expect($log->operation_type)->toBe('read');
    expect($log->outcome)->toBe('success');
    expect($log->target_tenant_id)->toBe($tenantAId);
    expect($log->target_resource_type)->toBe('billing_invoice');
    expect($log->reason)->toBe('Finance investigation');
});

it('allows platform admin billing invoice endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-BEX',
        facilityName: 'Dar Billing Exempt',
    );

    $patient = seedTenantPatient($tenantId, 'PT20260225XTB003', 'Billing', 'Exempt', 'Patient', '+255700500003');
    $invoice = seedTenantBillingInvoice(
        tenantId: $tenantId,
        facilityId: null,
        patientId: $patient->id,
        invoiceNumber: 'INV20260225XTB003',
        notes: 'Exempt billing invoice',
        currencyCode: 'TZS',
        status: 'issued',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/billing-invoices?targetTenantCode=TZH&reason=Finance%20audit%20review')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $invoice->id);
});

it('returns only laboratory orders from explicitly requested tenant and writes success audit log', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    [$tenantAId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-LADM',
        facilityName: 'Nairobi Lab Admin',
    );

    [$tenantBId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-LADM',
        facilityName: 'Dar Lab Admin',
    );

    $tenantAPatient = seedTenantPatient($tenantAId, 'PT20260225XTL001', 'Lab', 'Alpha', 'Patient', '+255700600001');
    $tenantBPatient = seedTenantPatient($tenantBId, 'PT20260225XTL002', 'Lab', 'Beta', 'Patient', '+255700600002');

    $visible = seedTenantLaboratoryOrder(
        tenantId: $tenantAId,
        facilityId: null,
        patientId: $tenantAPatient->id,
        orderNumber: 'LAB20260225XTL001',
        testCode: 'CBC',
        testName: 'Complete Blood Count',
        priority: 'urgent',
        status: 'ordered',
    );
    seedTenantLaboratoryOrder(
        tenantId: $tenantBId,
        facilityId: null,
        patientId: $tenantBPatient->id,
        orderNumber: 'LAB20260225XTL002',
        testCode: 'GLU',
        testName: 'Glucose',
        priority: 'routine',
        status: 'completed',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/laboratory-orders?targetTenantCode=EAH&reason=Lab%20investigation&q=CBC&priority=urgent')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.targetTenant.code', 'EAH')
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.priority', 'urgent')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.orderNumber', 'LAB20260225XTL001')
        ->assertJsonPath('data.0.testCode', 'CBC');

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('platform-admin.laboratory-orders.search');
    expect($log->operation_type)->toBe('read');
    expect($log->outcome)->toBe('success');
    expect($log->target_tenant_id)->toBe($tenantAId);
    expect($log->target_resource_type)->toBe('laboratory_order');
    expect($log->reason)->toBe('Lab investigation');
});

it('allows platform admin laboratory order endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-LEX',
        facilityName: 'Dar Lab Exempt',
    );

    $patient = seedTenantPatient($tenantId, 'PT20260225XTL003', 'Lab', 'Exempt', 'Patient', '+255700600003');
    $order = seedTenantLaboratoryOrder(
        tenantId: $tenantId,
        facilityId: null,
        patientId: $patient->id,
        orderNumber: 'LAB20260225XTL003',
        testCode: 'HB',
        testName: 'Hemoglobin',
        priority: 'routine',
        status: 'ordered',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/laboratory-orders?targetTenantCode=TZH&reason=Lab%20audit%20review')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $order->id);
});

it('returns only pharmacy orders from explicitly requested tenant and writes success audit log', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    [$tenantAId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-PADM',
        facilityName: 'Nairobi Pharmacy Admin',
    );

    [$tenantBId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-PADM',
        facilityName: 'Dar Pharmacy Admin',
    );

    $tenantAPatient = seedTenantPatient($tenantAId, 'PT20260225XTP001', 'Pharm', 'Alpha', 'Patient', '+255700700001');
    $tenantBPatient = seedTenantPatient($tenantBId, 'PT20260225XTP002', 'Pharm', 'Beta', 'Patient', '+255700700002');

    $visible = seedTenantPharmacyOrder(
        tenantId: $tenantAId,
        facilityId: null,
        patientId: $tenantAPatient->id,
        orderNumber: 'PHM20260225XTP001',
        medicationCode: 'AMOX',
        medicationName: 'Amoxicillin',
        status: 'pending',
    );
    seedTenantPharmacyOrder(
        tenantId: $tenantBId,
        facilityId: null,
        patientId: $tenantBPatient->id,
        orderNumber: 'PHM20260225XTP002',
        medicationCode: 'PARA',
        medicationName: 'Paracetamol',
        status: 'dispensed',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/pharmacy-orders?targetTenantCode=EAH&reason=Pharmacy%20investigation&q=AMOX&status=pending')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.targetTenant.code', 'EAH')
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.status', 'pending')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.orderNumber', 'PHM20260225XTP001')
        ->assertJsonPath('data.0.medicationCode', 'AMOX');

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('platform-admin.pharmacy-orders.search');
    expect($log->operation_type)->toBe('read');
    expect($log->outcome)->toBe('success');
    expect($log->target_tenant_id)->toBe($tenantAId);
    expect($log->target_resource_type)->toBe('pharmacy_order');
    expect($log->reason)->toBe('Pharmacy investigation');
});

it('allows platform admin pharmacy order endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-PEX',
        facilityName: 'Dar Pharmacy Exempt',
    );

    $patient = seedTenantPatient($tenantId, 'PT20260225XTP003', 'Pharm', 'Exempt', 'Patient', '+255700700003');
    $order = seedTenantPharmacyOrder(
        tenantId: $tenantId,
        facilityId: null,
        patientId: $patient->id,
        orderNumber: 'PHM20260225XTP003',
        medicationCode: 'ORS',
        medicationName: 'Oral Rehydration Salts',
        status: 'pending',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/pharmacy-orders?targetTenantCode=TZH&reason=Pharmacy%20audit%20review')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $order->id);
});

it('returns only medical records from explicitly requested tenant and writes success audit log', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    [$tenantAId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-MADM',
        facilityName: 'Nairobi Medical Admin',
    );

    [$tenantBId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MADM',
        facilityName: 'Dar Medical Admin',
    );

    $tenantAPatient = seedTenantPatient($tenantAId, 'PT20260225XTM001', 'Med', 'Alpha', 'Patient', '+255700800001');
    $tenantBPatient = seedTenantPatient($tenantBId, 'PT20260225XTM002', 'Med', 'Beta', 'Patient', '+255700800002');

    $visible = seedTenantMedicalRecord(
        tenantId: $tenantAId,
        facilityId: null,
        patientId: $tenantAPatient->id,
        recordNumber: 'MR20260225XTM001',
        recordType: 'progress_note',
        status: 'draft',
        diagnosisCode: 'A09',
    );
    seedTenantMedicalRecord(
        tenantId: $tenantBId,
        facilityId: null,
        patientId: $tenantBPatient->id,
        recordNumber: 'MR20260225XTM002',
        recordType: 'discharge_summary',
        status: 'finalized',
        diagnosisCode: 'J11',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/medical-records?targetTenantCode=EAH&reason=Clinical%20investigation&q=A09&recordType=progress_note&status=draft')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.targetTenant.code', 'EAH')
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.recordType', 'progress_note')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.recordNumber', 'MR20260225XTM001')
        ->assertJsonPath('data.0.recordType', 'progress_note');

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('platform-admin.medical-records.search');
    expect($log->operation_type)->toBe('read');
    expect($log->outcome)->toBe('success');
    expect($log->target_tenant_id)->toBe($tenantAId);
    expect($log->target_resource_type)->toBe('medical_record');
    expect($log->reason)->toBe('Clinical investigation');
});

it('allows platform admin medical record endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-MEX',
        facilityName: 'Dar Medical Exempt',
    );

    $patient = seedTenantPatient($tenantId, 'PT20260225XTM003', 'Med', 'Exempt', 'Patient', '+255700800003');
    $record = seedTenantMedicalRecord(
        tenantId: $tenantId,
        facilityId: null,
        patientId: $patient->id,
        recordNumber: 'MR20260225XTM003',
        recordType: 'consult_note',
        status: 'draft',
        diagnosisCode: 'R50',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/medical-records?targetTenantCode=TZH&reason=Clinical%20audit%20review')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $record->id);
});

it('returns only staff profiles from explicitly requested tenant and writes success audit log', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    [$tenantAId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-SADM',
        facilityName: 'Nairobi Staff Admin',
    );

    [$tenantBId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-SADM',
        facilityName: 'Dar Staff Admin',
    );

    $visible = seedTenantStaffProfile(
        tenantId: $tenantAId,
        employeeNumber: 'EMP20260225XTS001',
        department: 'Pharmacy',
        jobTitle: 'Pharmacist',
        employmentType: 'full_time',
        status: 'active',
    );
    seedTenantStaffProfile(
        tenantId: $tenantBId,
        employeeNumber: 'EMP20260225XTS002',
        department: 'Laboratory',
        jobTitle: 'Lab Technologist',
        employmentType: 'part_time',
        status: 'inactive',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/staff?targetTenantCode=EAH&reason=Staff%20investigation&q=Pharmacist&department=Pharmacy&status=active&employmentType=full_time')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.targetTenant.code', 'EAH')
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.department', 'Pharmacy')
        ->assertJsonPath('meta.filters.employmentType', 'full_time')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.employeeNumber', 'EMP20260225XTS001')
        ->assertJsonPath('data.0.jobTitle', 'Pharmacist');

    $log = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('platform-admin.staff.search');
    expect($log->operation_type)->toBe('read');
    expect($log->outcome)->toBe('success');
    expect($log->target_tenant_id)->toBe($tenantAId);
    expect($log->target_resource_type)->toBe('staff_profile');
    expect($log->reason)->toBe('Staff investigation');
});

it('allows platform admin staff endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-SEX',
        facilityName: 'Dar Staff Exempt',
    );

    $profile = seedTenantStaffProfile(
        tenantId: $tenantId,
        employeeNumber: 'EMP20260225XTS003',
        department: 'Outpatient',
        jobTitle: 'Clinical Officer',
        employmentType: 'full_time',
        status: 'active',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/staff?targetTenantCode=TZH&reason=Staff%20audit%20review')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $profile->id);
});

it('lists platform cross tenant admin audit logs when authorized and supports filters', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantReadPermission($user);
    grantPlatformCrossTenantAuditPermission($user);

    [$tenantId] = seedPlatformAdminTenantAndFacility(
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-AUD',
        facilityName: 'Nairobi Audit Facility',
    );

    seedTenantPatient($tenantId, 'PT20260225XTA010', 'Audit', 'Read', 'Visible', '+255700401010');

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/patients?targetTenantCode=EAH&reason=Audit%20trail%20seed')
        ->assertOk();

    DB::table('platform_cross_tenant_admin_audit_logs')->insert([
        'id' => (string) Str::uuid(),
        'action' => 'platform-admin.billing.search',
        'operation_type' => 'read',
        'actor_id' => $user->id,
        'target_tenant_id' => $tenantId,
        'target_tenant_code' => 'EAH',
        'target_resource_type' => 'billing_invoice',
        'target_resource_id' => null,
        'outcome' => 'success',
        'reason' => 'extra seeded log',
        'metadata' => json_encode(['seeded' => true], JSON_THROW_ON_ERROR),
        'created_at' => now()->subSecond(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-logs?targetTenantCode=eah&action=platform-admin.patients.search&outcome=success&perPage=5')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.action', 'platform-admin.patients.search')
        ->assertJsonPath('meta.filters.outcome', 'success')
        ->assertJsonPath('data.0.action', 'platform-admin.patients.search')
        ->assertJsonPath('data.0.operationType', 'read')
        ->assertJsonPath('data.0.targetTenantCode', 'EAH')
        ->assertJsonPath('data.0.reason', 'Audit trail seed');
});

it('allows platform cross tenant admin audit logs endpoint when tenant isolation is enabled and tenant scope is unresolved', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantAuditPermission($user);

    seedTenantIsolationCountryOverrideForPlatformAdmin('TZ');

    DB::table('platform_cross_tenant_admin_audit_logs')->insert([
        'id' => (string) Str::uuid(),
        'action' => 'platform-admin.patients.search',
        'operation_type' => 'read',
        'actor_id' => $user->id,
        'target_tenant_id' => null,
        'target_tenant_code' => 'TZH',
        'target_resource_type' => 'patient',
        'target_resource_id' => null,
        'outcome' => 'not_found',
        'reason' => 'tenant isolation exemption audit endpoint test',
        'metadata' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-logs?targetTenantCode=TZH')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.targetTenantCode', 'TZH');
});

function grantPlatformCrossTenantReadPermission(User $user): void
{
    $user->givePermissionTo('platform.cross-tenant.read');
}

function grantPlatformCrossTenantAuditPermission(User $user): void
{
    $user->givePermissionTo('platform.cross-tenant.view-audit-logs');
}

function seedTenantIsolationCountryOverrideForPlatformAdmin(string $countryCode): void
{
    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_tenant_isolation',
        'scope_type' => 'country',
        'scope_key' => strtoupper($countryCode),
        'enabled' => true,
        'reason' => 'platform admin route exemption test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array{0:string,1:string}
 */
function seedPlatformAdminTenantAndFacility(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => $tenantCode,
        'name' => $tenantName,
        'country_code' => $countryCode,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => $facilityCode,
        'name' => $facilityName,
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

function seedTenantPatient(
    string $tenantId,
    string $patientNumber,
    string $firstName,
    string $middleName,
    string $lastName,
    string $phone,
): PatientModel {
    return PatientModel::query()->create([
        'tenant_id' => $tenantId,
        'patient_number' => $patientNumber,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'gender' => 'female',
        'date_of_birth' => '1991-01-01',
        'phone' => $phone,
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
    ]);
}

function seedTenantBillingInvoice(
    string $tenantId,
    ?string $facilityId,
    string $patientId,
    string $invoiceNumber,
    string $notes,
    string $currencyCode,
    string $status,
): BillingInvoiceModel {
    return BillingInvoiceModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'invoice_number' => $invoiceNumber,
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'issued_by_user_id' => null,
        'invoice_date' => now()->toDateTimeString(),
        'currency_code' => strtoupper($currencyCode),
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 100,
        'paid_amount' => $status === 'paid' ? 100 : 0,
        'balance_amount' => $status === 'paid' ? 0 : 100,
        'payment_due_at' => now()->addDays(7)->toDateTimeString(),
        'notes' => $notes,
        'status' => $status,
        'status_reason' => null,
    ]);
}

function seedTenantAdmission(
    string $tenantId,
    ?string $facilityId,
    string $patientId,
    string $admissionNumber,
    string $ward,
    string $status,
): AdmissionModel {
    return AdmissionModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'admission_number' => $admissionNumber,
        'patient_id' => $patientId,
        'appointment_id' => null,
        'attending_clinician_user_id' => null,
        'ward' => $ward,
        'bed' => 'A-01',
        'admitted_at' => now()->toDateTimeString(),
        'discharged_at' => $status === 'discharged' ? now()->toDateTimeString() : null,
        'admission_reason' => 'Observation',
        'notes' => 'Cross-tenant admin admission seed',
        'status' => $status,
        'status_reason' => null,
    ]);
}

function seedTenantAppointment(
    string $tenantId,
    ?string $facilityId,
    string $patientId,
    string $appointmentNumber,
    string $reason,
    string $status,
): AppointmentModel {
    return AppointmentModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'appointment_number' => $appointmentNumber,
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => $reason,
        'notes' => null,
        'status' => $status,
        'status_reason' => null,
    ]);
}

function seedTenantLaboratoryOrder(
    string $tenantId,
    ?string $facilityId,
    string $patientId,
    string $orderNumber,
    string $testCode,
    string $testName,
    string $priority,
    string $status,
): LaboratoryOrderModel {
    return LaboratoryOrderModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'order_number' => $orderNumber,
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->toDateTimeString(),
        'test_code' => strtoupper($testCode),
        'test_name' => $testName,
        'priority' => strtolower($priority),
        'specimen_type' => 'blood',
        'clinical_notes' => null,
        'result_summary' => null,
        'resulted_at' => $status === 'completed' ? now()->toDateTimeString() : null,
        'status' => $status,
        'status_reason' => null,
    ]);
}

function seedTenantPharmacyOrder(
    string $tenantId,
    ?string $facilityId,
    string $patientId,
    string $orderNumber,
    string $medicationCode,
    string $medicationName,
    string $status,
): PharmacyOrderModel {
    return PharmacyOrderModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'order_number' => $orderNumber,
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'ordered_by_user_id' => null,
        'ordered_at' => now()->toDateTimeString(),
        'medication_code' => strtoupper($medicationCode),
        'medication_name' => $medicationName,
        'dosage_instruction' => '1 tablet twice daily',
        'quantity_prescribed' => 10,
        'quantity_dispensed' => $status === 'dispensed' ? 10 : 0,
        'dispensing_notes' => null,
        'dispensed_at' => $status === 'dispensed' ? now()->toDateTimeString() : null,
        'status' => $status,
        'status_reason' => null,
    ]);
}

function seedTenantMedicalRecord(
    string $tenantId,
    ?string $facilityId,
    string $patientId,
    string $recordNumber,
    string $recordType,
    string $status,
    string $diagnosisCode,
): MedicalRecordModel {
    return MedicalRecordModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'record_number' => $recordNumber,
        'patient_id' => $patientId,
        'admission_id' => null,
        'appointment_id' => null,
        'author_user_id' => null,
        'encounter_at' => now()->toDateTimeString(),
        'record_type' => $recordType,
        'subjective' => 'Patient reports symptoms.',
        'objective' => 'Vitals stable.',
        'assessment' => 'Clinical assessment text',
        'plan' => 'Treatment plan text',
        'diagnosis_code' => strtoupper($diagnosisCode),
        'status' => $status,
        'status_reason' => null,
    ]);
}

function seedTenantStaffProfile(
    string $tenantId,
    string $employeeNumber,
    string $department,
    string $jobTitle,
    string $employmentType,
    string $status,
): StaffProfileModel {
    $user = User::factory()->create();

    return StaffProfileModel::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $user->id,
        'employee_number' => $employeeNumber,
        'department' => $department,
        'job_title' => $jobTitle,
        'professional_license_number' => 'LIC-'.strtoupper(substr($employeeNumber, -6)),
        'license_type' => 'General',
        'phone_extension' => '204',
        'employment_type' => $employmentType,
        'status' => $status,
        'status_reason' => null,
    ]);
}
