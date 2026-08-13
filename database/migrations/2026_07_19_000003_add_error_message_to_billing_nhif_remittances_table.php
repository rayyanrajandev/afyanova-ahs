<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('billing_nhif_remittances') || Schema::hasColumn('billing_nhif_remittances', 'error_message')) {
            return;
        }

        Schema::table('billing_nhif_remittances', function (Blueprint $table): void {
            $table->text('error_message')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('billing_nhif_remittances') || ! Schema::hasColumn('billing_nhif_remittances', 'error_message')) {
            return;
        }

        Schema::table('billing_nhif_remittances', function (Blueprint $table): void {
            $table->dropColumn('error_message');
        });
    }
};
