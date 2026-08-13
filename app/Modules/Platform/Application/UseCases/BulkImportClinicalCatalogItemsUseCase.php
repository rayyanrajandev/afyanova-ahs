<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Application\Exceptions\DuplicateClinicalCatalogCodeException;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Application\Support\ClinicalCatalogBulkCsvSchema;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
class BulkImportClinicalCatalogItemsUseCase
{
    public function __construct(
        private readonly ClinicalCatalogItemRepositoryInterface $repository,
        private readonly CreateClinicalCatalogItemUseCase $createClinicalCatalogItemUseCase,
        private readonly UpdateClinicalCatalogItemUseCase $updateClinicalCatalogItemUseCase,
        private readonly UpdateClinicalCatalogItemStatusUseCase $updateClinicalCatalogItemStatusUseCase,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function execute(
        string $catalogType,
        array $rows,
        string $mode,
        bool $dryRun,
        ?int $actorId = null,
    ): array {
        $mode = $mode === 'upsert' ? 'upsert' : 'create';
        $departmentCodes = $this->departmentCodeMap();
        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $seenCodes = [];
        $results = [];
        $createdCount = 0;
        $updatedCount = 0;
        $failedCount = 0;
        $validationErrors = [];

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = (int) ($row['rowNumber'] ?? ($index + 1));
            $values = is_array($row['values'] ?? null) ? $row['values'] : (is_array($row) ? $row : []);
            $mapped = ClinicalCatalogBulkCsvSchema::rowToPersistencePayload($catalogType, $values, $rowNumber);

            if ($mapped['errors'] !== []) {
                $failedCount++;
                $validationErrors = array_merge($validationErrors, $mapped['errors']);
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => (string) ($values['code'] ?? ''),
                    'outcome' => 'failed',
                    'errors' => $this->errorsForRow($mapped['errors'], $rowNumber),
                ];

                continue;
            }

            $payload = $mapped['payload'];
            $code = (string) ($payload['code'] ?? '');
            $codeKey = strtolower($code);

            if (array_key_exists($codeKey, $seenCodes)) {
                $failedCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => $code,
                    'outcome' => 'failed',
                    'errors' => ['Duplicate code in import file.'],
                ];

                continue;
            }

            $seenCodes[$codeKey] = true;

            $departmentCode = strtoupper(trim((string) ($payload['department_code'] ?? '')));
            $departmentId = null;
            if ($departmentCode !== '') {
                $departmentId = $departmentCodes[$departmentCode] ?? null;
                if ($departmentId === null) {
                    $failedCount++;
                    $results[] = [
                        'rowNumber' => $rowNumber,
                        'code' => $code,
                        'outcome' => 'failed',
                        'errors' => [sprintf('Department code "%s" was not found.', $departmentCode)],
                    ];

                    continue;
                }
            }

            $existingId = $this->repository->findIdByCodeInScope($catalogType, $code, $tenantId, $facilityId);
            if ($mode === 'create' && $existingId !== null) {
                $failedCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => $code,
                    'outcome' => 'failed',
                    'errors' => ['Catalog code already exists in the current scope.'],
                ];

