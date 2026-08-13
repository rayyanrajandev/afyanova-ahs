<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('pharmacy_orders', 'prescribed_unit')) {
                $table->string('prescribed_unit', 40)->nullable()->after('quantity_prescribed');
            }

            if (! Schema::hasColumn('pharmacy_orders', 'dispensed_unit')) {
                $table->string('dispensed_unit', 40)->nullable()->after('quantity_dispensed');
            }

            if (! Schema::hasColumn('pharmacy_orders', 'dose_quantity')) {
                $table->decimal('dose_quantity', 12, 4)->nullable()->after('dosage_instruction');
            }

            if (! Schema::hasColumn('pharmacy_orders', 'dose_unit')) {
                $table->string('dose_unit', 40)->nullable()->after('dose_quantity');
            }

            if (! Schema::hasColumn('pharmacy_orders', 'route')) {
                $table->string('route', 60)->nullable()->after('dose_unit');
            }

            if (! Schema::hasColumn('pharmacy_orders', 'frequency')) {
                $table->string('frequency', 120)->nullable()->after('route');
            }

            if (! Schema::hasColumn('pharmacy_orders', 'duration_value')) {
                $table->decimal('duration_value', 8, 2)->nullable()->after('frequency');
            }

            if (! Schema::hasColumn('pharmacy_orders', 'duration_unit')) {
                $table->string('duration_unit', 40)->nullable()->after('duration_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $columns = [
                'prescribed_unit',
                'dispensed_unit',
                'dose_quantity',
                'dose_unit',
                'route',
                'frequency',
                'duration_value',
                'duration_unit',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('pharmacy_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
