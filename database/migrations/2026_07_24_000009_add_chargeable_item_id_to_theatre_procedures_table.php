<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theatre_procedures', function (Blueprint $table): void {
            if (! Schema::hasColumn('theatre_procedures', 'chargeable_item_id')) {
                $table->uuid('chargeable_item_id')->nullable()->after('theatre_procedure_catalog_item_id');
                $table->index('chargeable_item_id', 'theatre_procedures_chargeable_item_id_idx');
                $table->foreign('chargeable_item_id', 'theatre_procedures_chargeable_item_fk')
                    ->references('id')
                    ->on('chargeable_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('theatre_procedures', function (Blueprint $table): void {
            if (Schema::hasColumn('theatre_procedures', 'chargeable_item_id')) {
                $table->dropForeign('theatre_procedures_chargeable_item_fk');
                $table->dropIndex('theatre_procedures_chargeable_item_id_idx');
                $table->dropColumn('chargeable_item_id');
            }
        });
    }
};
