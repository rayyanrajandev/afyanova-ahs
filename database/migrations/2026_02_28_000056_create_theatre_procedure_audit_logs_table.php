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
        Schema::create('theatre_procedure_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('theatre_procedure_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['theatre_procedure_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('theatre_procedure_id')
                ->references('id')
                ->on('theatre_procedures')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theatre_procedure_audit_logs');
    }
};
