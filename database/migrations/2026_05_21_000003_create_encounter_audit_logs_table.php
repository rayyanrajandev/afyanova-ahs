<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounter_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('encounter_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['encounter_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('encounter_id')
                ->references('id')
                ->on('encounters')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_audit_logs');
    }
};
