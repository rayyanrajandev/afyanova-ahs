<?php

use App\Models\User;
use App\Modules\ClinicalProcedure\Application\Services\ClinicalProcedureQueueAnnouncer;
use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureQueueUpdated;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\Laboratory\Application\Services\LaboratoryQueueAnnouncer;
use App\Modules\Laboratory\Domain\Events\LaboratoryQueueUpdated;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Notifications\Domain\Events\NotificationDispatched;
use App\Modules\Notifications\Infrastructure\Models\NotificationModel;
use App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated;
use App\Modules\Pharmacy\Application\Services\PharmacyQueueAnnouncer;
use App\Modules\Pharmacy\Domain\Events\PharmacyQueueUpdated;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Application\Services\ClinicalWorkstationChannelAuthorizer;
use App\Modules\Radiology\Application\Services\RadiologyQueueAnnouncer;
use App\Modules\Radiology\Domain\Events\RadiologyQueueUpdated;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\Revenue\Domain\Events\ServiceChargeAuthorized;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

function clinicalLiveSyncFacility(): string
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'T-'.Str::upper(Str::random(6)),
        'name' => 'Live Sync Tenant',
        'country_code' => 'TZ',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'F-'.Str::upper(Str::random(6)),
        'name' => 'Live Sync Facility',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $facilityId;
}

