<?php

namespace App\Modules\InventoryProcurement\Application\Services;

use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\InventoryProcurement\Application\Services\DepartmentItemCatalogService;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class DepartmentRequisitionScopeResolver
{
    private const CROSS_DEPARTMENT_PERMISSIONS = [
        'inventory.procurement.manage-warehouses',
    ];

    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly DepartmentItemCatalogService $itemCatalogService,
    ) {}

    /**
     * @return array{
     *     canSelectAnyDepartment: bool,
     *     lockedDepartment: array{id:string,name:string,code:string|null}|null,
     *     staffDepartmentName: string|null,
     *     staffDepartmentId: string|null,
     *     preferredWarehouseId: string|null,
     *     hasExplicitItemCatalog: bool,
     *     departmentProfile: string|null
     * }
     */
    public function contextForUser(?User $user): array
    {
        $staffDepartmentId = $this->staffDepartmentId($user);
        $staffDepartmentName = $this->staffDepartmentName($user);
        $lockedDepartment = $staffDepartmentId
            ? $this->departmentByIdOrName($staffDepartmentId, null)
            : $this->departmentByIdOrName(null, $staffDepartmentName);

        $effectiveDepartmentId = $lockedDepartment['id'] ?? $staffDepartmentId;

        return [
            'canSelectAnyDepartment' => $this->canSelectAnyDepartment($user),
            'lockedDepartment' => $lockedDepartment,
            'staffDepartmentName' => $staffDepartmentName,
            'staffDepartmentId' => $staffDepartmentId,
            'preferredWarehouseId' => $this->itemCatalogService->preferredWarehouseId($effectiveDepartmentId),
            'hasExplicitItemCatalog' => $effectiveDepartmentId
                ? $this->itemCatalogService->hasExplicitCatalog($effectiveDepartmentId)
                : false,
            'departmentProfile' => $this->departmentProfile($effectiveDepartmentId),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id:string,name:string,code:string|null}
     */
    public function resolveForStorePayload(array $payload, ?User $user): array
    {
        if ($this->canSelectAnyDepartment($user)) {
            $department = $this->departmentByIdOrName(
                $this->nullableString($payload['requesting_department_id'] ?? null),
                $this->nullableString($payload['requesting_department'] ?? null),
            );

            if ($department === null) {
                throw ValidationException::withMessages([
                    'requestingDepartmentId' => 'Select an active requesting department from the department registry.',
                ]);
            }

            return $department;
        }

        $staffDepartmentName = $this->staffDepartmentName($user);
        $department = $this->departmentForStaffName($staffDepartmentName);

        if ($department === null) {
            throw ValidationException::withMessages([
                'requestingDepartment' => 'Your staff profile is not linked to an active department registry entry. Ask an administrator to update your staff profile department.',
            ]);
        }

        return $department;
    }

    public function assertItemIsRequestableForDepartment(string $itemId, ?string $departmentId): void
    {
        if ($departmentId === null || trim($departmentId) === '') {
            return;
        }

        // Check explicit catalog first — takes priority over category heuristic
        $catalogIds = $this->itemCatalogService->catalogItemIdsForDepartment($departmentId);
        if ($catalogIds !== null) {
            $isAllowed = in_array($itemId, $catalogIds, true);
            if (! $isAllowed) {
                throw ValidationException::withMessages([
                    'lines' => 'One or more requested items are not in the department item catalog.',
                ]);
            }

            return;
        }

        // Fallback: category-based heuristic
        $allowedCategories = $this->allowedCategoriesForDepartmentId($departmentId);
        if ($allowedCategories === null) {
            return;
        }

        $itemQuery = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel::query()
            ->where('id', $itemId)
            ->whereIn('category', $allowedCategories);
        // Without this, a requisition could validate against another
        // tenant/facility's catalog item id once scoping is enabled.
        $this->applyDepartmentScopeIfEnabled($itemQuery);
        $isAllowed = $itemQuery->exists();

        if (! $isAllowed) {
            throw ValidationException::withMessages([
                'lines' => 'One or more requested items are not requestable by the selected department.',
            ]);
        }
    }

    public function applyItemScope(Builder $query, ?string $departmentId): void
    {
        if ($departmentId === null || trim($departmentId) === '') {
            return;
        }

        // Check explicit catalog first — takes priority over category heuristic
        $catalogIds = $this->itemCatalogService->catalogItemIdsForDepartment($departmentId);
        if ($catalogIds !== null) {
            $query->whereIn('inventory_items.id', $catalogIds);

            return;
        }

        // Fallback: category-based heuristic
        $allowedCategories = $this->allowedCategoriesForDepartmentId($departmentId);
        if ($allowedCategories === null) {
            return;
        }

        $query->whereIn('category', $allowedCategories);
    }

    private function profileForDepartmentModel(DepartmentModel $department): string
    {
        return strtolower(trim(implode(' ', array_filter([
            (string) $department->code,
            (string) $department->name,
            (string) $department->service_type,
        ]))));
    }

    private function departmentProfileFromString(string $profile): ?string
    {
        if ($this->containsAny($profile, ['store', 'stores', 'procurement', 'supply', 'msd', 'warehouse'])) {
            return 'store';
        }

        if ($this->containsAny($profile, ['laboratory', 'lab', 'pathology'])) {
            return 'laboratory';
        }

        if ($this->containsAny($profile, ['pharmacy', 'dispensary'])) {
            return 'pharmacy';
        }

        if ($this->containsAny($profile, ['radiology', 'imaging', 'x-ray', 'xray', 'ultrasound'])) {
            return 'radiology';
        }

        if ($this->containsAny($profile, ['theatre', 'surgery', 'surgical', 'operating'])) {
            return 'theatre';
        }

        if ($this->containsAny($profile, ['dental'])) {
            return 'dental';
        }

        if ($this->containsAny($profile, ['kitchen', 'nutrition', 'food'])) {
            return 'kitchen';
        }

        if ($this->containsAny($profile, ['cleaning', 'housekeeping', 'sanitation'])) {
            return 'cleaning';
        }

        if ($this->containsAny($profile, ['billing', 'finance', 'records', 'front desk', 'admin'])) {
            return 'admin';
        }

        return 'general';
    }

    public function departmentProfile(?string $departmentId): ?string
    {
        if ($departmentId === null || trim($departmentId) === '') {
            return null;
        }

        $query = DepartmentModel::query()->where('status', 'active');
        $this->applyDepartmentScopeIfEnabled($query);

        $department = $query->find($departmentId);

        if ($department === null) {
            return null;
        }

        return $this->departmentProfileFromString($this->profileForDepartmentModel($department));
    }

    /**
     * @return array<int, string>|null Null means unrestricted store/procurement scope.
     */
    public function allowedCategoriesForDepartmentId(?string $departmentId): ?array
    {
        $query = DepartmentModel::query()->where('status', 'active');
        $this->applyDepartmentScopeIfEnabled($query);

        $department = $departmentId
            ? $query->find($departmentId)
            : null;

        if ($department === null) {
            // No department context — restrict to general clinical categories
            // so clinical roles (Nurse, Emergency) see only clinically relevant
            // items rather than unrestricted access (null = all categories).
            return [
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
                InventoryItemCategory::PPE->value,
                InventoryItemCategory::LINEN_TEXTILE->value,
                InventoryItemCategory::PHARMACEUTICAL->value,
                InventoryItemCategory::MEDICAL_EQUIPMENT->value,
            ];
        }

        $profile = $this->profileForDepartmentModel($department);

        if ($this->containsAny($profile, ['store', 'stores', 'procurement', 'supply', 'msd', 'warehouse'])) {
            return null;
        }

        if ($this->containsAny($profile, ['laboratory', 'lab', 'pathology'])) {
            return [
                InventoryItemCategory::LABORATORY->value,
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
                InventoryItemCategory::PPE->value,
            ];
        }

        if ($this->containsAny($profile, ['pharmacy', 'dispensary'])) {
            return [
                InventoryItemCategory::PHARMACEUTICAL->value,
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
                InventoryItemCategory::PPE->value,
            ];
        }

        if ($this->containsAny($profile, ['radiology', 'imaging', 'x-ray', 'xray', 'ultrasound'])) {
            return [
                InventoryItemCategory::RADIOLOGY->value,
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
                InventoryItemCategory::PPE->value,
            ];
        }

        if ($this->containsAny($profile, ['theatre', 'surgery', 'surgical', 'operating'])) {
            return [
                InventoryItemCategory::SURGICAL_INSTRUMENT->value,
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
                InventoryItemCategory::PPE->value,
                InventoryItemCategory::PHARMACEUTICAL->value,
            ];
        }

        if ($this->containsAny($profile, ['dental'])) {
            return [
                InventoryItemCategory::DENTAL->value,
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
                InventoryItemCategory::PPE->value,
            ];
        }

        if ($this->containsAny($profile, ['kitchen', 'nutrition', 'food'])) {
            return [
                InventoryItemCategory::FOOD_NUTRITION->value,
                InventoryItemCategory::CLEANING_SANITATION->value,
            ];
        }

        if ($this->containsAny($profile, ['cleaning', 'housekeeping', 'sanitation'])) {
            return [
                InventoryItemCategory::CLEANING_SANITATION->value,
                InventoryItemCategory::PPE->value,
            ];
        }

        if ($this->containsAny($profile, ['billing', 'finance', 'records', 'front desk', 'admin'])) {
            return [InventoryItemCategory::OFFICE_ADMIN->value];
        }

        return [
            InventoryItemCategory::MEDICAL_CONSUMABLE->value,
            InventoryItemCategory::PPE->value,
            InventoryItemCategory::LINEN_TEXTILE->value,
            InventoryItemCategory::PHARMACEUTICAL->value,
            InventoryItemCategory::MEDICAL_EQUIPMENT->value,
        ];
    }

    public function canSelectAnyDepartment(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isFacilitySuperAdminAccess()) {
            return true;
        }

        foreach (self::CROSS_DEPARTMENT_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    private function staffDepartmentName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $department = StaffProfileModel::query()
            ->where('user_id', (int) $user->id)
            ->where('status', 'active')
            ->value('department');

        if ($department !== null) {
            return $this->nullableString($department);
        }

        // Fallback: derive from the user's first role's department
        return $this->roleDepartmentName($user);
    }

    private function staffDepartmentId(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $departmentId = StaffProfileModel::query()
            ->where('user_id', (int) $user->id)
            ->where('status', 'active')
            ->value('department_id');

        if ($departmentId !== null) {
            return $this->nullableString($departmentId);
        }

        // Fallback: derive from the user's first role's department
        return $this->roleDepartmentId($user);
    }

    private function roleDepartmentId(?User $user): ?string
    {
        /*
         * When the user has no staff profile, derive the department from their
         * first inventory-scoped role.  This covers the common case where a
         * role like LAB.SUPERVISOR is assigned directly in the admin panel
         * without a corresponding staff-profile record.
         */
        $role = $user->roles()
            ->whereNotNull('department_id')
            ->where('status', 'active')
            ->first();

        return $role?->department_id;
    }

    private function roleDepartmentName(?User $user): ?string
    {
        $role = $user->roles()
            ->whereNotNull('department_id')
            ->where('status', 'active')
            ->first();

        if ($role === null) {
            return null;
        }

        return DepartmentModel::where('id', $role->department_id)->value('name');
    }

    /**
     * @return array{id:string,name:string,code:string|null}|null
     */
    private function departmentForStaffName(?string $departmentName): ?array
    {
        return $this->departmentByIdOrName(null, $departmentName);
    }

    /**
     * @return array{id:string,name:string,code:string|null}|null
     */
    private function departmentByIdOrName(?string $departmentId, ?string $departmentName): ?array
    {
        $query = DepartmentModel::query()->where('status', 'active');
        $this->applyDepartmentScopeIfEnabled($query);

        if ($departmentId !== null) {
            $department = (clone $query)->where('id', $departmentId)->first();
            if ($department !== null) {
                return $this->toDepartmentPayload($department);
            }
        }

        if ($departmentName === null) {
            return null;
        }

        $normalized = mb_strtolower($departmentName);
        $department = $query
            ->where(function (Builder $builder) use ($normalized): void {
                $builder
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(code)) = ?', [$normalized]);
            })
            ->first();

        if ($department !== null) {
            return $this->toDepartmentPayload($department);
        }

        return null;
    }

    /**
     * @return array{id:string,name:string,code:string|null}
     */
    private function toDepartmentPayload(DepartmentModel $department): array
    {
        return [
            'id' => (string) $department->id,
            'name' => (string) $department->name,
            'code' => $this->nullableString($department->code),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function applyDepartmentScopeIfEnabled(Builder $query): void
    {
        if (! $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            && ! $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation')) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }
}
