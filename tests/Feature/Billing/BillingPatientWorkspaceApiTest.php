<?php

use App\Models\User;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceAuditLogModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoicePaymentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function makeWorkspaceUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{tenantId:string,facilityId:string,headers:array<string,string>}
 */
function seedWorkspaceScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-WKSP',
        'name' => 'Workspace Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-WKSP',
        'name' => 'Dar Workspace Centre',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'billing_officer',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'tenantId' => $tenantId,
        'facilityId' => $facilityId,
        'headers' => [
            'X-Tenant-Code' => 'TZ-WKSP',
            'X-Facility-Code' => 'DAR-WKSP',
        ],
    ];
}

function makeWorkspacePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-'.strtoupper(Str::random(8)),
        'first_name' => 'Amina',
        'last_name' => 'Workspace',
        'gender' => 'female',
        'date_of_birth' => '1988-06-01',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makeWorkspaceInvoice(string $patientId, array $overrides = []): BillingInvoiceModel
{
    return BillingInvoiceModel::query()->create(array_merge([
        'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
        'patient_id' => $patientId,
        'invoice_date' => now()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 100000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 100000,
        'paid_amount' => 0,
        'balance_amount' => 100000,
        'line_items' => [],
        'status' => 'draft',
    ], $overrides));
}

it('returns the patient workspace with invoices and summary totals', function (): void {
    $user = makeWorkspaceUser(['billing.invoices.read']);
    $scope = seedWorkspaceScope($user->id);
    $patient = makeWorkspacePatient();

    makeWorkspaceInvoice($patient->id, [
        'status' => 'issued',
        'total_amount' => 100000,
        'paid_amount' => 0,
        'balance_amount' => 100000,
    ]);
    makeWorkspaceInvoice($patient->id, [
        'status' => 'paid',
        'total_amount' => 50000,
        'paid_amount' => 50000,
        'balance_amount' => 0,
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson("/api/v1/billing/{$patient->id}/workspace")
        ->assertOk()
        ->assertJsonPath('data.patient.id', $patient->id)
        ->assertJsonPath('data.patient.patientNumber', $patient->patient_number)
        ->assertJsonCount(2, 'data.invoices')
        ->assertJsonPath('data.summary.totalBilled', 150000.0)
        ->assertJsonPath('data.summary.totalPaid', 50000.0)
        ->assertJsonPath('data.summary.totalUnpaid', 100000.0)
        ->assertJsonPath('data.summary.invoiceCount', 2)
        ->assertJsonPath('data.summary.unpaidInvoiceCount', 1);
});

it('returns 404 from the workspace endpoint for an unknown patient', function (): void {
    $user = makeWorkspaceUser(['billing.invoices.read']);
    $scope = seedWorkspaceScope($user->id);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/billing/'.((string) Str::uuid()).'/workspace')
        ->assertNotFound();
});

it('denies the workspace endpoint without permission', function (): void {
    $user = makeWorkspaceUser();
    $scope = seedWorkspaceScope($user->id);
    $patient = makeWorkspacePatient();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson("/api/v1/billing/{$patient->id}/workspace")
        ->assertForbidden();
});

it('lists patient payments across invoices with invoice context merged in', function (): void {
    $user = makeWorkspaceUser(['billing.invoices.read']);
    $scope = seedWorkspaceScope($user->id);
    $patient = makeWorkspacePatient();

    $invoice = makeWorkspaceInvoice($patient->id, [
        'status' => 'partially_paid',
        'paid_amount' => 20000,
        'balance_amount' => 80000,
    ]);

    $payment = BillingInvoicePaymentModel::query()->create([
        'billing_invoice_id' => $invoice->id,
        'payment_at' => now()->toDateTimeString(),
        'amount' => 20000,
        'cumulative_paid_amount' => 20000,
        'payer_type' => 'self_pay',
        'payment_method' => 'cash',
        'payment_reference' => 'CASH-WKSP-1',
        'recorded_by_user_id' => $user->id,
        'entry_type' => 'payment',
        'source_action' => 'test.seed',
        'note' => 'Workspace test payment',
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson("/api/v1/billing/{$patient->id}/payments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $payment->id)
        ->assertJsonPath('data.0.amount', '20000.00')
        ->assertJsonPath('data.0.invoiceNumber', $invoice->invoice_number)
        ->assertJsonPath('data.0.invoiceStatus', 'partially_paid')
        ->assertJsonPath('data.0.currencyCode', 'TZS');
});

it('returns 404 from the payments endpoint for an unknown patient', function (): void {
    $user = makeWorkspaceUser(['billing.invoices.read']);
    $scope = seedWorkspaceScope($user->id);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/billing/'.((string) Str::uuid()).'/payments')
        ->assertNotFound();
});

it('lists patient audit logs across invoices with invoice number merged in', function (): void {
    $user = makeWorkspaceUser(['billing.invoices.read']);
    $scope = seedWorkspaceScope($user->id);
    $patient = makeWorkspacePatient();

    $invoice = makeWorkspaceInvoice($patient->id, ['status' => 'issued']);

    $log = BillingInvoiceAuditLogModel::query()->create([
        'billing_invoice_id' => $invoice->id,
        'action' => 'invoice.issued',
        'actor_id' => $user->id,
        'changes' => ['status' => ['draft', 'issued']],
        'metadata' => [],
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson("/api/v1/billing/{$patient->id}/audit-logs")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $log->id)
        ->assertJsonPath('data.0.action', 'invoice.issued')
        ->assertJsonPath('data.0.invoiceNumber', $invoice->invoice_number);
});

it('returns 404 from the audit-logs endpoint for an unknown patient', function (): void {
    $user = makeWorkspaceUser(['billing.invoices.read']);
    $scope = seedWorkspaceScope($user->id);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/billing/'.((string) Str::uuid()).'/audit-logs')
        ->assertNotFound();
});
