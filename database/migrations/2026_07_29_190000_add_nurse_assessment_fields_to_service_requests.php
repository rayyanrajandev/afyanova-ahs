<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->foreignUuid('encounter_id')
                ->nullable()
                ->constrained('encounters')
                ->cascadeOnDelete();

            $table->foreignId('assessed_by_user_id')
                ->nullable()
                ->constrained('users');

            $table->timestamp('assessed_at')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('encounter_id');
            $table->dropConstrainedForeignId('assessed_by_user_id');
            $table->dropColumn('assessed_at');
        });
    }
};
