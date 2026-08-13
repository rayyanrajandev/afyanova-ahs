<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            if (Schema::hasColumn('medical_records', 'appointment_referral_id')) {
                return;
            }

            $table->uuid('appointment_referral_id')->nullable();
            $table->foreign('appointment_referral_id')
                ->references('id')
                ->on('appointment_referrals')
                ->nullOnDelete();
            $table->index('appointment_referral_id');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('medical_records', 'appointment_referral_id')) {
                return;
            }

            $table->dropForeign(['appointment_referral_id']);
            $table->dropIndex(['appointment_referral_id']);
            $table->dropColumn('appointment_referral_id');
        });
    }
};
