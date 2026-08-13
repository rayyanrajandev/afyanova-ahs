<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
            $table->integer('uid');
            $table->string('user_id', 50);
            $table->uuid('staff_id')->nullable();
            $table->integer('state');
            $table->integer('type')->nullable();
            $table->dateTime('record_time');
            $table->dateTime('pulled_at');
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'uid', 'record_time'], 'attendance_logs_device_uid_time_unique');
            $table->index('staff_id');
            $table->index('record_time');
            $table->index('pulled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
