<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_referral_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('appointment_referral_id');
            $table->uuid('appointment_id');
            $table->string('action', 120);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');

            $table->foreign('appointment_referral_id')
                ->references('id')
                ->on('appointment_referrals')
                ->cascadeOnDelete();
            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->cascadeOnDelete();
            $table->index(['appointment_referral_id', 'created_at']);
            $table->index(['appointment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_referral_audit_logs');
    }
};

