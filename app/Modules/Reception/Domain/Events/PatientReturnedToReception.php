<?php

namespace App\Modules\Reception\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when Nursing returns a patient to Reception for administrative
 * verification (e.g. unverified insurance, wrong clinic, billing clearance).
 * Broadcasts in real-time on `patient-flow.{facilityId}` so Receptionists receive
 * an immediate high-priority alert Toast notification and live queue refresh.
 */
class PatientReturnedToReception implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $appointmentId,
        public readonly string $patientId,
        public readonly string $patientName,
        public readonly string $reason,
        public readonly ?string $facilityId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->facilityId === null) {
            return [];
        }

        return [new PrivateChannel('patient-flow.'.$this->facilityId)];
    }

    public function broadcastAs(): string
    {
        return 'patient.returned';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'appointmentId' => $this->appointmentId,
            'patientId' => $this->patientId,
            'patientName' => $this->patientName,
            'reason' => $this->reason,
            'returnedAt' => now()->toIso8601String(),
        ];
    }
}
