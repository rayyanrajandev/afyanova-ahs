<?php

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_department_requisitions', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_department_requisitions', 'requesting_department_id')) {
                $table->uuid('requesting_department_id')->nullable()->after('requesting_department');
                $table->index(['requesting_department_id', 'created_at'], 'inv_dept_req_department_id_created_at_idx');
                $table->foreign('requesting_department_id', 'inv_dept_req_department_id_fk')
                    ->references('id')
                    ->on('departments')
                    ->nullOnDelete();
            }
        });

        if (! Schema::hasColumn('inventory_department_requisitions', 'requesting_department_id')) {
            return;
        }

        $departments = DepartmentModel::query()
            ->select(['id', 'name', 'code'])
            ->where('status', 'active')
            ->get()
            ->flatMap(static function (DepartmentModel $department): array {
                $entries = [];
                foreach ([(string) $department->name, (string) $department->code] as $value) {
                    $key = mb_strtolower(trim($value));
                    if ($key !== '') {
                        $entries[$key] = (string) $department->id;
                    }
                }

                return $entries;
            });

        DB::table('inventory_department_requisitions')
            ->select(['id', 'requesting_department'])
            ->whereNull('requesting_department_id')
            ->chunkById(100, static function ($rows) use ($departments): void {
                foreach ($rows as $row) {
                    $departmentId = $departments->get(mb_strtolower(trim((string) $row->requesting_department)));
                    if (! is_string($departmentId) || $departmentId === '') {
                        continue;
                    }

                    DB::table('inventory_department_requisitions')
                        ->where('id', $row->id)
                        ->update(['requesting_department_id' => $departmentId]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('inventory_department_requisitions', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_department_requisitions', 'requesting_department_id')) {
                $table->dropForeign('inv_dept_req_department_id_fk');
                $table->dropIndex('inv_dept_req_department_id_created_at_idx');
                $table->dropColumn('requesting_department_id');
            }
        });
    }
};
