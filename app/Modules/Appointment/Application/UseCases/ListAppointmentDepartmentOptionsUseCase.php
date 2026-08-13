<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;

class ListAppointmentDepartmentOptionsUseCase
{
    public function __construct(private readonly DepartmentRepositoryInterface $departmentRepository) {}

    /**
     * @return array<int, array{value:string,label:string,group?:string|null,description?:string|null,keywords?:array<int,string>}>
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