<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            // Module-level hyphens → dot notation
            'laboratory-orders.view-audit-logs' => 'laboratory.orders.audit-logs.view',
            'pharmacy-orders.view-audit-logs' => 'pharmacy.orders.audit-logs.view',
            'radiology-orders.view-audit-logs' => 'radiology.orders.audit-logs.view',
            'medical-records.view-audit-logs' => 'medical.records.audit-logs.view',
            'billing-invoices.view-audit-logs' => 'billing.invoices.audit-logs.view',
        ];

        foreach ($renames as $old => $new) {
            DB::table('permissions')
                ->where('name', $old)
                ->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        $renames = [
            'laboratory.orders.audit-logs.view' => 'laboratory-orders.view-audit-logs',
            'pharmacy.orders.audit-logs.view' => 'pharmacy-orders.view-audit-logs',
            'radiology.orders.audit-logs.view' => 'radiology-orders.view-audit-logs',
            'medical.records.audit-logs.view' => 'medical-records.view-audit-logs',
            'billing.invoices.audit-logs.view' => 'billing-invoices.view-audit-logs',
        ];

        foreach ($renames as $old => $new) {
            DB::table('permissions')
                ->where('name', $old)
                ->update(['name' => $new]);
        }
    }
};
