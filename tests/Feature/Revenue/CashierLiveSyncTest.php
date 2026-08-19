<?php

use App\Models\User;
use App\Modules\Revenue\Application\Services\CashierQueueAnnouncer;
use App\Modules\Revenue\Application\Services\CashierQueueChannelAuthorizer;
use App\Modules\Revenue\Domain\Events\CashierQueueUpdated;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

/**
 * Two tills, one queue.
 *
 * The channel and its authorizer are tested directly: phpunit.xml forces
 * BROADCAST_CONNECTION=null, so /broadcasting/auth never invokes the closure
 * in routes/channels.php and a test that went through HTTP would assert
 * nothing.
 */
/**
 * facility_user carries real foreign keys, so a membership needs a real
 * tenant and facility behind it.
 */
function liveSyncFacility(): string
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

function liveSyncUser(array $permissions, ?string $facilityId = null): User
{
    $user = makeUserWithRole($permissions, 'LIVE.SYNC.'.Str::upper(Str::random(4)));

    if ($facilityId !== null) {
        DB::table('facility_user')->insert([
            'user_id' => $user->id,
            'facility_id' => $facilityId,
            'role' => 'FINANCE.CASHIER',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $user->refresh();
}

it('announces when a charge is written for a facility', function (): void {
    Event::fake([CashierQueueUpdated::class]);

    $facilityId = liveSyncFacility();

    // Written through the model rather than the use case so the observer — the
    // thing under test — is what triggers the announcement, and so the charge
    // carries a facility. A charge raised with no facility scope deliberately
    // announces nothing; see the null case below.
    ServiceChargeModel::query()->create([
        'facility_id' => $facilityId,
        'charge_number' => 'CHG-SYNC-'.Str::upper(Str::random(6)),
        'patient_id' => RevenueTestSupport::patientId(),
        'source_workflow_kind' => ChargeSourceKind::MANUAL->value,
        'description' => 'Consultation',
        'currency_code' => 'TZS',
        'status' => 'pending_payment',
    ]);

    Event::assertDispatched(
        CashierQueueUpdated::class,
        fn (CashierQueueUpdated $e): bool => $e->facilityId === $facilityId,
    );
});

it('announces once for a whole counter transaction, not once per row', function (): void {
    // A single payment touches the charge, the payment, its allocations and
    // the receipt. Every listener does the same thing with any of them —
    // refetch — so four broadcasts would be three wasted round trips per sale.
    Event::fake([CashierQueueUpdated::class]);

    $facilityId = liveSyncFacility();
    $announcer = app(CashierQueueAnnouncer::class);

    // The transaction is the unit of deduplication, because it is the unit the
    // counter actually works in — RecordCashPaymentUseCase wraps the whole sale
    // in one.
    DB::transaction(function () use ($announcer, $facilityId): void {
        $announcer->markDirty($facilityId);
        $announcer->markDirty($facilityId);
        $announcer->markDirty($facilityId);
    });

    Event::assertDispatchedTimes(CashierQueueUpdated::class, 1);
});

it('carries the facility and nothing else', function (): void {
    // The payload is a trigger, never a data source: a broadcast carrying
    // queue rows would be a second copy of the ledger travelling over a wire,
    // free to disagree with it by the time it lands.
    $event = new CashierQueueUpdated('facility-1');

    expect($event->broadcastAs())->toBe('queue.updated')
        ->and($event->broadcastOn()[0]->name)->toBe('private-cashier-queue.facility-1')
        ->and($event->facilityId)->toBe('facility-1')
        // No queue rows ride along: the listener refetches from the ledger.
        ->and(method_exists($event, 'broadcastWith'))->toBeFalse();
});

it('broadcasts nowhere when the facility is unknown', function (): void {
    // A globally scoped write has no till to notify, and must not fan out to
    // every facility.
    expect((new CashierQueueUpdated(null))->broadcastOn())->toBe([]);
});

it('lets a cashier of that facility listen', function (): void {
    $facilityId = liveSyncFacility();
    $user = liveSyncUser(['cashier.charges.read'], $facilityId);

    expect(app(CashierQueueChannelAuthorizer::class)->authorize($user, $facilityId))->toBeTrue();
});

it('refuses someone without the queue permission', function (): void {
    // A channel that announced changes to a queue the endpoint would refuse to
    // show is a side door, not a feature.
    $facilityId = liveSyncFacility();
    $user = liveSyncUser(['patients.read'], $facilityId);

    expect(app(CashierQueueChannelAuthorizer::class)->authorize($user, $facilityId))->toBeFalse();
});

it('refuses a cashier from another facility', function (): void {
    $theirFacility = liveSyncFacility();
    $someoneElses = liveSyncFacility();
    $user = liveSyncUser(['cashier.charges.read'], $theirFacility);

    expect(app(CashierQueueChannelAuthorizer::class)->authorize($user, $someoneElses))->toBeFalse();
});

it('refuses a cashier with no facility membership at all', function (): void {
    $user = liveSyncUser(['cashier.charges.read']);

    expect(app(CashierQueueChannelAuthorizer::class)->authorize($user, (string) Str::uuid()))
        ->toBeFalse();
});
