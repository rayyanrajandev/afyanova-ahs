<?php

namespace App\Modules\Platform\Presentation\Http\Controllers\Concerns;

use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Application\Support\ClinicalCatalogBulkCsvSchema;
use App\Modules\Platform\Application\UseCases\BulkImportClinicalCatalogItemsUseCase;
use App\Modules\Platform\Application\UseCases\BulkUpdateClinicalCatalogItemStatusUseCase;
use App\Modules\Platform\Application\UseCases\ExportClinicalCatalogItemsCsvUseCase;
use App\Modules\Platform\Presentation\Http\Requests\BulkImportClinicalCatalogItemsRequest;
use App\Modules\Platform\Presentation\Http\Requests\BulkUpdateClinicalCatalogItemStatusRequest;
use App\Modules\Platform\Presentation\Http\Transformers\ClinicalCatalogItemResponseTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HandlesClinicalCatalogBulkOperations
{
    public function exportLabTestsCsv(Request $request, ExportClinicalCatalogItemsCsvUseCase $useCase): StreamedResponse
    {
        return $this->exportCatalogItemsCsv('lab_test', 'lab-tests', $request, $useCase);
    }

    public function labTestsImportTemplate(): StreamedResponse
    {
        return $this->importCatalogItemsTemplateCsv('lab_test', 'lab-tests');
    }

    public function bulkImportLabTests(
        BulkImportClinicalCatalogItemsRequest $request,
        BulkImportClinicalCatalogItemsUseCase $useCase
    ): JsonResponse {
        return $this->bulkImportCatalogItems('lab_test', $request, $useCase);
    }

    public function bulkUpdateLabTestStatus(
        BulkUpdateClinicalCatalogItemStatusRequest $request,
        BulkUpdateClinicalCatalogItemStatusUseCase $useCase
    ): JsonResponse {
        return $this->bulkUpdateCatalogItemStatus('lab_test', $request, $useCase);
    }

    public function exportRadiologyProceduresCsv(Request $request, ExportClinicalCatalogItemsCsvUseCase $useCase): StreamedResponse
    {
        return $this->exportCatalogItemsCsv('radiology_procedure', 'radiology-procedures', $request, $useCase);
    }

    public function radiologyProceduresImportTemplate(): StreamedResponse
    {
        return $this->importCatalogItemsTemplateCsv('radiology_procedure', 'radiology-procedures');
    }

    public function bulkImportRadiologyProcedures(
        BulkImportClinicalCatalogItemsRequest $request,
        BulkImportClinicalCatalogItemsUseCase $useCase
    ): JsonResponse {
        return $this->bulkImportCatalogItems('radiology_procedure', $request, $useCase);
    }

    public function bulkUpdateRadiologyProcedureStatus(
        BulkUpdateClinicalCatalogItemStatusRequest $request,
        BulkUpdateClinicalCatalogItemStatusUseCase $useCase
    ): JsonResponse {
        return $this->bulkUpdateCatalogItemStatus('radiology_procedure', $request, $useCase);
    }

    public function exportTheatreProceduresCsv(Request $request, ExportClinicalCatalogItemsCsvUseCase $useCase): StreamedResponse
    {
        return $this->exportCatalogItemsCsv('theatre_procedure', 'theatre-procedures', $request, $useCase);
    }

    public function theatreProceduresImportTemplate(): StreamedResponse
    {
        return $this->importCatalogItemsTemplateCsv('theatre_procedure', 'theatre-procedures');
    }

    public function bulkImportTheatreProcedures(
        BulkImportClinicalCatalogItemsRequest $request,
        BulkImportClinicalCatalogItemsUseCase $useCase
    ): JsonResponse {
        return $this->bulkImportCatalogItems('theatre_procedure', $request, $useCase);
    }

    public function bulkUpdateTheatreProcedureStatus(
        BulkUpdateClinicalCatalogItemStatusRequest $request,
        BulkUpdateClinicalCatalogItemStatusUseCase $useCase
    ): JsonResponse {
        return $this->bulkUpdateCatalogItemStatus('theatre_procedure', $request, $useCase);
    }

    public function exportClinicalProceduresCsv(Request $request, ExportClinicalCatalogItemsCsvUseCase $useCase): StreamedResponse
    {
        return $this->exportCatalogItemsCsv('clinical_procedure', 'clinical-procedures', $request, $useCase);
    }

    public function clinicalProceduresImportTemplate(): StreamedResponse
    {
        return $this->importCatalogItemsTemplateCsv('clinical_procedure', 'clinical-procedures');
    }

    public function bulkImportClinicalProcedures(
        BulkImportClinicalCatalogItemsRequest $request,
        BulkImportClinicalCatalogItemsUseCase $useCase
    ): JsonResponse {
        return $this->bulkImportCatalogItems('clinical_procedure', $request, $useCase);
    }

    public function bulkUpdateClinicalProcedureStatus(
        BulkUpdateClinicalCatalogItemStatusRequest $request,
        BulkUpdateClinicalCatalogItemStatusUseCase $useCase
    ): JsonResponse {
        return $this->bulkUpdateCatalogItemStatus('clinical_procedure', $request, $useCase);
    }

    public function exportFormularyItemsCsv(Request $request, ExportClinicalCatalogItemsCsvUseCase $useCase): StreamedResponse
    {
        return $this->exportCatalogItemsCsv('formulary_item', 'formulary-items', $request, $useCase);
    }

    public function formularyItemsImportTemplate(): StreamedResponse
    {
        return $this->importCatalogItemsTemplateCsv('formulary_item', 'formulary-items');
    }

    public function bulkImportFormularyItems(
        BulkImportClinicalCatalogItemsRequest $request,
        BulkImportClinicalCatalogItemsUseCase $useCase
    ): JsonResponse {
        return $this->bulkImportCatalogItems('formulary_item', $request, $useCase);
    }

    public function bulkUpdateFormularyItemStatus(
        BulkUpdateClinicalCatalogItemStatusRequest $request,
        BulkUpdateClinicalCatalogItemStatusUseCase $useCase
    ): JsonResponse {
        return $this->bulkUpdateCatalogItemStatus('formulary_item', $request, $useCase);
    }

    private function exportCatalogItemsCsv(
        string $catalogType,
        string $catalogSlug,
        Request $request,
        ExportClinicalCatalogItemsCsvUseCase $useCase,
    ): StreamedResponse {
        $export = $useCase->execute($catalogType, $request->all());
        $columns = $export['columns'];
        $rows = $export['rows'];

        return $this->streamCsvExport(
            baseName: sprintf('platform_clinical_catalog_%s_%s', str_replace('-', '_', $catalogSlug), now()->format('Ymd_His')),
            columns: $columns,
            writeRows: static function ($output) use ($columns, $rows): void {
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($columns as $column) {
                        $line[] = $row[$column] ?? '';
                    }
                    fputcsv($output, $line);
                }
            },
            schemaHeaderName: 'X-Clinical-Catalog-Csv-Schema',
            schemaVersion: ClinicalCatalogBulkCsvSchema::SCHEMA_VERSION,
            extraHeaders: [
                'X-Clinical-Catalog-Type' => $catalogType,
            ],
        );
    }

    private function importCatalogItemsTemplateCsv(string $catalogType, string $catalogSlug): StreamedResponse
    {
        $columns = ClinicalCatalogBulkCsvSchema::columnsForCatalogType($catalogType);
        $example = ClinicalCatalogBulkCsvSchema::exampleRowForCatalogType($catalogType);

        return $this->streamCsvExport(
            baseName: sprintf('platform_clinical_catalog_%s_import_template', str_replace('-', '_', $catalogSlug)),
            columns: $columns,
            writeRows: static function ($output) use ($example): void {
                fputcsv($output, $example);
            },
            schemaHeaderName: 'X-Clinical-Catalog-Csv-Schema',
            schemaVersion: ClinicalCatalogBulkCsvSchema::SCHEMA_VERSION,
            extraHeaders: [
                'X-Clinical-Catalog-Type' => $catalogType,
                'X-Clinical-Catalog-Template' => 'true',
            ],
        );
    }

    private function bulkImportCatalogItems(
        string $catalogType,
        BulkImportClinicalCatalogItemsRequest $request,
        BulkImportClinicalCatalogItemsUseCase $useCase,
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                catalogType: $catalogType,
                rows: $this->normalizedImportRows($request->input('rows', [])),
                mode: $request->string('mode')->value(),
                dryRun: $request->boolean('dryRun'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        return response()->json([
            'data' => $result,
        ]);
    }

    private function bulkUpdateCatalogItemStatus(
        string $catalogType,
        BulkUpdateClinicalCatalogItemStatusRequest $request,
        BulkUpdateClinicalCatalogItemStatusUseCase $useCase,
    ): JsonResponse {
        try {
            $result = $useCase->execute(
                catalogType: $catalogType,
                itemIds: $request->input('itemIds', []),
                status: $request->string('status')->value(),
                reason: $request->input('reason'),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        return response()->json([
            'data' => [
                'requestedCount' => $result['requested_count'],
                'updatedCount' => $result['updated_count'],
                'skippedItemIds' => $result['skipped_item_ids'],
                'failed' => $result['failed'],
                'items' => array_map(
                    [ClinicalCatalogItemResponseTransformer::class, 'transform'],
                    $result['items'] ?? [],
                ),
            ],
        ]);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizedImportRows(array $rows): array
    {
        $normalized = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowNumber = (int) ($row['rowNumber'] ?? ($index + 1));
            $values = is_array($row['values'] ?? null) ? $row['values'] : $row;
            unset($values['rowNumber'], $values['values']);

            $normalized[] = [
                'rowNumber' => $rowNumber,
                'values' => $values,
            ];
        }

        return $normalized;
    }
}
