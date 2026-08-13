<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pos\Application\Exceptions\PosOperationException;
use App\Modules\Pos\Domain\Repositories\PosRegisterRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterStatus;

class CreatePosRegisterUseCase
{
    public function __construct(
        private readonly PosRegisterRepositoryInterface $posRegisterRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $registerCode = $this->normalizeRegisterCode((string) $payload['register_code']);

        if ($this->posRegisterRepository->existsByRegisterCodeInScope($registerCode, $tenantId, $facilityId)) {
            throw new PosOperationException('Register code already exists for the current scope.', 'registerCode');
        }

        return $this->posRegisterRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'register_code' => $registerCode,
            'register_name' => trim((string) $payload['register_name']),
            'location' => $this->nullableTrimmedValue($payload['location'] ?? null),
            'default_currency_code' => $this->resolveCurrencyCode($payload['default_currency_code'] ?? null),
            'status' => PosRegisterStatus::ACTIVE->value,
            'status_reason' => null,
            'notes' => $this->nullableTrimmedValue($payload['notes'] ?? null),
            'created_by_user_id' => $actorId,
            'updated_by_user_id' => $actorId,
        ]);
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
