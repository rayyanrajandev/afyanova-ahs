<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (! Schema::hasColumn('appointments', 'consultation_chargeable_item_id')) {
                $table->uuid('consultation_chargeable_item_id')->nullable()->after('consultation_owner_assigned_at');
                $table->index('consultation_chargeable_item_id', 'appointments_consultation_chargeable_item_id_idx');
                $table->foreign('consultation_chargeable_item_id', 'appointments_consultation_chargeable_item_fk')
                    ->references('id')
                    ->on('chargeable_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('appointments', 'consultation_chargeable_item_id')) {
                $table->dropForeign('appointments_consultation_chargeable_item_fk');
                $table->dropIndex('appointments_consultation_chargeable_item_id_idx');
                $table->dropColumn('consultation_chargeable_item_id');
            }
        });
    }
};
