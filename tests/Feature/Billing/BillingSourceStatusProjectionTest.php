<?php

use App\Modules\Billing\Infrastructure\Models\BillingSourceStatusModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * billing-financial-state-remediation-plan.md, Phase 2: recording/reversing a
 * payment, or changing an invoice's status, must keep billing_source_status
 * (written by SyncBillingSourceStatusProjection, listening for
 * InvoicePaymentRecorded/InvoicePaymentReversed/InvoiceStatusChanged) in sync.
 */
uses(RefreshDatabase::class);

it('upserts billing_source_status when a payment is recorded', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
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

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', ['status' => 'issued'])
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 25000, 'payerType' => 'self_pay', 'paymentMethod' => 'cash',
        ])
        ->assertCreated();

    $row = BillingSourceStatusModel::query()
        ->where('source_workflow_kind', 'radiology_order')
        ->where('source_workflow_id', $order->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('paid')
        ->and($row->billing_invoice_id)->toBe($created['id']);
});

it('updates billing_source_status back down when a payment is reversed', function (): void {
    $user = makeBillingUser();
    grantBillingPaymentRoutePermissions($user);
    $user->givePermissionTo('billing.payments.reverse');
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

    $this->actingAs($user)
        ->patchJson('/api/v1/billing/'.$created['id'].'/status', ['status' => 'issued'])
        ->assertOk();

    $payment = $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments', [
            'amount' => 25000, 'payerType' => 'self_pay', 'paymentMethod' => 'cash',
        ])
        ->assertCreated()
        ->json('data.payment');

    $this->actingAs($user)
        ->postJson('/api/v1/billing/'.$created['id'].'/payments/'.$payment['id'].'/reversals', [
            'amount' => 25000,
            'reason' => 'Incorrect amount charged',
        ])
        ->assertCreated();

    $row = BillingSourceStatusModel::query()
        ->where('source_workflow_kind', 'radiology_order')
        ->where('source_workflow_id', $order->id)
        ->first();

    expect($row->status)->toBe('issued');
});
