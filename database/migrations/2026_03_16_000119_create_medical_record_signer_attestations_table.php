<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_record_signer_attestations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('medical_record_id');
            $table->foreignId('attested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('attestation_note');
            $table->timestamp('attested_at');
            $table->timestamps();

            $table->index(['medical_record_id', 'attested_at'], 'medical_record_attestations_record_attested_at_index');

            $table->foreign('medical_record_id')
                ->references('id')
                ->on('medical_records')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_signer_attestations');
    }
};
