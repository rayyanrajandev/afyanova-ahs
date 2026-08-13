<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Application\Services\ServiceRequestDepartmentScope;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportServiceRequestsCsvUseCase
{
    public function __construct(private readonly ListServiceRequestsUseCase $listServiceRequests) {}

    /**
     * CSV export is gated by its own service.requests.export permission and
     * is deliberately not department-scoped by B1 — always exports across
     * every department, matching the pre-existing export behavior.
     */
    public function execute(array $filters): StreamedResponse
    {
        $scope = new ServiceRequestDepartmentScope(canViewAllDepartments: true, departmentId: null);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="service-requests.csv"',
            'Cache-Control' => 'no-store',
        ];

        return new StreamedResponse(function () use ($filters): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'id',
                'request_number',
                'patient_id',
                'appointment_id',
                'department_id',
                'requested_by_user_id',
                'service_type',
                'priority',
                'status',
                'requested_at',
                'acknowledged_at',
                'acknowledged_by_user_id',
                'completed_at',
                'created_at',
            ]);

            $page = 1;
            do {
                $batch = $this->listServiceRequests->execute(array_merge($filters, [
                    'page' => $page,
                    'perPage' => 250,
                ]), $scope);
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $batch['data'];

                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row['id'] ?? '',
                        $row['request_number'] ?? '',
                        $row['patient_id'] ?? '',
                        $row['appointment_id'] ?? '',
                        $row['department_id'] ?? '',
                        $row['requested_by_user_id'] ?? '',
                        $row['service_type'] ?? '',
                        $row['priority'] ?? '',
                        $row['status'] ?? '',
                        $this->csvDate($row['requested_at'] ?? null),
                        $this->csvDate($row['acknowledged_at'] ?? null),
                        $row['acknowledged_by_user_id'] ?? '',
                        $this->csvDate($row['completed_at'] ?? null),
                        $this->csvDate($row['created_at'] ?? null),
                    ]);
                }

                $meta = $batch['meta'] ?? [];
                $lastPage = (int) ($meta['lastPage'] ?? 1);
                $page++;
            } while ($page <= $lastPage);

            fclose($handle);
        }, 200, $headers);
    }

    private function csvDate(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'toISOString')) {
            return (string) $value->toISOString();
        }

        return (string) $value;
    }
}
