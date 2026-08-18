<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Records that studies completed before verification existed were released at
 * completion.
 *
 * Until `verified_at` was added, radiology had no release step: a study became
 * visible to the clinician the moment it was marked `completed`, and the
 * clinician workspace read it that way
 * (`!!item.verifiedAt || item.status === 'completed'` for imaging).
 *
 * Now that release is a real gate, those historical rows would read as "reported
 * but never released" — permanently unreadable on the chart, and permanently
 * counted as outstanding work by the clinician's "Send for Diagnostics" control.
 * Backfilling `verified_at = completed_at` states what actually happened under
 * the old rules rather than leaving every past study stuck behind a gate that
 * did not exist when it was reported.
 *
 * `verified_by_user_id` is deliberately left NULL: no person verified these, and
 * inventing an actor would be worse than an honest absence. The note records why.
 */
return new class extends Migration
{
    private const BACKFILL_NOTE = 'Released at completion under pre-verification workflow (backfilled).';

    public function up(): void
    {
        $boundary = now();

        DB::table('radiology_orders')
            ->where('status', 'completed')
            ->whereNull('verified_at')
            // Only rows that were already complete when this ran. Anything
            // completed afterwards must go through the real release step.
            ->where('completed_at', '<=', $boundary)
            ->update([
                'verified_at' => DB::raw('completed_at'),
                'verification_note' => self::BACKFILL_NOTE,
            ]);

        // Completed before completed_at was populated: fall back to the row's
        // own updated_at so the study is still readable rather than stranded.
        DB::table('radiology_orders')
            ->where('status', 'completed')
            ->whereNull('verified_at')
            ->whereNull('completed_at')
            ->where('updated_at', '<=', $boundary)
            ->update([
                'verified_at' => DB::raw('updated_at'),
                'verification_note' => self::BACKFILL_NOTE,
            ]);
    }

    public function down(): void
    {
        DB::table('radiology_orders')
            ->where('verification_note', self::BACKFILL_NOTE)
            ->update([
                'verified_at' => null,
                'verification_note' => null,
            ]);
    }
};
