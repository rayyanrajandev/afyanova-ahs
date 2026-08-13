<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theatre_procedures', function (Blueprint $table): void {
            $table->uuid('theatre_room_service_point_id')->nullable()->after('anesthetist_user_id');
            $table->foreign('theatre_room_service_point_id')
                ->references('id')
                ->on('facility_resources')
                ->nullOnDelete();
            $table->index('theatre_room_service_point_id');
        });

        $this->backfillTheatreRoomServicePointLinks();
    }

    public function down(): void
    {
        Schema::table('theatre_procedures', function (Blueprint $table): void {
            $table->dropForeign(['theatre_room_service_point_id']);
            $table->dropIndex(['theatre_room_service_point_id']);
            $table->dropColumn('theatre_room_service_point_id');
        });
    }

    private function backfillTheatreRoomServicePointLinks(): void
    {
        DB::table('theatre_procedures')
            ->select(['id', 'tenant_id', 'facility_id', 'theatre_room_name'])
            ->whereNull('theatre_room_service_point_id')
            ->whereNotNull('theatre_room_name')
            ->orderBy('id')
            ->cursor()
            ->each(function (object $procedure): void {
                $roomName = trim((string) ($procedure->theatre_room_name ?? ''));
                if ($roomName === '') {
                    return;
                }

                $query = DB::table('facility_resources')
                    ->select(['id'])
                    ->where('resource_type', 'service_point')
                    ->where(function ($builder) use ($roomName): void {
                        $normalizedRoomName = strtolower($roomName);

                        $builder
                            ->whereRaw('LOWER(TRIM(COALESCE(name, \'\'))) = ?', [$normalizedRoomName])
                            ->orWhereRaw('LOWER(TRIM(COALESCE(code, \'\'))) = ?', [$normalizedRoomName]);
                    });

                if ($procedure->facility_id !== null) {
                    $query->where(function ($builder) use ($procedure): void {
                        $builder
                            ->where('facility_id', $procedure->facility_id)
                            ->orWhereNull('facility_id');
                    });
                }

                if ($procedure->tenant_id !== null) {
                    $query->where(function ($builder) use ($procedure): void {
                        $builder
                            ->where('tenant_id', $procedure->tenant_id)
                            ->orWhereNull('tenant_id');
                    });
                }

                $matchedRoom = $query
                    ->orderByRaw('CASE WHEN facility_id IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
                    ->first();

                if ($matchedRoom === null) {
                    return;
                }

                DB::table('theatre_procedures')
                    ->where('id', $procedure->id)
                    ->update([
                        'theatre_room_service_point_id' => $matchedRoom->id,
                    ]);
            });
    }
};
