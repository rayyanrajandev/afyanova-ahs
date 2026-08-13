<?php

use App\Modules\Billing\Infrastructure\Models\BillingInvoiceLineItemSourceModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * billing-financial-state-remediation-plan.md, Phase 1: regression coverage for
 * replacing the JSON-scan + notes-regex "is this already invoiced" mechanism
 * (EloquentBillingInvoiceRepository::findByLineItemSource(),
 * ListBillingChargeCaptureCandidatesUseCase::invoicedSourceIndex()) with a real
 * indexed table (billing_invoice_line_item_sources).
 */
uses(RefreshDatabase::class);

function makeSourceIndexRadiologyOrder(string $patientId, ?string $appointmentId = null): RadiologyOrderModel
{
    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null, 'facility_id' => null,
        'catalog_type' => 'radiology_procedure', 'code' => 'RAD-SRC-001', 'name' => 'Chest X-Ray',
        'department_id' => null, 'category' => 'xray', 'unit' => 'study',
        'description' => 'Chest x-ray test fixture.', 'metadata' => ['billingServiceCode' => 'RAD-SRC-001'],
        'status' => 'active', 'status_reason' => null,
    ]);

    $chargeableItem = new ChargeableItemModel();
    $chargeableItem->id = $catalogItem->id;
    $chargeableItem->fill([
        'catalog_type' => 'radiology_procedure', 'charge_model' => 'flat',
        'code' => 'RAD-SRC-001', 'name' => 'Chest X-Ray', 'status' => 'active',
    ]);
    $chargeableItem->save();

    return RadiologyOrderModel::query()->create([
        'order_number' => 'RAD'.now()->format('Ymd').strtoupper(Str::random(6)),
        'tenant_id' => null, 'facility_id' => null,
        'patient_id' => $patientId, 'admission_id' => null, 'appointment_id' => $appointmentId,
        'ordered_by_user_id' => null, 'ordered_at' => now()->subHours(2)->toDateTimeString(),
        'radiology_procedure_catalog_item_id' => $catalogItem->id,
        'procedure_code' => 'RAD-SRC-001', 'modality' => 'xray', 'study_description' => 'Chest X-Ray',
        'clinical_indication' => 'Cough', 'scheduled_for' => now()->subHour()->toDateTimeString(),
        'report_summary' => 'Clear.', 'completed_at' => now()->subMinutes(20)->toDateTimeString(),
        'status' => 'completed', 'status_reason' => null,
    ]);
}

it('recognizes a manually-invoiced source referenced only by sourceWorkflowKind/Id, with no notes match', function (): void {
    $user = makeBillingUser();
    $patient = makeBillingPatient();
    $order = makeSourceIndexRadiologyOrder($patient->id);

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 25000, 'discountAmount' => 0, 'taxAmount' => 0, 'paidAmount' => 0,
            // Deliberately no "Source: [kind] ... (id: ...)" text in notes -- the
            // old implementation's regex fallback would never have caught this;
            // the new table doesn't need it to, since sourceWorkflowKind/Id alone
            // are enough.
            'notes' => 'Cashier walk-in charge, entered manually.',
            'lineItems' => [[
                'description' => 'Chest X-Ray', 'quantity' => 1, 'unitPrice' => 25000,
                'serviceCode' => 'RAD-SRC-001', 'unit' => 'study',
                'sourceWorkflowKind' => 'radiology_order', 'sourceWorkflowId' => $order->id,
            ]],
        ]))
        ->assertCreated();

    expect(BillingInvoiceLineItemSourceModel::query()
        ->where('source_workflow_kind', 'radiology_order')
        ->where('source_workflow_id', $order->id)
        ->exists())->toBeTrue();

    $candidate = $this->actingAs($user)
        ->getJson('/api/v1/billing/charge-capture-candidates?patientId='.$patient->id.'&currencyCode=TZS&includeInvoiced=true')
        ->assertOk()
        ->json('data.0');

    expect($candidate['sourceWorkflowId'])->toBe($order->id)
        ->and($candidate['alreadyInvoiced'])->toBeTrue();
});

it('still rejects a second invoice for a source already covered by an issued invoice', function (): void {
    $user = makeBillingUser();
    grantBillingInvoiceStatusRoutePermissions($user);
    $patient = makeBillingPatient();
    $order = makeSourceIndexRadiologyOrder($patient->id);

    $created = $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 25000, 'discountAmount' => 0, 'taxAmount' => 0, 'paidAmount' => 0,
            'lineItems' => [[
                'description' => 'Chest X-Ray', 'quantity' => 1, 'unitPrice' => 25000,
                'serviceCode' => 'RAD-SRC-001', 'unit' => 'study',
                'sourceWorkflowKind' => 'radiology_order', 'sourceWorkflowId' => $order->id,
            ]],
        ]))
        ->assertCreated()
        ->json('data');

    // Issue it first -- a still-draft invoice for the same patient/currency/null
    // context would just get merged into by findMatchingDraft(), which isn't what
    // this test is exercising (that's the "continues the active draft" behavior,
    // tested elsewhere). Issuing forces the second POST into a genuinely separate
    // invoice, which must hit assertNoExistingSourceCharges().
    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', ['status' => 'issued'])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/billing', billingInvoicePayload($patient->id, [
            'subtotalAmount' => 25000, 'discountAmount' => 0, 'taxAmount' => 0, 'paidAmount' => 0,
            'notes' => 'Attempted duplicate charge',
            'lineItems' => [[
                'description' => 'Chest X-Ray (duplicate attempt)', 'quantity' => 1, 'unitPrice' => 25000,
                'serviceCode' => 'RAD-SRC-001', 'unit' => 'study',
                'sourceWorkflowKind' => 'radiology_order', 'sourceWorkflowId' => $order->id,
            ]],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lineItems']);
});
