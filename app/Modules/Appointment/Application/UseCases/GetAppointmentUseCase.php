<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;

class GetAppointmentUseCase
{
    public function __construct(private readonly AppointmentRepositoryInterface $appointmentRepository) {}

    public function execute(string $id): ?array
    {
        return $this->appointmentRepository->findById($id);
    }
}
