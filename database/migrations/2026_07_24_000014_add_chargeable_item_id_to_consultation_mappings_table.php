<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_mappings', function (Blueprint $table): void {
            if (! Schema::hasColumn('consultation_mappings', 'chargeable_item_id')) {
                $table->uuid('chargeable_item_id')->nullable()->after('billing_service_catalog_item_id');
                $table->index('chargeable_item_id', 'consultation_mappings_chargeable_item_id_idx');
                $table->foreign('chargeable_item_id', 'consultation_mappings_chargeable_item_fk')
                    ->references('id')
                    ->on('chargeable_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('consultation_mappings', function (Blueprint $table): void {
            if (Schema::hasColumn('consultation_mappings', 'chargeable_item_id')) {
                $table->dropForeign('consultation_mappings_chargeable_item_fk');
                $table->dropIndex('consultation_mappings_chargeable_item_id_idx');
                $table->dropColumn('chargeable_item_id');
            }
        });
    }
};
