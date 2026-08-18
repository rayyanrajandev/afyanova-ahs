<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record who dispensed, so verification can require someone else.
 *
 * The table has carried `verified_by_user_id` since 2026_02_25_000037 but never
 * the counterpart, so the second-pair-of-eyes check the laboratory and radiology
 * verify paths enforce had nothing to compare against in pharmacy: the same
 * pharmacist could hand the medicine over and then sign off their own work.
 *
 * The dispenser was not unrecorded, only unindexed — every release writes a
 * `pharmacy-order.status.updated` audit row carrying the actor and the
 * transition. The backfill below reads the identity back out of that log rather
 * than abandoning history to nulls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->foreignId('dispensed_by_user_id')
                ->nullable()
                ->after('dispensed_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        $this->backfillFromAuditLog();
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dispensed_by_user_id');
        });
    }

    /**
     * The earliest release recorded against each order names its dispenser.
     *
     * Earliest, not latest: a partial fill is a release, and the person who
     * began handing the medicine over is the one whose work the verification is
     * meant to check.
     *
     * The transition is read out of the JSON in PHP rather than in SQL. This
     * runs on PostgreSQL in production and SQLite under test, and the JSON
     * operators the three engines expose do not agree — a `json_unquote` here
     * would have passed review and failed on every environment we run.
     *
     * Orders whose log has been pruned stay null, which
     * VerifyPharmacyOrderDispenseUseCase treats as "dispenser unknown" and lets
     * through. Refusing them instead would freeze every order dispensed before
     * this migration while gaining nothing: an unknown dispenser cannot be
     * shown to be the person signing off.
     */
    private function backfillFromAuditLog(): void
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
                    // Ordered by created_at, so the first release wins.
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
};
