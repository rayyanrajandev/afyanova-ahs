<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventoryItemCodeException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreInventoryItemRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SplFileObject;

class ImportInventoryItemsUseCase
{
    public function __construct(
        private readonly CreateInventoryItemUseCase $createInventoryItemUseCase,
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
    ) {}

    /**
     * @param SplFileObject $csvFile
     * @param int|null $actorId
     * @return array<string, mixed>
     */
    public function execute(SplFileObject $csvFile, ?int $actorId = null): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
        ];

        $headers = [];
        $rowIndex = 0;

        while (! $csvFile->eof()) {
            $row = $csvFile->fgetcsv();

            if ($row === [null] || $row === false) {
                continue; // Skip empty lines
            }

            if ($rowIndex === 0) {
                $headers = array_map(static fn(string $h): string => trim($h), $row);
                $rowIndex++;
                continue;
            }

            $rowData = array_combine($headers, $row);
            if ($rowData === false) {
                $results['failed'][] = [
                    'row' => $rowIndex + 1,
                    'errors' => ['CSV row parsing failed. Invalid number of columns.'],
                ];
                $rowIndex++;
                continue;
            }

            $rowData = $this->normalizeCsvRow($rowData);
            $errors = [];

            // Validate the row using the rules from StoreInventoryItemRequest
            $request = new StoreInventoryItemRequest();
            $rules = $request->rules();

            // Custom handling for 'codes' field: combine codes_X into a 'codes' array
            $codes = [];
            foreach ($rowData as $key => $value) {
                if (str_starts_with($key, 'codes_')) {
                    $codeType = substr($key, 6); // Remove 'codes_'
                    if (! empty($value)) {
                        $codes[$codeType] = $value;
                    }
                    unset($rowData[$key]); // Remove individual code columns
                }
            }
            if (! empty($codes)) {
                $rowData['codes'] = $codes;
            }

            $validator = Validator::make($rowData, $rules);

            try {
                $validator->validate();
            } catch (ValidationException $e) {
                $errors = $e->errors();
            }

            // Add custom validation from StoreInventoryItemRequest::withValidator
            $request->merge($rowData); // Merge data into a dummy request for withValidator
            $validator = Validator::make($request->all(), $rules); // Re-create validator with merged data
            $request->withValidator($validator); // Apply custom validation
            try {
                $validator->validate();
            } catch (ValidationException $e) {
                $errors = array_merge_recursive($errors, $e->errors());
            }

            // Check for duplicate item code in the database
            if (empty($errors) && $this->inventoryItemRepository->existsByItemCode(strtoupper(trim($rowData['itemCode'])))) {
                $errors['itemCode'][] = 'Item code already exists.';
            }

            if (! empty($errors)) {
                $results['failed'][] = [
                    'row' => $rowIndex + 1,
                    'data' => $rowData, // Include data for debugging
                    'errors' => $errors,
                ];
            } else {
                try {
                    // Transform payload from camelCase to snake_case for the UseCase
                    $payload = $this->toItemPayload($rowData);
                    $item = $this->createInventoryItemUseCase->execute(
                        payload: $payload,
                        actorId: $actorId,
                    );
                    $results['successful'][] = $item; // Assuming use case returns created item
                } catch (DuplicateInventoryItemCodeException $e) {
                    $results['failed'][] = [
                        'row' => $rowIndex + 1,
                        'data' => $rowData,
                        'errors' => ['itemCode' => [$e->getMessage()]],
                    ];
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'row' => $rowIndex + 1,
                        'data' => $rowData,
                        'errors' => ['general' => [$e->getMessage()]],
                    ];
                }
            }

            $rowIndex++;
        }

        return $results;
    }

    /**
     * Normalize CSV row values based on expected types.
     * @param array<string, mixed> $rowData
     * @return array<string, mixed>
     */
    private function normalizeCsvRow(array $rowData): array
    {
        foreach ($rowData as $key => $value) {
            // Trim strings
            if (is_string($value)) {
                $rowData[$key] = trim($value);
            }

            // Convert 'true'/'false' or '1'/'0' to actual booleans for boolean fields
            if (in_array($key, ['requiresColdChain', 'isControlledSubstance'])) {
                if (is_string($value)) {
                    $lowerValue = strtolower($value);
                    if ($lowerValue === 'true' || $lowerValue === '1') {
                        $rowData[$key] = true;
                    } elseif ($lowerValue === 'false' || $lowerValue === '0') {
                        $rowData[$key] = false;
                    }
                }
            }

            // Convert numeric strings to numbers (float) for numeric fields
            if (in_array($key, ['conversionFactor', 'reorderLevel', 'maxStockLevel'])) {
                if (is_string($value) && is_numeric($value)) {
                    $rowData[$key] = (float) $value;
                }
            }
        }

        return $rowData;
    }

    /**
     * Converts camelCase keys to snake_case for the payload.
     * This logic is similar to the toItemPayload in InventoryProcurementController, but here it's generic.
     *
     * @param array<string, mixed> $validatedData
     * @return array<string, mixed>
     */
    private function toItemPayload(array $validatedData): array
    {
        $payload = [];
        foreach ($validatedData as $key => $value) {
            $snakeCaseKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            $payload[$snakeCaseKey] = $value;
        }
        return $payload;
    }
}
