<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Domain\Repositories\PosRegisterRepositoryInterface;
use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterStatus;

class UpdatePosRegisterUseCase
{
    public function __construct(
        private readonly PosRegisterRepositoryInterface $posRegisterRepository,
        private readonly PosRegisterSessionRepositoryInterface $posRegisterSessionRepository,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->posRegisterRepository->findById($id);
        if ($existing === null) {
            return null;
        }

        $registerCode = array_key_exists('register_code', $payload)
            ? $this->normalizeRegisterCode((string) $payload['register_code'])
            : (string) ($existing['register_code'] ?? '');

        if (
            $registerCode !== ''
            && strcasecmp($registerCode, (string) ($existing['register_code'] ?? '')) !== 0
            && $this->posRegisterRepository->existsByRegisterCodeInScope(
                $registerCode,
                $existing['tenant_id'] ?? null,
                $existing['facility_id'] ?? null,
                $id,
            )
        ) {
            throw new PosOperationException('Register code already exists for the current scope.', 'registerCode');
        }

        $status = array_key_exists('status', $payload)
            ? strtolower(trim((string) $payload['status']))
            : (string) ($existing['status'] ?? PosRegisterStatus::ACTIVE->value);

        $statusReason = array_key_exists('status_reason', $payload)
            ? $this->nullableTrimmedValue($payload['status_reason'])
            : ($existing['status_reason'] ?? null);

        if ($status === PosRegisterStatus::INACTIVE->value && $statusReason === null) {
            throw new PosOperationException('Provide a reason before deactivating a register.', 'statusReason');
        }

        if (
            $status === PosRegisterStatus::INACTIVE->value
            && ($existing['status'] ?? null) !== PosRegisterStatus::INACTIVE->value
            && $this->posRegisterSessionRepository->findOpenByRegisterId($id) !== null
        ) {
            throw new PosOperationException(
                'Close the active register session before deactivating this register.',
                'status',
            );
        }

        $attributes = [
            'register_code' => $registerCode,
            'register_name' => array_key_exists('register_name', $payload)
                ? trim((string) $payload['register_name'])
                : ($existing['register_name'] ?? null),
            'location' => array_key_exists('location', $payload)
                ? $this->nullableTrimmedValue($payload['location'])
                : ($existing['location'] ?? null),
            'default_currency_code' => array_key_exists('default_currency_code', $payload)
                ? $this->resolveCurrencyCode($payload['default_currency_code'])
                : ($existing['default_currency_code'] ?? $this->defaultCurrencyResolver->resolve()),
            'status' => $status,
            'status_reason' => $statusReason,
            'notes' => array_key_exists('notes', $payload)
                ? $this->nullableTrimmedValue($payload['notes'])
                : ($existing['notes'] ?? null),
            'updated_by_user_id' => $actorId,
        ];

        return $this->posRegisterRepository->update($id, $attributes);
    }

    private function normalizeRegisterCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function resolveCurrencyCode(mixed $value): string
    {
        $currencyCode = strtoupper(trim((string) $value));

        return $currencyCode !== '' ? $currencyCode : $this->defaultCurrencyResolver->resolve();
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
