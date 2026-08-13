<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_procurement_requests', 'source_department_requisition_id')) {
                $table->uuid('source_department_requisition_id')->nullable()->after('supplier_id');
            }

            if (! Schema::hasColumn('inventory_procurement_requests', 'source_department_requisition_line_id')) {
                $table->uuid('source_department_requisition_line_id')->nullable()->after('source_department_requisition_id');
            }
        });

        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            $table->foreign('source_department_requisition_id', 'inv_pr_source_dept_req_fk')
                ->references('id')
                ->on('inventory_department_requisitions')
                ->nullOnDelete();

            $table->foreign('source_department_requisition_line_id', 'inv_pr_source_dept_req_line_fk')
                ->references('id')
                ->on('inventory_department_requisition_lines')
                ->nullOnDelete();

            $table->index(
                ['source_department_requisition_id', 'source_department_requisition_line_id'],
                'inv_pr_source_dept_req_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            $table->dropForeign('inv_pr_source_dept_req_fk');
            $table->dropForeign('inv_pr_source_dept_req_line_fk');
            $table->dropIndex('inv_pr_source_dept_req_idx');
            $table->dropColumn([
                'source_department_requisition_id',
                'source_department_requisition_line_id',
            ]);
        });
    }
};
