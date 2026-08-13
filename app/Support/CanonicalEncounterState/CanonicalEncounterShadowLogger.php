<?php

namespace App\Support\CanonicalEncounterState;

use Illuminate\Support\Facades\Log;

/**
 * Shadow Mode logging only (design doc 01, §3 Mode B / §4 Divergence Handling).
 *
 * Writes to a dedicated log channel — never to medical_record_audit_logs or
 * encounter_audit_logs, which remain exclusively clinical audit trails
 * (design doc 01, §4.1). This class never writes to any application table.
 */
final class CanonicalEncounterShadowLogger
{
    private const CHANNEL = 'canonical_encounter_shadow';

    public function log(CanonicalEncounterStateSnapshot $snapshot): void
    {
        try {
            Log::channel(self::CHANNEL)->info(
                'canonical_encounter_state.shadow_evaluation',
                $snapshot->toLogContext(),
            );
        } catch (\Throwable) {
            // Logging failure must never surface to the caller — this class is used from a
            // non-blocking, try/catch-isolated integration hook (design doc 01, §2.4).
        }
    }

    public function logFailure(string $encounterId, \Throwable $exception): void
    {
        try {
            Log::channel(self::CHANNEL)->warning(
                'canonical_encounter_state.shadow_evaluation_failed',
                [
                    'encounter_id' => $encounterId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );
        } catch (\Throwable) {
            // Same guarantee as above — never let logging itself break the caller.
        }
    }
}