                continue;
            }

            if ($mode === 'upsert' && $existingId === null && $dryRun) {
                $createdCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => $code,
                    'outcome' => 'would_create',
                    'errors' => [],
                ];

                continue;
            }

            if ($mode === 'upsert' && $existingId !== null && $dryRun) {
                $updatedCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => $code,
                    'outcome' => 'would_update',
                    'errors' => [],
                ];

                continue;
            }

            if ($dryRun) {
                $createdCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => $code,
                    'outcome' => 'would_create',
                    'errors' => [],
                ];

                continue;
            }

            $storePayload = $this->toStorePayload($payload, $departmentId);
            $targetStatus = (string) ($payload['status'] ?? ClinicalCatalogItemStatus::ACTIVE->value);
            $statusReason = $payload['status_reason'] ?? null;

            try {
                if ($existingId === null) {
                    $item = $this->createClinicalCatalogItemUseCase->execute(
                        catalogType: $catalogType,
                        payload: $storePayload,
                        actorId: $actorId,
                    );
                    $itemId = (string) ($item['id'] ?? '');
                    if ($targetStatus !== ClinicalCatalogItemStatus::ACTIVE->value && $itemId !== '') {
                        $item = $this->updateClinicalCatalogItemStatusUseCase->execute(
                            id: $itemId,
                            catalogType: $catalogType,
                            status: $targetStatus,
                            reason: $statusReason,
                            actorId: $actorId,
                        ) ?? $item;
                    }
                    $createdCount++;
                    $results[] = [
                        'rowNumber' => $rowNumber,
                        'code' => $code,
                        'outcome' => 'created',
                        'itemId' => $itemId,
                        'errors' => [],
                    ];

                    continue;
                }

                $item = $this->updateClinicalCatalogItemUseCase->execute(
                    id: $existingId,
                    catalogType: $catalogType,
                    payload: $storePayload,
                    actorId: $actorId,
                );

                if ($item === null) {
                    $failedCount++;
                    $results[] = [
                        'rowNumber' => $rowNumber,
                        'code' => $code,
                        'outcome' => 'failed',
                        'errors' => ['Catalog item could not be updated.'],
                    ];

                    continue;
                }

                if (($item['status'] ?? null) !== $targetStatus) {
                    $item = $this->updateClinicalCatalogItemStatusUseCase->execute(
                        id: $existingId,
                        catalogType: $catalogType,
                        status: $targetStatus,
                        reason: $statusReason,
                        actorId: $actorId,
                    ) ?? $item;
                }

                $updatedCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => $code,
                    'outcome' => 'updated',
                    'itemId' => $existingId,
                    'errors' => [],
                ];
            } catch (TenantScopeRequiredForIsolationException $exception) {
                throw $exception;
            } catch (DuplicateClinicalCatalogCodeException $exception) {
                $failedCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => $code,
                    'outcome' => 'failed',
                    'errors' => [$exception->getMessage()],
                ];
            } catch (\Throwable $exception) {
                $failedCount++;
                $results[] = [
                    'rowNumber' => $rowNumber,
                    'code' => $code,
                    'outcome' => 'failed',
                    'errors' => [$exception->getMessage()],
                ];
            }
        }

        if (! $dryRun && $failedCount === 0 && ($createdCount > 0 || $updatedCount > 0)) {
            // Successful writes already committed per item; wrap nothing extra.
        }

        return [
            'dry_run' => $dryRun,
            'mode' => $mode,
            'requested_count' => count($rows),
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'failed_count' => $failedCount,
            'validation_errors' => $validationErrors,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toStorePayload(array $payload, ?string $departmentId): array
    {
        return [
            'code' => $payload['code'] ?? null,
            'name' => $payload['name'] ?? null,
            'facility_tier' => $payload['facility_tier'] ?? null,
            'department_id' => $departmentId,
            'category' => $payload['category'] ?? null,
            'unit' => $payload['unit'] ?? null,
            'billing_service_code' => $payload['billing_service_code'] ?? null,
            'description' => $payload['description'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'codes' => $payload['codes'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function departmentCodeMap(): array
    {
        return DepartmentModel::query()
            ->whereNotNull('code')
            ->get(['id', 'code'])
            ->mapWithKeys(static function (DepartmentModel $department): array {
                $code = strtoupper(trim((string) $department->code));

                return $code === '' ? [] : [$code => (string) $department->id];
            })
            ->all();
    }

    /**
     * @param  array<string, string>  $errors
     * @return array<int, string>
     */
    private function errorsForRow(array $errors, int $rowNumber): array
    {
        $messages = [];
        foreach ($errors as $key => $message) {
            if (str_contains($key, sprintf('rows.%d.', $rowNumber))) {
                $messages[] = $message;
            }
        }

        return $messages !== [] ? $messages : array_values($errors);
    }
}
