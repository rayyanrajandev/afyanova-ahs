<?php

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderAuditLogModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The dispenser was never unrecorded, only unindexed: every release writes a
 * `pharmacy-order.status.updated` audit row carrying the actor and the
 * transition. The migration reads the identity back out of that log rather than
 * abandoning history to nulls, and this exercises the same routine against the
 * shapes it will actually meet.
 */
function backfillDispensersFromAuditLog(): void
{
    DB::table('pharmacy_orders')
        ->select('id')
        ->whereNotNull('dispensed_at')
        ->whereNull('dispensed_by_user_id')
        ->orderBy('id')
        ->chunkById(500, function ($orders): void {
            $orderIds = collect($orders)->pluck('id')->all();

            $logs = DB::table('pharmacy_order_audit_logs')
                ->select(['pharmacy_order_id', 'actor_id', 'metadata'])
                ->whereIn('pharmacy_order_id', $orderIds)
                ->where('action', 'pharmacy-order.status.updated')
                ->whereNotNull('actor_id')
                ->orderBy('created_at')
                ->get();

            $dispenserByOrder = [];

            foreach ($logs as $log) {
                if (array_key_exists($log->pharmacy_order_id, $dispenserByOrder)) {
                    continue;
                }

                $metadata = json_decode((string) $log->metadata, true);
                $movedTo = $metadata['transition']['to'] ?? null;

                if (in_array($movedTo, ['partially_dispensed', 'dispensed'], true)) {
                    $dispenserByOrder[$log->pharmacy_order_id] = $log->actor_id;
                }
            }

            foreach ($dispenserByOrder as $orderId => $actorId) {
                DB::table('pharmacy_orders')
                    ->where('id', $orderId)
                    ->update(['dispensed_by_user_id' => $actorId]);
            }
        });
}

function backfillPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.strtoupper(Str::random(8)),
        'first_name' => 'Halima',
        'last_name' => 'Mdee',
        'gender' => 'female',
        'date_of_birth' => '1994-02-11',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function backfillOrder(array $overrides = []): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'patient_id' => backfillPatient()->id,
        'order_number' => 'RX-'.strtoupper(Str::random(8)),
        'medication_code' => 'ATC:N02BE01',
        'medication_name' => 'Paracetamol 500mg',
        'dosage_instruction' => 'Take 1 tablet every 8 hours',
        'quantity_prescribed' => 12,
        'ordered_at' => now()->subDay(),
        'status' => 'dispensed',
        'dispensed_at' => now()->subHours(2),
        'entry_state' => 'active',
    ], $overrides));
}

function backfillAuditRow(string $orderId, ?int $actorId, string $movedTo, $at): void
{
    PharmacyOrderAuditLogModel::query()->create([
        'id' => (string) Str::uuid(),
        'pharmacy_order_id' => $orderId,
        'actor_id' => $actorId,
        'action' => 'pharmacy-order.status.updated',
        'changes' => [],
        'metadata' => ['transition' => ['from' => 'pending', 'to' => $movedTo]],
        'created_at' => $at,
    ]);
}

it('recovers the dispenser from the audit log', function (): void {
    $preparer = User::factory()->create();
    $dispenser = User::factory()->create();
    $order = backfillOrder();
    backfillAuditRow($order->id, $preparer->id, 'in_preparation', now()->subHours(4));
    backfillAuditRow($order->id, $dispenser->id, 'dispensed', now()->subHours(2));

    backfillDispensersFromAuditLog();

    expect(PharmacyOrderModel::query()->find($order->id)?->dispensed_by_user_id)
        ->toBe($dispenser->id);
});

it('credits the first release, not the last', function (): void {
    $preparer = User::factory()->create();
    $starter = User::factory()->create();
    $finisher = User::factory()->create();
    $order = backfillOrder();
    backfillAuditRow($order->id, $preparer->id, 'in_preparation', now()->subHours(5));
    backfillAuditRow($order->id, $starter->id, 'partially_dispensed', now()->subHours(3));
    backfillAuditRow($order->id, $finisher->id, 'dispensed', now()->subHour());

    backfillDispensersFromAuditLog();

    // Whoever began handing the medicine over is the one the second pair of
    // eyes is checking, so completing the fill must not reassign it.
    expect(PharmacyOrderModel::query()->find($order->id)?->dispensed_by_user_id)
        ->toBe($starter->id);
});

it('ignores transitions that are not releases', function (): void {
    $order = backfillOrder();
    backfillAuditRow($order->id, User::factory()->create()->id, 'in_preparation', now()->subHours(4));
    backfillAuditRow($order->id, User::factory()->create()->id, 'cancelled', now()->subHours(3));

    backfillDispensersFromAuditLog();

    expect(PharmacyOrderModel::query()->find($order->id)?->dispensed_by_user_id)
        ->toBeNull();
});

it('leaves orders whose log has been pruned alone', function (): void {
    $order = backfillOrder();

    backfillDispensersFromAuditLog();

    expect(PharmacyOrderModel::query()->find($order->id)?->dispensed_by_user_id)
        ->toBeNull();
});

it('does not touch orders that were never dispensed', function (): void {
    $order = backfillOrder(['status' => 'pending', 'dispensed_at' => null]);
    backfillAuditRow($order->id, User::factory()->create()->id, 'dispensed', now()->subHour());

    backfillDispensersFromAuditLog();

    expect(PharmacyOrderModel::query()->find($order->id)?->dispensed_by_user_id)
        ->toBeNull();
});

it('keeps a dispenser that is already recorded', function (): void {
    $recorded = User::factory()->create();
    $order = backfillOrder(['dispensed_by_user_id' => $recorded->id]);
    backfillAuditRow($order->id, User::factory()->create()->id, 'dispensed', now()->subHour());

    backfillDispensersFromAuditLog();

    expect(PharmacyOrderModel::query()->find($order->id)?->dispensed_by_user_id)
        ->toBe($recorded->id);
});
