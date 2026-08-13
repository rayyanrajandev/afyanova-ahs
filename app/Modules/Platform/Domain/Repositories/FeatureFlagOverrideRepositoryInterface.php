<?php

namespace App\Modules\Platform\Domain\Repositories;

interface FeatureFlagOverrideRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array;

    /**
     * @param  array<int, string>  $flagNames
     * @param  array<int, array{scope_type:string,scope_key:string}>  $scopes
     * @return array<int, array<string, mixed>>
     */
    public function listApplicable(array $flagNames, array $scopes): array;

    public function findById(string $id): ?array;

    public function findByIdentity(string $flagName, string $scopeType, string $scopeKey): ?array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function updateById(string $id, array $payload): ?array;

    public function deleteById(string $id): bool;
}
