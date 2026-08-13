<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Infrastructure\Models\PlatformOperationalFlagModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformOperationalFlagController extends Controller
{
    private const ALLOWED_FLAG_TYPES = ['mci_mode'];

    /**
     * Return the current status of all operational flags.
     * No additional permission gate — any authenticated user in scope may read flag status.
     */
    public function status(): JsonResponse
    {
        $flags = PlatformOperationalFlagModel::query()
            ->whereNull('facility_id')
            ->whereIn('flag_type', self::ALLOWED_FLAG_TYPES)
            ->get()
            ->keyBy('flag_type');

        $result = [];
        foreach (self::ALLOWED_FLAG_TYPES as $type) {
            $flag = $flags->get($type);
            $result[$type] = [
                'is_active'    => $flag?->is_active ?? false,
                'activated_at' => $flag?->activated_at?->toIso8601String(),
                'note'         => $flag?->note,
            ];
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Activate an operational flag.
     * Requires: emergency.triage.update-status
     */
    public function activate(Request $request, string $flagType): JsonResponse
    {
        if (! in_array($flagType, self::ALLOWED_FLAG_TYPES, true)) {
            return response()->json(['message' => 'Unknown flag type.'], 422);
        }

        $note = trim((string) ($request->input('note') ?? ''));

        $existing = PlatformOperationalFlagModel::query()
            ->whereNull('facility_id')
            ->where('flag_type', $flagType)
            ->first();

        if ($existing) {
            $existing->fill([
                'is_active'              => true,
                'activated_by_user_id'   => $request->user()?->id,
                'activated_at'           => now(),
                'deactivated_at'         => null,
                'note'                   => $note ?: null,
            ])->save();
            $flag = $existing;
        } else {
            $flag = PlatformOperationalFlagModel::create([
                'facility_id'            => null,
                'flag_type'              => $flagType,
                'is_active'              => true,
                'activated_by_user_id'   => $request->user()?->id,
                'activated_at'           => now(),
                'deactivated_at'         => null,
                'note'                   => $note ?: null,
            ]);
        }

        return response()->json(['data' => [
            'flag_type'    => $flagType,
            'is_active'    => true,
            'activated_at' => $flag->activated_at?->toIso8601String(),
        ]]);
    }

    /**
     * Deactivate an operational flag.
     * Requires: emergency.triage.update-status
     */
    public function deactivate(Request $request, string $flagType): JsonResponse
    {
        if (! in_array($flagType, self::ALLOWED_FLAG_TYPES, true)) {
            return response()->json(['message' => 'Unknown flag type.'], 422);
        }

        $existing = PlatformOperationalFlagModel::query()
            ->whereNull('facility_id')
            ->where('flag_type', $flagType)
            ->first();

        if ($existing) {
            $existing->fill([
                'is_active'      => false,
                'deactivated_at' => now(),
            ])->save();
            $flag = $existing;
        } else {
            $flag = PlatformOperationalFlagModel::create([
                'facility_id'    => null,
                'flag_type'      => $flagType,
                'is_active'      => false,
                'deactivated_at' => now(),
            ]);
        }

        return response()->json(['data' => [
            'flag_type'      => $flagType,
            'is_active'      => false,
            'deactivated_at' => $flag->deactivated_at?->toIso8601String(),
        ]]);
    }
}
