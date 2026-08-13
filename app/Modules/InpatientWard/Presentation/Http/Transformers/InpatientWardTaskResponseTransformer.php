<?php

namespace App\Modules\InpatientWard\Presentation\Http\Transformers;

class InpatientWardTaskResponseTransformer
{
    public static function transform(array $task): array
    {
        return [
            'id' => $task['id'] ?? null,
            'taskNumber' => $task['task_number'] ?? null,
            'admissionId' => $task['admission_id'] ?? null,
            'patientId' => $task['patient_id'] ?? null,
            'taskType' => $task['task_type'] ?? null,
            'title' => $task['title'] ?? null,
            'priority' => $task['priority'] ?? null,
            'status' => $task['status'] ?? null,
            'statusReason' => $task['status_reason'] ?? null,
            'assignedToUserId' => $task['assigned_to_user_id'] ?? null,
            'createdByUserId' => $task['created_by_user_id'] ?? null,
            'dueAt' => $task['due_at'] ?? null,
            'startedAt' => $task['started_at'] ?? null,
            'completedAt' => $task['completed_at'] ?? null,
            'escalatedAt' => $task['escalated_at'] ?? null,
            'notes' => $task['notes'] ?? null,
            'metadata' => $task['metadata'] ?? null,
            'createdAt' => $task['created_at'] ?? null,
            'updatedAt' => $task['updated_at'] ?? null,
        ];
    }
}
