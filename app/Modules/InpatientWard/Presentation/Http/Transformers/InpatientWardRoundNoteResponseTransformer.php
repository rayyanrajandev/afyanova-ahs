<?php

namespace App\Modules\InpatientWard\Presentation\Http\Transformers;

class InpatientWardRoundNoteResponseTransformer
{
    public static function transform(array $note): array
    {
        return [
            'id' => $note['id'] ?? null,
            'admissionId' => $note['admission_id'] ?? null,
            'patientId' => $note['patient_id'] ?? null,
            'authorUserId' => $note['author_user_id'] ?? null,
            'roundedAt' => $note['rounded_at'] ?? null,
            'shiftLabel' => $note['shift_label'] ?? null,
            'roundNote' => $note['round_note'] ?? null,
            'carePlan' => $note['care_plan'] ?? null,
            'handoffNotes' => $note['handoff_notes'] ?? null,
            'acknowledgedByUserId' => $note['acknowledged_by_user_id'] ?? null,
            'acknowledgedAt' => $note['acknowledged_at'] ?? null,
            'metadata' => $note['metadata'] ?? null,
            'createdAt' => $note['created_at'] ?? null,
            'updatedAt' => $note['updated_at'] ?? null,
        ];
    }
}
