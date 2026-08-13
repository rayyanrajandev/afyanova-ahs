<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('ip', 45);
            $table->integer('port')->default(4370);
            $table->string('serial', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('location', 200)->nullable();
            $table->string('password', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_devices');
    }
};
