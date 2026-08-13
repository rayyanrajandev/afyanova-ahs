<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_warehouse_transfers', function (Blueprint $table) {
            $table->bigInteger('packed_by_user_id')->unsigned()->nullable()->after('approved_by_user_id');
            $table->timestamp('packed_at')->nullable()->after('approved_at');
            $table->string('dispatch_note_number', 80)->nullable()->after('transfer_number');
            $table->text('pack_notes')->nullable()->after('notes');

            $table->foreign('packed_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['status', 'packed_at']);
        });

        Schema::table('inventory_warehouse_transfer_lines', function (Blueprint $table) {
            $table->decimal('packed_quantity', 14, 3)->nullable()->after('requested_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_warehouse_transfer_lines', function (Blueprint $table) {
            $table->dropColumn('packed_quantity');
        });

        Schema::table('inventory_warehouse_transfers', function (Blueprint $table) {
            $table->dropForeign(['packed_by_user_id']);
            $table->dropIndex(['status', 'packed_at']);
            $table->dropColumn([
                'packed_by_user_id',
                'packed_at',
                'dispatch_note_number',
                'pack_notes',
            ]);
        });
    }
};
