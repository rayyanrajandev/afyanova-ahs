<?php

namespace App\Modules\Reception\Domain\Repositories;

interface ArrivalEventRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestForAppointment(string $appointmentId): ?array;
}