function clinicalLiveSyncUser(array $permissions, ?string $facilityId = null): User
{
    $user = makeUserWithRole($permissions, 'CLINICAL.SYNC.'.Str::upper(Str::random(4)));

    if ($facilityId !== null) {
        DB::table('facility_user')->insert([
            'user_id' => $user->id,
            'facility_id' => $facilityId,
            'role' => 'CLINICAL.STAFF',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $user->refresh();
}

it('authorizes valid staff on departmental workstation channels and refuses unauthorized users', function (): void {
    $authorizer = app(ClinicalWorkstationChannelAuthorizer::class);
    $facilityId = clinicalLiveSyncFacility();
    $otherFacilityId = clinicalLiveSyncFacility();

    $labUser = clinicalLiveSyncUser(['laboratory.orders.read'], $facilityId);
    $radUser = clinicalLiveSyncUser(['radiology.orders.read'], $facilityId);
    $pharmUser = clinicalLiveSyncUser(['pharmacy.orders.read'], $facilityId);
    $procUser = clinicalLiveSyncUser(['clinical-procedure.orders.read'], $facilityId);

    // Permitted users in the same facility are authorized
    expect($authorizer->authorize($labUser, $facilityId, 'laboratory.orders.read'))->toBeTrue()
        ->and($authorizer->authorize($radUser, $facilityId, 'radiology.orders.read'))->toBeTrue()
        ->and($authorizer->authorize($pharmUser, $facilityId, 'pharmacy.orders.read'))->toBeTrue()
        ->and($authorizer->authorize($procUser, $facilityId, 'clinical-procedure.orders.read'))->toBeTrue();

    // Wrong facility is refused
    expect($authorizer->authorize($labUser, $otherFacilityId, 'laboratory.orders.read'))->toBeFalse()
        ->and($authorizer->authorize($radUser, $otherFacilityId, 'radiology.orders.read'))->toBeFalse();

    // Missing permission is refused
    expect($authorizer->authorize($labUser, $facilityId, 'radiology.orders.read'))->toBeFalse()
        ->and($authorizer->authorize($procUser, $facilityId, 'laboratory.orders.read'))->toBeFalse();

    // User without facility membership is refused
    $noFacilityUser = clinicalLiveSyncUser(['laboratory.orders.read']);
    expect($authorizer->authorize($noFacilityUser, $facilityId, 'laboratory.orders.read'))->toBeFalse();
});

it('carries only facility id on departmental broadcast events', function (): void {
    $labEvent = new LaboratoryQueueUpdated('facility-lab');
    expect($labEvent->broadcastAs())->toBe('queue.updated')
        ->and($labEvent->broadcastOn()[0]->name)->toBe('private-laboratory-queue.facility-lab')
        ->and($labEvent->facilityId)->toBe('facility-lab');

    $radEvent = new RadiologyQueueUpdated('facility-rad');
    expect($radEvent->broadcastAs())->toBe('queue.updated')
        ->and($radEvent->broadcastOn()[0]->name)->toBe('private-radiology-queue.facility-rad')
        ->and($radEvent->facilityId)->toBe('facility-rad');

    $pharmEvent = new PharmacyQueueUpdated('facility-pharm');
    expect($pharmEvent->broadcastAs())->toBe('queue.updated')
        ->and($pharmEvent->broadcastOn()[0]->name)->toBe('private-pharmacy-queue.facility-pharm')
        ->and($pharmEvent->facilityId)->toBe('facility-pharm');

    $procEvent = new ClinicalProcedureQueueUpdated('facility-proc');
    expect($procEvent->broadcastAs())->toBe('queue.updated')
        ->and($procEvent->broadcastOn()[0]->name)->toBe('private-procedure-queue.facility-proc')
        ->and($procEvent->facilityId)->toBe('facility-proc');
});

it('deduplicates announcements within the same database transaction', function (): void {
    Event::fake([
        LaboratoryQueueUpdated::class,
        RadiologyQueueUpdated::class,
        PharmacyQueueUpdated::class,
        ClinicalProcedureQueueUpdated::class,
    ]);

    $facilityId = clinicalLiveSyncFacility();

    DB::transaction(function () use ($facilityId): void {
        app(LaboratoryQueueAnnouncer::class)->markDirty($facilityId);
        app(LaboratoryQueueAnnouncer::class)->markDirty($facilityId);

        app(RadiologyQueueAnnouncer::class)->markDirty($facilityId);
        app(RadiologyQueueAnnouncer::class)->markDirty($facilityId);

        app(PharmacyQueueAnnouncer::class)->markDirty($facilityId);
        app(PharmacyQueueAnnouncer::class)->markDirty($facilityId);

        app(ClinicalProcedureQueueAnnouncer::class)->markDirty($facilityId);
        app(ClinicalProcedureQueueAnnouncer::class)->markDirty($facilityId);
    });

    Event::assertDispatchedTimes(LaboratoryQueueUpdated::class, 1);
    Event::assertDispatchedTimes(RadiologyQueueUpdated::class, 1);
    Event::assertDispatchedTimes(PharmacyQueueUpdated::class, 1);
    Event::assertDispatchedTimes(ClinicalProcedureQueueUpdated::class, 1);
});

function seedLiveSyncPatient(): string
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Amina',
        'last_name' => 'Juma',
        'gender' => 'female',
        'date_of_birth' => '1995-03-20',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $patientId;
}

it('dispatches department queue update, patient-flow update, and in-app notification when lab charge is authorized', function (): void {
    Event::fake([
        LaboratoryQueueUpdated::class,
        PatientFlowBoardUpdated::class,
        NotificationDispatched::class,
    ]);

    $facilityId = clinicalLiveSyncFacility();
    $doctor = clinicalLiveSyncUser(['clinical.physician'], $facilityId);
    $patientId = seedLiveSyncPatient();

    $order = LaboratoryOrderModel::query()->create([
        'facility_id' => $facilityId,
        'order_number' => 'LAB-'.Str::upper(Str::random(6)),
        'patient_id' => $patientId,
        'ordered_by_user_id' => $doctor->id,
        'ordered_at' => now(),
        'test_code' => 'LAB-FBC',
        'test_name' => 'Full Blood Count',
        'priority' => 'routine',
        'status' => 'ordered',
    ]);

    $charge = ServiceChargeModel::query()->create([
        'facility_id' => $facilityId,
        'charge_number' => 'CHG-LAB-'.Str::upper(Str::random(6)),
        'patient_id' => $patientId,
        'source_workflow_kind' => ChargeSourceKind::LABORATORY_ORDER->value,
        'source_workflow_id' => (string) $order->id,
        'description' => 'Full Blood Count',
        'currency_code' => 'TZS',
        'status' => 'authorized',
    ]);

    event(new ServiceChargeAuthorized(
        serviceChargeId: (string) $charge->id,
        patientId: $patientId,
        sourceKind: ChargeSourceKind::LABORATORY_ORDER,
        sourceId: (string) $order->id,
        basis: AuthorizationBasis::PAYMENT,
        actorUserId: $doctor->id,
    ));

    Event::assertDispatched(LaboratoryQueueUpdated::class, fn ($e): bool => $e->facilityId === $facilityId);
    Event::assertDispatched(PatientFlowBoardUpdated::class, fn ($e): bool => $e->facilityId === $facilityId);
    Event::assertDispatched(NotificationDispatched::class, fn ($e): bool => (int) $e->userId === (int) $doctor->id && str_contains($e->title, 'Lab order authorized'));
});

it('dispatches department queue update, patient-flow update, and in-app notification when pharmacy charge is authorized', function (): void {
    Event::fake([
        PharmacyQueueUpdated::class,
        PatientFlowBoardUpdated::class,
        NotificationDispatched::class,
    ]);

    $facilityId = clinicalLiveSyncFacility();
    $doctor = clinicalLiveSyncUser(['clinical.physician'], $facilityId);
    $patientId = seedLiveSyncPatient();

    $order = PharmacyOrderModel::query()->create([
        'facility_id' => $facilityId,
        'order_number' => 'RX-'.Str::upper(Str::random(6)),
        'patient_id' => $patientId,
        'ordered_by_user_id' => $doctor->id,
        'ordered_at' => now(),
        'medication_code' => 'MED-AMOX-500',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => 'Take 1 capsule 3 times a day for 5 days',
        'route' => 'oral',
        'frequency' => 'TID',
        'quantity_prescribed' => 15,
        'quantity_dispensed' => 0,
        'status' => 'pending',
    ]);

    $charge = ServiceChargeModel::query()->create([
        'facility_id' => $facilityId,
        'charge_number' => 'CHG-RX-'.Str::upper(Str::random(6)),
        'patient_id' => $patientId,
        'source_workflow_kind' => ChargeSourceKind::PHARMACY_ORDER->value,
        'source_workflow_id' => (string) $order->id,
        'description' => 'Amoxicillin 500mg',
        'currency_code' => 'TZS',
        'status' => 'authorized',
    ]);

    event(new ServiceChargeAuthorized(
        serviceChargeId: (string) $charge->id,
        patientId: $patientId,
        sourceKind: ChargeSourceKind::PHARMACY_ORDER,
        sourceId: (string) $order->id,
        basis: AuthorizationBasis::PAYMENT,
        actorUserId: $doctor->id,
    ));

    Event::assertDispatched(PharmacyQueueUpdated::class, fn ($e): bool => $e->facilityId === $facilityId);
    Event::assertDispatched(PatientFlowBoardUpdated::class, fn ($e): bool => $e->facilityId === $facilityId);
    Event::assertDispatched(NotificationDispatched::class, fn ($e): bool => (int) $e->userId === (int) $doctor->id && str_contains($e->title, 'Prescription authorized'));
});

it('dispatches department queue update, patient-flow update, and in-app notification when procedure charge is authorized', function (): void {
    Event::fake([
        ClinicalProcedureQueueUpdated::class,
        PatientFlowBoardUpdated::class,
        NotificationDispatched::class,
    ]);

    $facilityId = clinicalLiveSyncFacility();
    $doctor = clinicalLiveSyncUser(['clinical.physician'], $facilityId);
    $patientId = seedLiveSyncPatient();

    $order = ClinicalProcedureOrderModel::query()->create([
        'facility_id' => $facilityId,
        'order_number' => 'PROC-'.Str::upper(Str::random(6)),
        'patient_id' => $patientId,
        'ordered_by_user_id' => $doctor->id,
        'ordered_at' => now(),
        'procedure_description' => 'Minor Wound Suturing',
        'procedure_setting' => 'outpatient',
        'status' => 'ordered',
    ]);

    $charge = ServiceChargeModel::query()->create([
        'facility_id' => $facilityId,
        'charge_number' => 'CHG-PROC-'.Str::upper(Str::random(6)),
        'patient_id' => $patientId,
        'source_workflow_kind' => ChargeSourceKind::CLINICAL_PROCEDURE_ORDER->value,
        'source_workflow_id' => (string) $order->id,
        'description' => 'Minor Wound Suturing',
        'currency_code' => 'TZS',
        'status' => 'authorized',
    ]);

    event(new ServiceChargeAuthorized(
        serviceChargeId: (string) $charge->id,
        patientId: $patientId,
        sourceKind: ChargeSourceKind::CLINICAL_PROCEDURE_ORDER,
        sourceId: (string) $order->id,
        basis: AuthorizationBasis::PAYMENT,
        actorUserId: $doctor->id,
    ));

    Event::assertDispatched(ClinicalProcedureQueueUpdated::class, fn ($e): bool => $e->facilityId === $facilityId);
    Event::assertDispatched(PatientFlowBoardUpdated::class, fn ($e): bool => $e->facilityId === $facilityId);
    Event::assertDispatched(NotificationDispatched::class, fn ($e): bool => (int) $e->userId === (int) $doctor->id && str_contains($e->title, 'Procedure authorized'));
});
