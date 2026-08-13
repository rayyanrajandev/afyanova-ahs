<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_regulatory_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_profile_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('primary_regulator_code', 30);
            $table->string('cadre_code', 100);
            $table->string('professional_title', 150);
            $table->string('registration_type', 80);
            $table->string('practice_authority_level', 40);
            $table->string('supervision_level', 40);
            $table->string('good_standing_status', 40);
            $table->date('good_standing_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('staff_profile_id');
            $table->index(['tenant_id', 'primary_regulator_code']);
            $table->index(['tenant_id', 'cadre_code']);

            $table->foreign('staff_profile_id')
                ->references('id')
                ->on('staff_profiles')
                ->cascadeOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('updated_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_regulatory_profiles');
    }
};
