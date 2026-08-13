<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_export_retry_resume_telemetry_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('module_key', 20)->index();
            $table->string('event_type', 20)->index();
            $table->string('failure_reason', 120)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_export_retry_resume_telemetry_events');
    }
};

