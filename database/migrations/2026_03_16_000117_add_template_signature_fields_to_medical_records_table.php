<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('medical_records', 'signed_by_user_id')) {
                $table->foreignId('signed_by_user_id')->nullable()->after('status_reason')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('medical_records', 'signed_at')) {
                $table->timestamp('signed_at')->nullable()->after('signed_by_user_id');
                $table->index('signed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            if (Schema::hasColumn('medical_records', 'signed_at')) {
                $table->dropIndex(['signed_at']);
                $table->dropColumn('signed_at');
            }

            if (Schema::hasColumn('medical_records', 'signed_by_user_id')) {
                $table->dropConstrainedForeignId('signed_by_user_id');
            }
        });
    }
};
