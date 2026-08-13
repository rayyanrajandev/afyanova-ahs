<?php

namespace App\Modules\Encounter\Presentation\Http\Transformers;

class EncounterCloseReadinessResponseTransformer
{
    /**
     * @param  array<string, mixed>|null  $readiness
     * @return array<string, mixed>|null
     */
    public static function transform(?array $readiness): ?array
    {
        if ($readiness === null) {
            return null;
        }

        $items = is_array($readiness['items'] ?? null) ? $readiness['items'] : [];
        $billingSummary = is_array($readiness['billingSummary'] ?? null)
            ? $readiness['billingSummary']
            : [];

        return [
            'canClose' => (bool) ($readiness['canClose'] ?? false),
            'requiresAcknowledgement' => (bool) ($readiness['requiresAcknowledgement'] ?? false),
            'blockingCount' => (int) ($readiness['blockingCount'] ?? 0),
            'warningCount' => (int) ($readiness['warningCount'] ?? 0),
            'items' => array_map(
                static fn (array $item): array => [
                    'id' => $item['id'] ?? null,
                    'label' => $item['label'] ?? null,
                    'severity' => $item['severity'] ?? null,
                    'status' => $item['status'] ?? null,
                    'message' => $item['message'] ?? null,
                    'count' => array_key_exists('count', $item) ? $item['count'] : null,
                    'details' => is_array($item['details'] ?? null) ? $item['details'] : [],
                ],
                $items,
            ),
            'billingSummary' => [
                'pendingCandidates' => (int) ($billingSummary['pendingCandidates'] ?? 0),
                'alreadyInvoiced' => (int) ($billingSummary['alreadyInvoiced'] ?? 0),
                'totalCandidates' => (int) ($billingSummary['totalCandidates'] ?? 0),
                'currencyCode' => $billingSummary['currencyCode'] ?? null,
            ],
        ];
    }
}
