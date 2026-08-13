<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_resources', function (Blueprint $table): void {
            if (! Schema::hasColumn('facility_resources', 'chargeable_item_id')) {
                $table->uuid('chargeable_item_id')->nullable()->after('notes');
                $table->index('chargeable_item_id', 'facility_resources_chargeable_item_id_idx');
                $table->foreign('chargeable_item_id', 'facility_resources_chargeable_item_fk')
                    ->references('id')
                    ->on('chargeable_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('facility_resources', function (Blueprint $table): void {
            if (Schema::hasColumn('facility_resources', 'chargeable_item_id')) {
                $table->dropForeign('facility_resources_chargeable_item_fk');
                $table->dropIndex('facility_resources_chargeable_item_id_idx');
                $table->dropColumn('chargeable_item_id');
            }
        });
    }
};
