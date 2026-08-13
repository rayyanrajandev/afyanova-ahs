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
        Schema::create('medical_record_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('medical_record_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['medical_record_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('medical_record_id')
                ->references('id')
                ->on('medical_records')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_record_audit_logs');
    }
};
