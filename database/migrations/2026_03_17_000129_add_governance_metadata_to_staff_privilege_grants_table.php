<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('staff_privilege_grants', function (Blueprint $table): void {
            $table->unsignedBigInteger('reviewer_user_id')->nullable()->after('granted_by_user_id');
            $table->string('review_note', 255)->nullable()->after('reviewer_user_id');
            $table->unsignedBigInteger('approver_user_id')->nullable()->after('review_note');
            $table->string('approval_note', 255)->nullable()->after('approver_user_id');

            $table->index(['reviewer_user_id'], 'staff_privilege_grants_reviewer_user_idx');
            $table->index(['approver_user_id'], 'staff_privilege_grants_approver_user_idx');

            $table->foreign('reviewer_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('approver_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_privilege_grants', function (Blueprint $table): void {
            $table->dropForeign(['reviewer_user_id']);
            $table->dropForeign(['approver_user_id']);
            $table->dropIndex('staff_privilege_grants_reviewer_user_idx');
            $table->dropIndex('staff_privilege_grants_approver_user_idx');
            $table->dropColumn([
                'reviewer_user_id',
                'review_note',
                'approver_user_id',
                'approval_note',
            ]);
        });
    }
};
