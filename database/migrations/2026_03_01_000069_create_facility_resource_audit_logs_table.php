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
        Schema::create('facility_resource_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('facility_resource_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['facility_resource_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('facility_resource_id')
                ->references('id')
                ->on('facility_resources')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facility_resource_audit_logs');
    }
};

