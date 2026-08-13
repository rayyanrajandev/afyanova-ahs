<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Application\Support\PatientCsvSchema;

class ExportPatientsCsvUseCase
{
    private const PAGE_SIZE = 250;

    public function __construct(private readonly ListPatientsUseCase $listPatientsUseCase) {}

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: array<int, string>, rows: array<int, array<string, string>>}
     */
    public function execute(array $filters): array
    {
        $rows = [];
        $page = 1;

        do {
            $result = $this->listPatientsUseCase->execute(array_merge($filters, [
                'page' => $page,
                'perPage' => self::PAGE_SIZE,
            ]));

            foreach ($result['data'] as $patient) {
                $rows[] = PatientCsvSchema::toCsvRow($patient);
            }

            $lastPage = $result['meta']['lastPage'] ?? $page;
            $page++;
        } while ($page <= $lastPage);

        return [
            'columns' => PatientCsvSchema::COLUMNS,
            'rows' => $rows,
        ];
    }
}
