<?php

use App\Support\CatalogGovernance\CatalogPlacementAuditor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_integrity_audit_findings')) {
            Schema::create('catalog_integrity_audit_findings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('issue_code', 120)->index();
                $table->string('severity', 30)->default('warning')->index();
                $table->string('module', 80)->index();
                $table->string('source_table', 120)->nullable();
                $table->uuid('source_id')->nullable()->index();
                $table->text('summary');
                $table->json('payload')->nullable();
                $table->string('resolution', 80)->default('audited')->index();
                $table->timestamps();
            });
        }

        app(CatalogPlacementAuditor::class)->repairInventoryPlacement();
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_integrity_audit_findings');
    }
};
