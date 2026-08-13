<?php

namespace App\Support\ClinicalOrders;

use App\Models\ClinicalOrderSession;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderSessionManager
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function ensureSession(string $module, ?string $requestedSessionId, array $context): array
    {
        $sessionId = trim((string) ($requestedSessionId ?? ''));

        if ($sessionId !== '') {
            $session = ClinicalOrderSession::query()->find($sessionId);
            if ($session !== null) {
                $this->assertCompatibleSession($session, $module, $context);

                return $session->toArray();
            }
        }

        $session = new ClinicalOrderSession();
        if ($sessionId !== '') {
            $session->id = $sessionId;
        }

        $session->fill([
            'session_number' => $this->generateSessionNumber(),
            'module' => $module,
            'tenant_id' => $context['tenant_id'] ?? null,
            'facility_id' => $context['facility_id'] ?? null,
            'patient_id' => $context['patient_id'] ?? null,
            'admission_id' => $context['admission_id'] ?? null,
            'appointment_id' => $context['appointment_id'] ?? null,
            'ordered_by_user_id' => $context['ordered_by_user_id'] ?? null,
            'submitted_at' => $context['submitted_at'] ?? now(),
            'item_count' => 0,
            'metadata' => $context['metadata'] ?? null,
        ]);
        $session->save();

        return $session->toArray();
    }

    public function incrementItemCount(string $sessionId): void
    {
        ClinicalOrderSession::query()
            ->whereKey($sessionId)
            ->increment('item_count');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertCompatibleSession(
        ClinicalOrderSession $session,
        string $module,
        array $context
    ): void {
        if ((string) $session->module !== $module) {
            throw ValidationException::withMessages([
                'orderSessionId' => ['Order session module does not match the submitted order.'],
            ]);
        }

        foreach (['patient_id', 'appointment_id', 'admission_id', 'tenant_id', 'facility_id'] as $field) {
            $existing = trim((string) ($session->{$field} ?? ''));
            $requested = trim((string) ($context[$field] ?? ''));

            if ($existing !== '' && $requested !== '' && $existing !== $requested) {
                throw ValidationException::withMessages([
                    'orderSessionId' => ['Order session context does not match the submitted order.'],
                ]);
            }
        }
    }

    private function generateSessionNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'ORD'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! ClinicalOrderSession::query()->where('session_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique clinical order session number.');
    }
}
