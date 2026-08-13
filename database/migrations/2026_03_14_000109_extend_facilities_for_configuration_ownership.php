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
        Schema::table('facilities', function (Blueprint $table): void {
            $table->string('status_reason', 255)->nullable()->after('status');
            $table->foreignId('operations_owner_user_id')->nullable()->after('status_reason')->constrained('users')->nullOnDelete();
            $table->foreignId('clinical_owner_user_id')->nullable()->after('operations_owner_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('administrative_owner_user_id')->nullable()->after('clinical_owner_user_id')->constrained('users')->nullOnDelete();

            $table->index('operations_owner_user_id');
            $table->index('clinical_owner_user_id');
            $table->index('administrative_owner_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table): void {
            $table->dropIndex(['operations_owner_user_id']);
            $table->dropIndex(['clinical_owner_user_id']);
            $table->dropIndex(['administrative_owner_user_id']);

            $table->dropConstrainedForeignId('operations_owner_user_id');
            $table->dropConstrainedForeignId('clinical_owner_user_id');
            $table->dropConstrainedForeignId('administrative_owner_user_id');
            $table->dropColumn('status_reason');
        });
    }
};
