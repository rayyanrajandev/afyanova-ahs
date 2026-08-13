<?php

namespace App\Support\ClinicalOrders;

use Illuminate\Validation\ValidationException;

class ClinicalOrderLifecycle
{
    public static function assertNoConflictingLinkage(string $replacesOrderId, string $addOnToOrderId): void
    {
        if ($replacesOrderId === '' || $addOnToOrderId === '') {
            return;
        }

        throw ValidationException::withMessages([
            'replacesOrderId' => ['Replacement and add-on linkage cannot be submitted together.'],
            'addOnToOrderId' => ['Replacement and add-on linkage cannot be submitted together.'],
        ]);
    }

    public static function throwImmutableSubmittedOrder(string $resourceLabel): never
    {
        throw ValidationException::withMessages([
            'order' => [sprintf(
                'Signed %s is immutable. Cancel, reorder, or add an add-on order instead.',
                $resourceLabel,
            )],
        ]);
    }

    /**
     * @param array<string, mixed> $order
     */
    public static function assertDraftEditable(array $order, string $resourceLabel): void
    {
        if (! self::isDraft($order)) {
            self::throwImmutableSubmittedOrder($resourceLabel);
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    public static function assertActiveForWorkflow(array $order, string $resourceLabel): void
    {
        if (! self::isDraft($order)) {
            return;
        }

        throw ValidationException::withMessages([
            'order' => [sprintf(
                'Draft %s must be signed before workflow actions can continue.',
                $resourceLabel,
            )],
        ]);
    }

    public static function normalizeEntryState(?string $entryState): string
    {
        $normalized = strtolower(trim((string) $entryState));

        return in_array($normalized, ClinicalOrderEntryState::values(), true)
            ? $normalized
            : ClinicalOrderEntryState::ACTIVE->value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function applyDraftEntryState(array &$payload): void
    {
        $payload['entry_state'] = ClinicalOrderEntryState::DRAFT->value;
        $payload['signed_at'] = null;
        $payload['signed_by_user_id'] = null;
        $payload['lifecycle_locked_at'] = null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function applyActiveEntryState(array &$payload, ?int $actorId = null): void
    {
        $signedAt = now();

        $payload['entry_state'] = ClinicalOrderEntryState::ACTIVE->value;
        $payload['signed_at'] = $signedAt;
        $payload['signed_by_user_id'] = $actorId;
        $payload['lifecycle_locked_at'] = $signedAt;
    }

    /**
     * @param array<string, mixed> $order
     */
    public static function isDraft(array $order): bool
    {
        $entryState = strtolower(trim((string) ($order['entry_state'] ?? '')));
        if ($entryState !== '') {
            return $entryState === ClinicalOrderEntryState::DRAFT->value;
        }

        return blank($order['lifecycle_locked_at'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $sourceOrder
     * @param array<string, mixed> $payload
     */
    public static function assertReplacementSource(
        ?array $sourceOrder,
        array $payload,
        string $field,
        string $resourceLabel,
    ): void {
        if ($sourceOrder === null) {
            throw ValidationException::withMessages([
                $field => [sprintf('The selected %s to replace could not be found.', $resourceLabel)],
            ]);
        }

        self::assertSamePatient($sourceOrder, $payload, $field, $resourceLabel);

        if (self::isEnteredInError($sourceOrder)) {
            throw ValidationException::withMessages([
                $field => [sprintf(
                    'The selected %s is already marked entered in error and cannot be used as a replacement source.',
                    $resourceLabel,
                )],
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $sourceOrder
     * @param array<string, mixed> $payload
     */
    public static function assertAddOnSource(
        ?array $sourceOrder,
        array $payload,
        string $field,
        string $resourceLabel,
    ): void {
        if ($sourceOrder === null) {
            throw ValidationException::withMessages([
                $field => [sprintf('The selected %s for add-on ordering could not be found.', $resourceLabel)],
            ]);
        }

        self::assertSamePatient($sourceOrder, $payload, $field, $resourceLabel);
        self::assertSameEncounter($sourceOrder, $payload, $field, $resourceLabel);

        if (self::isEnteredInError($sourceOrder)) {
            throw ValidationException::withMessages([
                $field => [sprintf(
                    'The selected %s is marked entered in error and cannot accept add-on ordering.',
                    $resourceLabel,
                )],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    public static function isEnteredInError(array $order): bool
    {
        $reasonCode = trim((string) ($order['lifecycle_reason_code'] ?? ''));
        $enteredInErrorAt = $order['entered_in_error_at'] ?? null;

        return $reasonCode === 'entered_in_error' || ! blank($enteredInErrorAt);
    }

    /**
     * @param array<string, mixed> $sourceOrder
     * @param array<string, mixed> $payload
     */
    private static function assertSamePatient(
        array $sourceOrder,
        array $payload,
        string $field,
        string $resourceLabel,
    ): void {
        $sourcePatientId = trim((string) ($sourceOrder['patient_id'] ?? ''));
        $requestedPatientId = trim((string) ($payload['patient_id'] ?? ''));

        if ($sourcePatientId === '' || $requestedPatientId === '' || $sourcePatientId !== $requestedPatientId) {
            throw ValidationException::withMessages([
                $field => [sprintf(
                    'The selected %s belongs to a different patient and cannot be linked here.',
                    $resourceLabel,
                )],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $sourceOrder
     * @param array<string, mixed> $payload
     */
    private static function assertSameEncounter(
        array $sourceOrder,
        array $payload,
        string $field,
        string $resourceLabel,
    ): void {
        $sourceAppointmentId = trim((string) ($sourceOrder['appointment_id'] ?? ''));
        $sourceAdmissionId = trim((string) ($sourceOrder['admission_id'] ?? ''));
        $requestedAppointmentId = trim((string) ($payload['appointment_id'] ?? ''));
        $requestedAdmissionId = trim((string) ($payload['admission_id'] ?? ''));

        $sourceHasEncounter = $sourceAppointmentId !== '' || $sourceAdmissionId !== '';
        $requestedHasEncounter = $requestedAppointmentId !== '' || $requestedAdmissionId !== '';

        if (! $sourceHasEncounter && ! $requestedHasEncounter) {
            return;
        }

        if (
            $sourceAppointmentId !== $requestedAppointmentId
            || $sourceAdmissionId !== $requestedAdmissionId
        ) {
            throw ValidationException::withMessages([
                $field => [sprintf(
                    'Add-on ordering for %s must stay within the same linked encounter.',
                    $resourceLabel,
                )],
            ]);
        }
    }
}
