<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_privilege_grants', function (Blueprint $table): void {
            $table->timestamp('requested_at')->nullable()->after('review_due_at');
            $table->timestamp('review_started_at')->nullable()->after('requested_at');
            $table->timestamp('approved_at')->nullable()->after('review_started_at');
            $table->timestamp('activated_at')->nullable()->after('approved_at');

            $table->index(['status', 'requested_at'], 'staff_privilege_grants_status_requested_idx');
            $table->index(['status', 'approved_at'], 'staff_privilege_grants_status_approved_idx');
            $table->index(['status', 'activated_at'], 'staff_privilege_grants_status_activated_idx');
        });

        DB::table('staff_privilege_grants')
            ->orderBy('created_at')
            ->select([
                'id',
                'status',
                'created_at',
                'updated_at',
                'granted_at',
            ])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $createdAt = $this->normalizeDateTime($row->created_at);
                    $updatedAt = $this->normalizeDateTime($row->updated_at);
                    $grantedAt = $this->normalizeDateTime($row->granted_at);
                    $status = strtolower(trim((string) $row->status));

                    $payload = [
                        'requested_at' => $createdAt,
                        'review_started_at' => in_array($status, ['under_review', 'approved', 'active', 'suspended', 'retired'], true)
                            ? ($createdAt ?? $grantedAt ?? $updatedAt)
                            : null,
                        'approved_at' => in_array($status, ['approved', 'active', 'suspended', 'retired'], true)
                            ? ($grantedAt ?? $updatedAt ?? $createdAt)
                            : null,
                        'activated_at' => in_array($status, ['active', 'suspended', 'retired'], true)
                            ? ($grantedAt ?? $updatedAt ?? $createdAt)
                            : null,
                    ];

                    DB::table('staff_privilege_grants')
                        ->where('id', $row->id)
                        ->update($payload);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('staff_privilege_grants', function (Blueprint $table): void {
            $table->dropIndex('staff_privilege_grants_status_requested_idx');
            $table->dropIndex('staff_privilege_grants_status_approved_idx');
            $table->dropIndex('staff_privilege_grants_status_activated_idx');
            $table->dropColumn([
                'requested_at',
                'review_started_at',
                'approved_at',
                'activated_at',
            ]);
        });
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateTimeString();
    }
};
