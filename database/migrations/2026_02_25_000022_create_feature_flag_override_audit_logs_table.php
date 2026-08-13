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
        Schema::create('feature_flag_override_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('feature_flag_override_id');
            $table->string('action', 50);
            $table->foreignId('actor_id')->nullable()->index();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['feature_flag_override_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_flag_override_audit_logs');
    }
};
