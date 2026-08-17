<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;

class ListAppointmentDepartmentOptionsUseCase
{
    public function __construct(private readonly DepartmentRepositoryInterface $departmentRepository) {}

    /**
     * @return array<int, array{id:string|null,value:string,label:string,group?:string|null,description?:string|null,keywords?:array<int,string>}>
     */
    public function execute(): array
    {
        return array_values(array_filter(array_map(function (array $department): ?array {
            $name = trim((string) ($department['name'] ?? ''));
            if ($name === '') {
                return null;
            }

            $code = trim((string) ($department['code'] ?? ''));
            $serviceType = trim((string) ($department['service_type'] ?? ''));
            $description = trim((string) ($department['description'] ?? ''));

            return [
                // The department's real id, added 2026-08-16 so callers can route
                // by relationship instead of by display name. `value` stays the
                // name for back-compat: the scheduling form still writes the
                // string column, and appointments.department is still read by
                // several modules.
                'id' => $department['id'] ?? null,
                'value' => $name,
                'label' => $code !== '' ? sprintf('%s - %s', $code, $name) : $name,
                'group' => $serviceType !== '' ? $serviceType : null,
                'description' => $description !== ''
                    ? $description
                    : 'Patient-facing department available for appointment scheduling.',
                'keywords' => array_values(array_filter([$code, $serviceType, 'patient-facing', 'appointmentable'])),
            ];
        }, $this->departmentRepository->listAppointmentableOptions())));
    }
}