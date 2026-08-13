<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_user_mappings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('device_id');
            $table->integer('device_user_id');
            $table->string('name', 100)->nullable();
            $table->uuid('staff_id')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'device_user_id']);
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_user_mappings');
    }
};
