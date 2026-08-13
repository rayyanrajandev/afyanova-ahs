<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Transformers;

class ServiceRequestResponseTransformer
{
    /**
     * @param  array<string, mixed>  $serviceRequest
     * @return array<string, mixed>
     */
    public static function transform(array $serviceRequest): array
    {
        $department = self::departmentSummary(
            is_array($serviceRequest['department'] ?? null) ? $serviceRequest['department'] : null,
        );

        $items = [];
        if (isset($serviceRequest['items']) && is_array($serviceRequest['items'])) {
            $items = array_map(static function (array $item): array {
                return [
                    'id' => $item['id'] ?? null,
                    'catalogItemId' => $item['catalog_item_id'] ?? null,
                    'itemName' => $item['item_name'] ?? null,
                    'itemCode' => $item['item_code'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'status' => $item['status'] ?? 'pending',
                    'clinicalIndication' => $item['clinical_indication'] ?? null,
                    'instructions' => $item['instructions'] ?? null,
                    'requestedByUserId' => $item['requested_by_user_id'] ?? null,
                    'requestedAt' => isset($item['requested_at'])
                        ? (is_string($item['requested_at'])
                            ? $item['requested_at']
                            : optional($item['requested_at'])->toISOString())
                        : null,
                    'orderedAt' => isset($item['ordered_at'])
                        ? (is_string($item['ordered_at'])
                            ? $item['ordered_at']
                            : optional($item['ordered_at'])->toISOString())
                        : null,
                    'completedAt' => isset($item['completed_at'])
                        ? (is_string($item['completed_at'])
                            ? $item['completed_at']
                            : optional($item['completed_at'])->toISOString())
                        : null,
                    'failedAt' => isset($item['failed_at'])
                        ? (is_string($item['failed_at'])
                            ? $item['failed_at']
                            : optional($item['failed_at'])->toISOString())
                        : null,
                    'cancelledAt' => isset($item['cancelled_at'])
                        ? (is_string($item['cancelled_at'])
                            ? $item['cancelled_at']
                            : optional($item['cancelled_at'])->toISOString())
                        : null,
                    'failureReason' => $item['failure_reason'] ?? null,
                ];
            }, $serviceRequest['items']);
        }

        return [
            'id' => $serviceRequest['id'] ?? null,
            'requestNumber' => $serviceRequest['request_number'] ?? null,
            'patientId' => $serviceRequest['patient_id'] ?? null,
            'appointmentId' => $serviceRequest['appointment_id'] ?? null,
            'departmentId' => $serviceRequest['department_id'] ?? null,
            'department' => $department,
            'departmentLabel' => $department['label'] ?? null,
            'requestedByUserId' => $serviceRequest['requested_by_user_id'] ?? null,
            'serviceType' => $serviceRequest['service_type'] ?? null,
            'priority' => $serviceRequest['priority'] ?? null,
            'status' => $serviceRequest['status'] ?? null,
            'notes' => $serviceRequest['notes'] ?? null,
            'items' => $items,
            'requestedAt' => isset($serviceRequest['requested_at'])
                ? (is_string($serviceRequest['requested_at'])
                    ? $serviceRequest['requested_at']
                    : optional($serviceRequest['requested_at'])->toISOString())
                : null,
            'acknowledgedAt' => isset($serviceRequest['acknowledged_at'])
                ? (is_string($serviceRequest['acknowledged_at'])
                    ? $serviceRequest['acknowledged_at']
                    : optional($serviceRequest['acknowledged_at'])->toISOString())
                : null,
            'acknowledgedByUserId' => $serviceRequest['acknowledged_by_user_id'] ?? null,
            'encounterId' => $serviceRequest['encounter_id'] ?? null,
            'assessedByUserId' => $serviceRequest['assessed_by_user_id'] ?? null,
            'assessedAt' => isset($serviceRequest['assessed_at'])
                ? (is_string($serviceRequest['assessed_at'])
                    ? $serviceRequest['assessed_at']
                    : optional($serviceRequest['assessed_at'])->toISOString())
                : null,
            'completedAt' => isset($serviceRequest['completed_at'])
                ? (is_string($serviceRequest['completed_at'])
                    ? $serviceRequest['completed_at']
                    : optional($serviceRequest['completed_at'])->toISOString())
                : null,
            'statusReason' => $serviceRequest['status_reason'] ?? null,
            'linkedOrderType' => $serviceRequest['linked_order_type'] ?? null,
            'linkedOrderId' => $serviceRequest['linked_order_id'] ?? null,
            'linkedOrderNumber' => $serviceRequest['linked_order_number'] ?? null,
            'createdAt' => isset($serviceRequest['created_at'])
                ? (is_string($serviceRequest['created_at'])
                    ? $serviceRequest['created_at']
                    : optional($serviceRequest['created_at'])->toISOString())
                : null,
            'updatedAt' => isset($serviceRequest['updated_at'])
                ? (is_string($serviceRequest['updated_at'])
                    ? $serviceRequest['updated_at']
                    : optional($serviceRequest['updated_at'])->toISOString())
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $department
     * @return array{id: string|null, name: string|null, code: string|null, serviceType: string|null, label: string}|null
     */
    private static function departmentSummary(?array $department): ?array
    {
        if ($department === null) {
            return null;
        }

        $id = self::nullableTrimmed($department['id'] ?? null);
        $name = self::nullableTrimmed($department['name'] ?? null);
        $code = self::nullableTrimmed($department['code'] ?? null);
        $serviceType = self::nullableTrimmed($department['service_type'] ?? null);

        if ($id === null && $name === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'serviceType' => $serviceType,
            'label' => $code !== null && $name !== null ? sprintf('%s - %s', $code, $name) : ($name ?? $id ?? 'Department'),
        ];
    }

    private static function nullableTrimmed(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
