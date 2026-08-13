<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Support\ConsultationPricingResolver;
use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use DateTimeInterface;

class AutoCaptureConsultationFeeUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly CreateBillingInvoiceUseCase $createBillingInvoiceUseCase,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly BillingPayerContractRepositoryInterface $payerContractRepository,
        private readonly EncounterResolverService $encounterResolverService,
        private readonly ConsultationPricingResolver $consultationPricingResolver,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function execute(string $appointmentId, ?int $actorId = null): array
    {
        $appointment = $this->appointmentRepository->findById($appointmentId);
        if ($appointment === null) {
            return ['captured' => false, 'reason' => 'appointment_not_found', 'invoice' => null];
        }

        $patientId = (string) ($appointment['patient_id'] ?? '');
        if ($patientId === '') {
            return ['captured' => false, 'reason' => 'no_patient', 'invoice' => null];
        }

        $sourceKey = 'appointment_consultation:' . $appointmentId;
        $existingInvoice = $this->billingInvoiceRepository->findByLineItemSource(
            patientId: $patientId,
            sourceWorkflowKind: 'appointment_consultation',
            sourceWorkflowId: $appointmentId,
        );
        if ($existingInvoice !== null) {
            return ['captured' => false, 'reason' => 'already_captured', 'invoice' => null];
        }

        $clinicianUserId = (int) ($appointment['consultation_owner_user_id'] ?? $appointment['clinician_user_id'] ?? 0);
        $clinicianContext = $clinicianUserId > 0
            ? $this->resolveClinicianContext($clinicianUserId)
            : null;

        $department = trim((string) ($appointment['department'] ?? ''));
        $currencyCode = $this->defaultCurrencyResolver->resolve();
        $tier = $this->consultationClinicianTier($clinicianContext);

        // Distinct from "no price configured": we don't even know what tier
        // of clinician saw this patient (no clinician assigned, or one with
        // no staff profile / an unrecognized job title), so the mapping/price
        // lookup below is never attempted. Mirrors the same distinction
        // ListBillingChargeCaptureCandidatesUseCase::applyConsultationResolvedPrice()
        // makes for the pending-candidates list (missing_clinician_tier vs
        // missing_catalog_price).
        if ($tier === null) {
            return ['captured' => false, 'reason' => 'no_clinician_tier', 'invoice' => null];
        }

        $performedAt = $appointment['consultation_started_at']
            ?? $appointment['triaged_at']
            ?? $appointment['scheduled_at']
            ?? $appointment['updated_at'];

        $performedAtStringForResolver = $performedAt instanceof DateTimeInterface
            ? $performedAt->format(DateTimeInterface::ATOM)
            : (is_string($performedAt) ? $performedAt : null);

        // Explicit mapping lookup (tier + department -> chargeable item).
        // PricingEngine_Migration_Plan.md Phase 5: this is now the *only*
        // way a consultation auto-captures a price -- the old brittle
        // CONSULT-{tier}-{dept} string-generation fallback is gone.
        $mapping = null;
        if ($department !== '') {
            $mapping = ConsultationMappingModel::query()
                ->where('clinician_tier', $tier)
                ->where('department', $department)
                ->first();
        }

        $resolved = $mapping !== null
            ? $this->consultationPricingResolver->resolveViaExplicitMapping(
                mapping: $mapping,
                tier: $tier,
                department: $department,
                quantity: 1.0,
                performedAt: $performedAtStringForResolver,
                tenantId: $this->scopeContext->tenantId(),
                facilityId: $this->scopeContext->facilityId(),
                currencyCode: $currencyCode,
            )
            : null;

        // No resolvable price for this tier/department: leave the visit
        // uncaptured rather than invoicing at TZS 0. It still surfaces as a
        // pending candidate via ListBillingChargeCaptureCandidatesUseCase
        // (pricingStatus: missing_catalog_price) for manual pricing/capture,
        // instead of a phantom $0 invoice that looks resolved when it isn't.
        if ($resolved === null || $resolved['pricingStatus'] !== 'priced') {
            return ['captured' => false, 'reason' => 'no_catalog_price', 'invoice' => null];
        }

        $serviceName = $this->consultationServiceName($department, $clinicianContext);
        $unitPrice = $resolved['unitPrice'];
        $chargeableItem = ChargeableItemModel::query()->find($resolved['chargeableItemId']);
        $normalizedServiceCode = strtoupper(trim((string) ($chargeableItem->code ?? '')));
        $resolvedUnit = trim((string) ($chargeableItem->default_unit ?? 'visit'));
        $resolvedDescription = $serviceName;

        $performedAtString = $performedAtStringForResolver;

        $lineNotes = sprintf(
            'Charge capture from appointment_consultation %s%s.',
            $appointment['appointment_number'] ?? $appointmentId,
            $performedAtString !== null ? ' performed ' . $performedAtString : '',
        );

        $lineItem = [
            'description' => $resolvedDescription,
            'quantity' => 1,
            'unitPrice' => $unitPrice,
            'lineTotal' => $unitPrice,
            'serviceCode' => $normalizedServiceCode,
            'unit' => $resolvedUnit,
            'notes' => $lineNotes,
            'sourceWorkflowKind' => 'appointment_consultation',
            'sourceWorkflowId' => $appointmentId,
        ];

        // ── Step 1: Build the immutable BillingDecision BEFORE invoice creation ──
        $billingDecision = $this->resolveBillingDecision(
            contractId: $appointment['billing_payer_contract_id'] ?? null,
        );

        // ── Step 2: Payload — billing_decision is a TOP-LEVEL field ──
        $payload = [
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'invoice_date' => now()->toDateTimeString(),
            'line_items' => [$lineItem],
            'subtotal_amount' => $unitPrice,
            'currency_code' => $currencyCode,
            'issued_by_user_id' => $actorId,
            'billing_decision' => $billingDecision,
            'pricing_context' => null,
        ];

        // Set encounter_id when one already exists for this appointment, so
        // close-readiness and other consumers can rely on the real FK instead
        // of reconstructing the link from appointment_id inside line-item
        // metadata. Nullable/non-cascading on the invoice side — omitted
        // entirely (not persisted as null) when no encounter has been opened
        // yet, matching how the field behaves for every other consumer.
        $encounter = $this->encounterResolverService->findByAppointmentId($appointmentId);
        if ($encounter !== null) {
            $payload['encounter_id'] = (string) $encounter->id;
        }

        if ($billingDecision['mode'] === 'INSURANCE_ACTIVE') {
            $payload['billing_payer_contract_id'] = $billingDecision['contractId'];
        }

        // ── Step 3: Create invoice through the standard deterministic pipeline.
        // CreateBillingInvoiceUseCase routes to resolveFromBillingDecision() ──
        $invoice = $this->createBillingInvoiceUseCase->execute($payload, $actorId);

        return [
            'captured' => $invoice !== null,
            'reason' => $invoice !== null ? 'created' : 'creation_failed',
            'invoice' => $invoice,
        ];
    }

    private function resolveClinicianContext(int $userId): ?array
    {
        $staffProfileTable = (new StaffProfileModel)->getTable();
        $regulatoryProfileTable = (new \App\Modules\Staff\Infrastructure\Models\StaffRegulatoryProfileModel)->getTable();
        $specialtyTable = (new \App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyModel)->getTable();
        $specialtyAssignmentTable = (new \App\Modules\Staff\Infrastructure\Models\StaffProfileSpecialtyModel)->getTable();

        $row = StaffProfileModel::query()
            ->select([
                "{$staffProfileTable}.*",
                "{$regulatoryProfileTable}.id as regulatory_profile_id",
                "{$regulatoryProfileTable}.cadre_code",
                "{$regulatoryProfileTable}.professional_title",
                "{$regulatoryProfileTable}.practice_authority_level",
                "{$specialtyTable}.id as specialty_id",
                "{$specialtyTable}.name as specialty_name",
                "{$specialtyTable}.code as specialty_code",
            ])
            ->leftJoin($regulatoryProfileTable, "{$regulatoryProfileTable}.staff_profile_id", '=', "{$staffProfileTable}.id")
            ->leftJoin($specialtyAssignmentTable, "{$specialtyAssignmentTable}.staff_profile_id", '=', "{$staffProfileTable}.id")
            ->leftJoin($specialtyTable, "{$specialtyTable}.id", '=', "{$specialtyAssignmentTable}.specialty_id")
            ->where("{$staffProfileTable}.user_id", $userId)
            ->orderByDesc("{$specialtyAssignmentTable}.is_primary")
            ->orderBy("{$specialtyAssignmentTable}.created_at")
            ->first();

        if ($row === null) {
            return null;
        }

        $profileArray = $row->toArray();

        $regulatoryProfile = null;
        if (isset($profileArray['regulatory_profile_id']) && $profileArray['regulatory_profile_id'] !== null) {
            $regulatoryProfile = [
                'id' => $profileArray['regulatory_profile_id'],
                'staff_profile_id' => $row->id,
                'cadre_code' => $profileArray['cadre_code'] ?? null,
                'professional_title' => $profileArray['professional_title'] ?? null,
                'practice_authority_level' => $profileArray['practice_authority_level'] ?? null,
            ];
        }

        $specialty = null;
        if (isset($profileArray['specialty_id']) && $profileArray['specialty_id'] !== null) {
            $specialty = [
                'id' => $profileArray['specialty_id'],
                'name' => $profileArray['specialty_name'] ?? null,
                'code' => $profileArray['specialty_code'] ?? null,
            ];
        }

        return [
            'profile' => $profileArray,
            'regulatoryProfile' => $regulatoryProfile,
            'specialty' => $specialty,
        ];
    }

    private function consultationServiceName(string $department, ?array $clinicianContext): string
    {
        $tier = $this->consultationClinicianTier($clinicianContext);
        $specialtyName = trim((string) ($clinicianContext['specialty']['name'] ?? ''));
        $tierLabel = match ($tier) {
            'CO' => 'Clinical Officer',
            'AMO' => 'Assistant Medical Officer',
            'MD' => 'Medical Doctor',
            'SPECIALIST' => 'Specialist',
            default => null,
        };

        $serviceName = $tierLabel !== null ? sprintf('%s Consultation', $tierLabel) : 'Consultation';
        $scope = $specialtyName !== '' ? $specialtyName : $department;

        return trim($scope) !== '' ? sprintf('%s - %s', $serviceName, trim($scope)) : $serviceName;
    }

    private function consultationClinicianTier(?array $clinicianContext): ?string
    {
        if ($clinicianContext === null) {
            return null;
        }

        $haystack = strtoupper(trim(implode(' ', array_filter([
            $clinicianContext['profile']['job_title'] ?? null,
            $clinicianContext['profile']['license_type'] ?? null,
            $clinicianContext['regulatoryProfile']['cadre_code'] ?? null,
            $clinicianContext['regulatoryProfile']['professional_title'] ?? null,
            $clinicianContext['regulatoryProfile']['practice_authority_level'] ?? null,
        ], static fn (mixed $value): bool => trim((string) $value) !== ''))));

        $haystack = preg_replace('/[^A-Z0-9]+/', ' ', $haystack) ?: '';

        if ($this->containsAny($haystack, [
            'SPECIALIST', 'CONSULTANT', 'CARDIOLOG', 'DERMAT', 'GYNAE',
            'NEURO', 'OBSTET', 'ORTHOP', 'PAEDIATR', 'PEDIATR',
            'PSYCHIATR', 'RADIOLOG', 'SURGEON', 'UROLOG',
        ])) {
            return 'SPECIALIST';
        }

        if ($this->containsAny($haystack, ['ASSISTANT MEDICAL OFFICER', ' AMO '])) {
            return 'AMO';
        }

        if ($this->containsAny($haystack, ['CLINICAL OFFICER', ' CO '])) {
            return 'CO';
        }

        if ($this->containsAny($haystack, ['MEDICAL DOCTOR', 'MEDICAL OFFICER', 'PHYSICIAN', ' DOCTOR ', ' MD '])) {
            return 'MD';
        }

        return null;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        $paddedHaystack = sprintf(' %s ', trim($haystack));

        foreach ($needles as $needle) {
            if (str_contains($paddedHaystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the immutable BillingDecision DTO.
     *
     * This is the SINGLE SOURCE OF TRUTH for billing intent.
     * All contract validation happens HERE — not in the resolver.
     *
     * @param mixed $contractId
     * @return array<string, mixed>
     */
    private function resolveBillingDecision(mixed $contractId): array
    {
        $normalizedContractId = trim((string) $contractId);

        if ($normalizedContractId === '') {
            return [
                'mode' => 'TRUE_SELF_PAY',
                'contractId' => null,
                'contractState' => 'missing',
                'reason' => 'Appointment has no billing payer contract assigned.',
                'decidedAt' => now()->toISOString(),
                'decidedBy' => 'AutoCaptureConsultationFeeUseCase',
            ];
        }

        $payerContract = $this->payerContractRepository->findById($normalizedContractId);

        if (! is_array($payerContract)) {
            return [
                'mode' => 'INSURANCE_FALLBACK_SELF_PAY',
                'contractId' => $normalizedContractId,
                'contractState' => 'missing',
                'reason' => sprintf(
                    'Payer contract %s referenced by appointment was not found in current scope.',
                    $normalizedContractId,
                ),
                'decidedAt' => now()->toISOString(),
                'decidedBy' => 'AutoCaptureConsultationFeeUseCase',
            ];
        }

        $contractStatus = trim((string) ($payerContract['status'] ?? ''));

        if ($contractStatus !== 'active') {
            return [
                'mode' => 'INSURANCE_FALLBACK_SELF_PAY',
                'contractId' => $normalizedContractId,
                'contractState' => 'inactive',
                'reason' => sprintf(
                    'Payer contract %s status is "%s" — must be active for insurance billing.',
                    $normalizedContractId,
                    $contractStatus !== '' ? $contractStatus : 'unknown',
                ),
                'decidedAt' => now()->toISOString(),
                'decidedBy' => 'AutoCaptureConsultationFeeUseCase',
            ];
        }

        return [
            'mode' => 'INSURANCE_ACTIVE',
            'contractId' => $normalizedContractId,
            'contractState' => 'active',
            'reason' => sprintf(
                'Payer contract %s is active and will be used for insurance billing.',
                $normalizedContractId,
            ),
            'decidedAt' => now()->toISOString(),
            'decidedBy' => 'AutoCaptureConsultationFeeUseCase',
        ];
    }
}