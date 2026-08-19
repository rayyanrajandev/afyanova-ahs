<?php

namespace App\Providers;

use App\Modules\MedicalRecord\Application\Listeners\SendMedicalRecordHandoffEmail;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffInitiated;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Policies\AppointmentPolicy;
use App\Policies\LaboratoryOrderPolicy;
use App\Policies\MedicalRecordPolicy;
use App\Policies\PatientPolicy;
use App\Policies\PharmacyOrderPolicy;
use App\Policies\RadiologyOrderPolicy;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Services\RequestCurrentPlatformScopeContext;
use App\Modules\Platform\Infrastructure\Services\RequestScopedDefaultCurrencyResolver;
use App\Modules\Platform\Infrastructure\Services\RequestScopedFeatureFlagResolver;
use App\Support\Audit\InventoryAccessAuditLogger;
use App\Support\Audit\SodAlertNotifier;
use App\Support\Audit\WebhookChannel;
use App\Support\Auth\ConsultationProviderAuthorization;
use App\Support\Auth\DepartmentScopedPermissionResolver;
use App\Support\ApprovalWorkflow\ApprovalWorkflowEngine;
use App\Support\ApprovalWorkflow\SegregationOfDutiesValidator;
use App\Support\Branding\SystemBrandingManager;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindModuleContracts();

        $this->app->scoped(
            CurrentPlatformScopeContextInterface::class,
            RequestCurrentPlatformScopeContext::class,
        );

        $this->app->scoped(
            FeatureFlagResolverInterface::class,
            RequestScopedFeatureFlagResolver::class,
        );

        $this->app->scoped(
            DefaultCurrencyResolverInterface::class,
            RequestScopedDefaultCurrencyResolver::class,
        );

        // Phase 1: Inventory RBAC Services
        $this->app->singleton('inventory.permission_resolver', DepartmentScopedPermissionResolver::class);
        $this->app->singleton(InventoryAccessAuditLogger::class, InventoryAccessAuditLogger::class);

        // Phase 2: Approval Workflow Services
        $this->app->singleton(ApprovalWorkflowEngine::class, ApprovalWorkflowEngine::class);
        $this->app->singleton(SegregationOfDutiesValidator::class, SegregationOfDutiesValidator::class);
        $this->app->singleton('inventory.approval_engine', ApprovalWorkflowEngine::class);
        $this->app->singleton('inventory.sod_validator', SegregationOfDutiesValidator::class);

        // Phase 2: SOD Alerting
        $this->app->singleton(SodAlertNotifier::class, SodAlertNotifier::class);
    }

    public function boot(): void
    {
        $this->applyRuntimeBrandingConfig();

        // Register custom notification channels
        $this->app->make('config')->set('notifications.channels.webhook', WebhookChannel::class);

        // Policy registration
        Gate::policy(PatientModel::class, PatientPolicy::class);
        Gate::policy(MedicalRecordModel::class, MedicalRecordPolicy::class);
        Gate::policy(LaboratoryOrderModel::class, LaboratoryOrderPolicy::class);
        Gate::policy(PharmacyOrderModel::class, PharmacyOrderPolicy::class);
        Gate::policy(RadiologyOrderModel::class, RadiologyOrderPolicy::class);
        Gate::policy(AppointmentModel::class, AppointmentPolicy::class);

        Gate::before(function ($user, string $ability): ?bool {
            if (! method_exists($user, 'hasPermissionTo')) {
                return null;
            }

            $gate = app(GateContract::class);
            if (method_exists($gate, 'has') && $gate->has($ability)) {
                return null;
            }

            if ($user->hasPermissionTo($ability)) {
                return true;
            }

            return null;
        });

        // Workspace-level access gates
        Gate::define('reception.access', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) return true;
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('reception.access')) return true;
            $roles = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            return in_array('receptionist', $roles, true)
                || in_array('admin.registration', $roles, true)
                || in_array('registration_clerk', $roles, true)
                || (method_exists($user, 'hasPermissionTo') && (bool) $user->hasPermissionTo('appointment.check-in'));
        });

        Gate::define('clinician.access', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) return true;
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('clinician.access')) return true;
            $roles = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            return in_array('clinical_officer', $roles, true)
                || in_array('clinical.officer', $roles, true)
                || in_array('medical-officer', $roles, true)
                || in_array('clinical.physician', $roles, true)
                || in_array('doctor', $roles, true)
                || in_array('physician', $roles, true)
                || in_array('surgeon', $roles, true)
                || in_array('clinical.surgeon', $roles, true)
                || (method_exists($user, 'hasPermissionTo') && (bool) $user->hasPermissionTo('medication.prescribe'));
        });

        Gate::define('nursing.access', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) return true;
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('nursing.access')) return true;
            $roles = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            return in_array('nurse', $roles, true)
                || in_array('nurse-officer', $roles, true)
                || in_array('clinical.nurse', $roles, true)
                || in_array('nurse-midwife', $roles, true)
                || in_array('clinical.nurse.midwife', $roles, true)
                || in_array('medical_attendant', $roles, true)
                || (method_exists($user, 'hasPermissionTo') && (bool) $user->hasPermissionTo('inpatient.ward.create-task'));
        });

        Gate::define('laboratory.access', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) return true;
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('laboratory.access')) return true;
            $roles = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            return in_array('lab_technician', $roles, true)
                || in_array('lab-technologist', $roles, true)
                || in_array('lab.staff', $roles, true)
                || in_array('lab-supervisor', $roles, true)
                || in_array('lab.supervisor', $roles, true)
                || in_array('lab-manager', $roles, true)
                || in_array('lab.manager', $roles, true)
                || (method_exists($user, 'hasPermissionTo') && (bool) $user->hasPermissionTo('lab.result.enter'));
        });

        Gate::define('radiology.access', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) return true;
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('radiology.access')) return true;
            $roles = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            return in_array('radiographer', $roles, true)
                || in_array('radiology.staff', $roles, true)
                || in_array('radiographer-senior', $roles, true)
                || in_array('radiology.supervisor', $roles, true)
                || in_array('radiologist', $roles, true)
                || (method_exists($user, 'hasPermissionTo') && (bool) $user->hasPermissionTo('imaging.perform'));
        });

        Gate::define('pharmacy.access', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) return true;
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pharmacy.access')) return true;
            $roles = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            return in_array('dispenser', $roles, true)
                || in_array('pharmacy.staff', $roles, true)
                || in_array('pharmacist', $roles, true)
                || in_array('pharmacy.supervisor', $roles, true)
                || (method_exists($user, 'hasPermissionTo') && (bool) $user->hasPermissionTo('medication.dispense'));
        });

        Gate::define('cashier.access', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) return true;
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('cashier.access')) return true;
            $roles = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            return in_array('cashier', $roles, true)
                || in_array('finance.cashier', $roles, true)
                || in_array('accountant', $roles, true)
                || in_array('finance.officer', $roles, true)
                || in_array('finance.controller', $roles, true)
                || (method_exists($user, 'hasPermissionTo') && (bool) $user->hasPermissionTo('cashier.payments.record'));
        });

        Gate::define('inventory.access', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) return true;
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('inventory.access')) return true;
            $roles = method_exists($user, 'roleCodes') ? $user->roleCodes() : [];
            return in_array('inventory_clerk', $roles, true)
                || in_array('storekeeper', $roles, true)
                || (method_exists($user, 'hasPermissionTo') && (bool) $user->hasPermissionTo('inventory.manage'));
        });

        Gate::define('admin.access', function ($user): bool {
            return $this->isFacilitySuperAdmin($user);
        });

        /**
         * OPD triage — claiming a patient, recording the handoff, releasing the
         * claim.
         *
         * This resolved only from `emergency.triage.*`, which are held by
         * CLINICAL.EMERGENCY alone. So an ordinary nurse got a 403 on
         * `appointments/{id}/claim-triage`: outpatient triage was gated behind
         * Emergency Department permissions, `triage_owner_user_id` could never
         * be set through the normal path, and the "In Triage" badge and the
         * triage.claimed / triage.claim_released timeline entries were
         * unreachable for the role that actually does the work (2026-08-16).
         *
         * A directly-granted `appointments.record-triage` now satisfies it too,
         * so OPD triage is expressed by its own permission instead of borrowing
         * the ED's. Emergency staff keep resolving through the branch below —
         * this widens the gate, it does not narrow it.
         */
        Gate::define('appointments.record-triage', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) {
                return true;
            }

            return method_exists($user, 'hasPermissionTo')
                && (
                    (bool) $user->hasPermissionTo('appointments.record-triage')
                    || (bool) $user->hasPermissionTo('emergency.triage.create')
                    || (bool) $user->hasPermissionTo('emergency.triage.update')
                    || (bool) $user->hasPermissionTo('emergency.triage.update-status')
                );
        });

        Gate::define('admissions.create', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) {
                return true;
            }

            if (! method_exists($user, 'hasPermissionTo')) {
                return true;
            }

            return (bool) $user->hasPermissionTo('admissions.create')
                || (bool) $user->hasPermissionTo('inpatient.ward.create')
                || (bool) $user->hasPermissionTo('emergency.triage.create')
                || (bool) $user->hasPermissionTo('appointments.record-triage');
        });

        Gate::define('appointments.read-routing-options', function ($user): bool {
            if ($this->isFacilitySuperAdmin($user)) {
                return true;
            }

            return method_exists($user, 'hasPermissionTo')
                && (
                    (bool) $user->hasPermissionTo('appointments.create')
                    || (bool) $user->hasPermissionTo('appointment.reschedule')
                    || (bool) $user->hasPermissionTo('appointment.check-in')
                    || (bool) $user->hasPermissionTo('emergency.triage.create')
                    || (bool) $user->hasPermissionTo('emergency.triage.update')
                    || (bool) $user->hasPermissionTo('emergency.triage.update-status')
                );
        });

        Gate::define('appointments.start-consultation', function ($user): bool {
            return $this->allowsAppointmentProviderSession($user);
        });

        Gate::define('appointments.manage-provider-session', function ($user): bool {
            return $this->allowsAppointmentProviderSession($user);
        });

        Gate::define('medical.records.draft.update', function ($user, string $recordId): bool {
            if (! method_exists($user, 'hasPermissionTo')) {
                return false;
            }

            if ((bool) $user->hasPermissionTo('medical.records.update')) {
                return true;
            }

            if (
                ! (bool) $user->hasPermissionTo('medical.records.read')
                || ! (bool) $user->hasPermissionTo('medical.records.create')
            ) {
                return false;
            }

            $record = MedicalRecordModel::query()
                ->select(['id', 'tenant_id', 'facility_id', 'author_user_id', 'handed_off_to_user_id', 'handoff_status', 'status'])
                ->find($recordId);

            if ($record === null || $record->status !== MedicalRecordStatus::DRAFT->value) {
                return false;
            }

            // Author can always edit their own draft
            if ((int) $record->author_user_id === (int) $user->id) {
                /** @var CurrentPlatformScopeContextInterface $scopeContext */
                $scopeContext = app(CurrentPlatformScopeContextInterface::class);
                return $this->matchesScope($record, $scopeContext);
            }

            // Handoff recipient can edit after accepting
            if (
                $record->handoff_status === 'accepted'
                && (int) $record->handed_off_to_user_id === (int) $user->id
            ) {
                /** @var CurrentPlatformScopeContextInterface $scopeContext */
                $scopeContext = app(CurrentPlatformScopeContextInterface::class);
                return $this->matchesScope($record, $scopeContext);
            }

            return false;
        });

        Event::listen(
            MedicalRecordHandoffInitiated::class,
            [SendMedicalRecordHandoffEmail::class, 'handle'],
        );
    }

    private function allowsAppointmentProviderSession(mixed $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isFacilitySuperAdmin($user)) {
            return true;
        }

        /** @var ConsultationProviderAuthorization $authorization */
        $authorization = app(ConsultationProviderAuthorization::class);

        return $authorization->allows($user);
    }

    private function isFacilitySuperAdmin(mixed $user): bool
    {
        return $user !== null
            && method_exists($user, 'isFacilitySuperAdminAccess')
            && (bool) $user->isFacilitySuperAdminAccess();
    }

    private function applyRuntimeBrandingConfig(): void
    {
        /** @var SystemBrandingManager $brandingManager */
        $brandingManager = app(SystemBrandingManager::class);

        $replyToAddress = $brandingManager->mailReplyToAddress();

        config([
            'app.name' => $brandingManager->systemName(),
            'mail.from.address' => $brandingManager->mailFromAddress(),
            'mail.from.name' => $brandingManager->mailFromName(),
            'mail.reply_to' => $replyToAddress !== null
                ? [
                    'address' => $replyToAddress,
                    'name' => $brandingManager->mailFromName(),
                ]
                : null,
            'mail.markdown.theme' => SystemBrandingManager::MAIL_MARKDOWN_THEME,
        ]);
    }

    private function matchesScope(MedicalRecordModel $record, CurrentPlatformScopeContextInterface $scopeContext): bool
    {
        $tenantId = $scopeContext->tenantId();
        $facilityId = $scopeContext->facilityId();

        return ($tenantId === null || (string) $record->tenant_id === $tenantId)
            && ($facilityId === null || (string) $record->facility_id === $facilityId);
    }

    private function bindModuleContracts(): void
    {
        foreach (glob(app_path('Modules/*/Domain/Repositories/*Interface.php')) ?: [] as $file) {
            $this->bindModuleContract($file, [
                'App\\Modules\\%s\\Infrastructure\\Repositories\\Eloquent%s',
                'App\\Modules\\%s\\Infrastructure\\Repositories\\Database%s',
                'App\\Modules\\%s\\Infrastructure\\Repositories\\Config%s',
                'App\\Modules\\%s\\Infrastructure\\Repositories\\%s',
            ]);
        }

        foreach (glob(app_path('Modules/*/Domain/Services/*Interface.php')) ?: [] as $file) {
            $this->bindModuleContract($file, [
                'App\\Modules\\%s\\Infrastructure\\Services\\%s',
            ]);
        }
    }

    /**
     * @param array<int, string> $candidatePatterns
     */
    private function bindModuleContract(string $file, array $candidatePatterns): void
    {
        $normalizedFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        $normalizedAppPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, app_path());
        $relative = str_replace($normalizedAppPath.DIRECTORY_SEPARATOR, '', $normalizedFile);
        $segments = explode(DIRECTORY_SEPARATOR, $relative);

        if (count($segments) < 5 || $segments[0] !== 'Modules') {
            return;
        }

        $module = $segments[1];
        $contractName = pathinfo($file, PATHINFO_FILENAME);
        if (! str_ends_with($contractName, 'Interface')) {
            return;
        }

        $contract = 'App\\'.str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relative,
        );
        $base = substr($contractName, 0, -strlen('Interface'));

        foreach ($candidatePatterns as $candidatePattern) {
            $concrete = substr_count($candidatePattern, '%s') === 2
                ? sprintf($candidatePattern, $module, $base)
                : sprintf($candidatePattern, $module);
            if (! class_exists($concrete)) {
                continue;
            }

            $this->app->bind($contract, $concrete);
            return;
        }
    }
}


