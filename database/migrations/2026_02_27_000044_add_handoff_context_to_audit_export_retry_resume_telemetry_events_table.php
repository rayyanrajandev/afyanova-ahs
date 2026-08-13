<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_export_retry_resume_telemetry_events', function (Blueprint $table): void {
            $table->uuid('target_resource_id')->nullable()->after('facility_id')->index();
            $table->uuid('export_job_id')->nullable()->after('target_resource_id')->index();
            $table->string('handoff_status_group', 20)->nullable()->after('export_job_id');
            $table->unsignedInteger('handoff_page')->nullable()->after('handoff_status_group');
            $table->unsignedInteger('handoff_per_page')->nullable()->after('handoff_page');
        });
    }

    public function down(): void
    {
        Schema::table('audit_export_retry_resume_telemetry_events', function (Blueprint $table): void {
            $table->dropColumn([
                'target_resource_id',
                'export_job_id',
                'handoff_status_group',
                'handoff_page',
                'handoff_per_page',
            ]);
        });
    }
};

