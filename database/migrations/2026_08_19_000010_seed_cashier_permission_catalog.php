<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cashier permission namespace.
 *
 * A clean replacement for the 65 billing.*, pos.* and claims.* permissions
 * dropped in Phase 2, rather than a rename of them: the old catalogue was
 * shaped around invoices, registers and claims, none of which exist any more,
 * and carrying its vocabulary forward would describe a system that is not
 * there.
 *
 * Seeded here rather than only granted in config/roles.php so the RBAC usage
 * auditor does not flag them as checked-but-never-seeded — the bug class that
 * tripwire exists for.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const PERMISSIONS = [
        // Reaching the workspace at all.
        'cashier.access',

        // Charges. Read is deliberately wide — reception and clinical staff
        // need to see whether a patient still owes, or they cannot explain to
        // the patient why they are being sent to the counter.
        'cashier.charges.read',
        'cashier.charges.create',
        'cashier.charges.cancel',

        // Money.
        'cashier.payments.record',
        'cashier.payments.read',
        'cashier.payments.reverse',

        'cashier.receipts.read',
        'cashier.receipts.reprint',

        // The drawer.
        'cashier.sessions.read',
        'cashier.sessions.open',
        'cashier.sessions.close',
        'cashier.sessions.move-cash',

        // Second-person controls. Held by a supervisor, never by the cashier
        // whose work they check.
        'cashier.sessions.approve-variance',
        'cashier.refunds.request',
        'cashier.refunds.approve',
        'cashier.waivers.approve',

        // Clinical override: treat now, reconcile later. Belongs to triage and
        // clinical leads, not to finance — the decision is a clinical one.
        'cashier.charges.emergency-override',

        'cashier.reports.read',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $existing = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('name')
            ->flip();

        $now = now();
        $rows = [];

        foreach (self::PERMISSIONS as $name) {
            if ($existing->has($name)) {
                continue;
            }

            $rows[] = ['name' => $name, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($rows !== []) {
            DB::table('permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        foreach (['permission_role', 'permission_user'] as $pivot) {
            if (Schema::hasTable($pivot)) {
                DB::table($pivot)->whereIn('permission_id', $ids)->delete();
            }
        }

        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
