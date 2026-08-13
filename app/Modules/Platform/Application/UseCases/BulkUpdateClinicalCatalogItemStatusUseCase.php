<?php

namespace App\Modules\Platform\Application\UseCases;

use Illuminate\Support\Facades\DB;

class BulkUpdateClinicalCatalogItemStatusUseCase
{
    public function __construct(
        private readonly UpdateClinicalCatalogItemStatusUseCase $updateClinicalCatalogItemStatusUseCase,
    ) {}

    /**
     * @param  array<int, string>  $itemIds
     * @return array<string, mixed>
     */
    public function execute(
        string $catalogType,
        array $itemIds,
        string $status,
        ?string $reason = null,
        ?int $actorId = null,
    ): array {
        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $itemIds,
        ), static fn (string $value): bool => $value !== '')));

        [$items, $skippedItemIds, $failed] = DB::transaction(function () use (
            $catalogType,
            $normalizedIds,
            $status,
            $reason,
            $actorId
        ): array {
            $items = [];
            $skippedItemIds = [];
            $failed = [];

            foreach ($normalizedIds as $itemId) {
                try {
                    $updated = $this->updateClinicalCatalogItemStatusUseCase->execute(
                        id: $itemId,
                        catalogType: $catalogType,
                        status: $status,
                        reason: $reason,
                        actorId: $actorId,
                    );
                } catch (\Throwable $exception) {
                    $failed[] = [
                        'itemId' => $itemId,
                        'message' => $exception->getMessage(),
                    ];

                    continue;
                }

                if ($updated === null) {
                    $skippedItemIds[] = $itemId;

                    continue;
                }

                $items[] = $updated;
            }

            return [$items, $skippedItemIds, $failed];
        });

        return [
            'requested_count' => count($normalizedIds),
            'updated_count' => count($items),
            'skipped_item_ids' => $skippedItemIds,
            'failed' => $failed,
            'items' => $items,
        ];
    }
}
