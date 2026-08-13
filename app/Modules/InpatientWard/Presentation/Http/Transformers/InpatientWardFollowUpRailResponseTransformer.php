<?php

namespace App\Modules\InpatientWard\Presentation\Http\Transformers;

class InpatientWardFollowUpRailResponseTransformer
{
    public static function transform(array $summary): array
    {
        return [
            'admissionId' => $summary['admissionId'] ?? null,
            'patientId' => $summary['patientId'] ?? null,
            'generatedAt' => $summary['generatedAt'] ?? null,
            'modules' => [
                'laboratory' => self::transformModule($summary['modules']['laboratory'] ?? []),
                'pharmacy' => self::transformModule($summary['modules']['pharmacy'] ?? []),
                'radiology' => self::transformModule($summary['modules']['radiology'] ?? []),
                'billing' => self::transformModule($summary['modules']['billing'] ?? []),
            ],
        ];
    }

    private static function transformModule(array $module): array
    {
        return [
            'followUpCount' => (int) ($module['follow_up_count'] ?? 0),
            'statusCounts' => $module['status_counts'] ?? [],
            'items' => array_map([self::class, 'transformItem'], $module['items'] ?? []),
        ];
    }

    private static function transformItem(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'number' => $item['number'] ?? null,
            'title' => $item['title'] ?? null,
            'status' => $item['status'] ?? null,
            'timestamp' => $item['timestamp'] ?? null,
            'detail' => $item['detail'] ?? null,
        ];
    }
}
