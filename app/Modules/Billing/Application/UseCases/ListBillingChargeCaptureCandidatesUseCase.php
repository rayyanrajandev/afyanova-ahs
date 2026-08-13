<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Jobs\ShadowDiffChargeCaptureCandidateJob;
use App\Modules\Billing\Application\Support\ConsultationPricingResolver;
use App\Modules\Billing\Domain\Services\ChargeResolverInterface;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceLineItemSourceModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalSourceKind;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileSpecialtyModel;
use App\Modules\Staff\Infrastructure\Models\StaffRegulatoryProfileModel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

class ListBillingChargeCaptureCandidatesUseCase
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters): array
    {
        $patientId = trim((string) ($filters['patientId'] ?? ''));
        $encounterId = $this->normalizeNullableUuid($filters['encounterId'] ?? null);
        $appointmentId = $this->normalizeNullableUuid($filters['appointmentId'] ?? null);
        $admissionId = $this->normalizeNullableUuid($filters['admissionId'] ?? null);

        if ($encounterId !== null) {
            $encounter = EncounterModel::query()->find($encounterId);
            if ($encounter !== null) {
                if ($patientId === '') {
                    $patientId = trim((string) ($encounter->patient_id ?? ''));
                }

                $appointmentId = $this->normalizeNullableUuid($encounter->appointment_id) ?? $appointmentId;
                $admissionId = $this->normalizeNullableUuid($encounter->admission_id) ?? $admissionId;
            }
        }

        $currencyCode = strtoupper(trim((string) ($filters['currencyCode'] ?? '')));
        $currencyCode = $currencyCode !== '' ? $currencyCode : $this->defaultCurrencyResolver->resolve();
        $includeInvoiced = filter_var($filters['includeInvoiced'] ?? false, FILTER_VALIDATE_BOOL);
        $limit = max(min((int) ($filters['limit'] ?? 100), 200), 1);

        if ($patientId === '') {
            return [
                'data' => [],
                'meta' => $this->buildMeta([], $currencyCode, $includeInvoiced),
            ];
        }

        $invoicedSources = $this->invoicedSourceIndex($patientId);
        $candidates = array_merge(
            $this->consultationCandidates($patientId, $encounterId, $appointmentId, $admissionId, $currencyCode, $invoicedSources),
            $this->orderCandidates($patientId, $encounterId, $appointmentId, $admissionId, $currencyCode, $invoicedSources),
            $this->admissionBedDayCandidates($patientId, $admissionId, $currencyCode, $invoicedSources),
        );

        usort(
            $candidates,
            static fn (array $left, array $right): int => strcmp(
                (string) ($right['performedAt'] ?? ''),
                (string) ($left['performedAt'] ?? ''),
            ),
        );

        $visibleCandidates = $includeInvoiced
            ? $candidates
            : array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => ! (bool) ($candidate['alreadyInvoiced'] ?? false),
            ));

        $visibleCandidates = array_slice($visibleCandidates, 0, $limit);

        return [
            'data' => $visibleCandidates,
            'meta' => $this->buildMeta($candidates, $currencyCode, $includeInvoiced),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $invoicedSources
     * @return array<int, array<string, mixed>>
     */
    private function consultationCandidates(
        string $patientId,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $admissionId,
        string $currencyCode,
        array $invoicedSources,
    ): array {
        if ($admissionId !== null && $appointmentId === null) {
            return [];
        }

        $query = AppointmentModel::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', [
                'checked_in',
                'waiting_triage',
                'waiting_provider',
                'in_consultation',
                'completed',
            ]);

        if ($appointmentId !== null) {
            $query->where('id', $appointmentId);
        }

        $this->applyPlatformScopeIfEnabled($query);

        $appointments = $query
            ->orderByDesc('consultation_started_at')
            ->orderByDesc('triaged_at')
            ->orderByDesc('scheduled_at')
            ->limit(100)
            ->get();

        $clinicianContexts = $this->consultationClinicianContextIndex(
            $appointments
                ->map(fn (AppointmentModel $appointment): ?int => $this->consultationOwnerUserId($appointment))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        );

        return $appointments
            ->map(function (AppointmentModel $appointment) use ($clinicianContexts, $currencyCode, $invoicedSources): array {
                $department = trim((string) $appointment->department);
                $clinicianContext = $clinicianContexts[$this->consultationOwnerUserId($appointment) ?? 0] ?? null;
                $performedAt = $this->dateTimeString(
                    $appointment->consultation_started_at
                    ?? $appointment->triaged_at
                    ?? $appointment->scheduled_at
                    ?? $appointment->updated_at
                );

                $candidate = $this->buildCandidate(
                    sourceKind: 'appointment_consultation',
                    sourceId: (string) $appointment->id,
                    patientId: (string) $appointment->patient_id,
                    appointmentId: $this->normalizeNullableUuid($appointment->id),
                    admissionId: null,
                    sourceNumber: $appointment->appointment_number,
                    // Consultation no longer has a legacy string-match
                    // fallback (Phase 5 legacy removal, this domain first) --
                    // an empty code list means buildCandidate()'s legacy
                    // lookup is a guaranteed no-op here; applyConsultationResolvedPrice()
                    // below is now the *only* way a consultation gets priced.
                    serviceCode: [],
                    serviceName: $this->consultationServiceName($department, $clinicianContext),
                    serviceType: 'consultation',
                    sourceStatus: $appointment->status,
                    performedAt: $performedAt,
                    quantity: 1,
                    unit: 'visit',
                    currencyCode: $currencyCode,
                    invoicedSources: $invoicedSources,
                );

                return $this->applyConsultationResolvedPrice($candidate, $department, $clinicianContext, $performedAt, $currencyCode);
            })
            ->all();
    }

    /**
     * PricingEngine_Migration_Plan.md Phase 5, Consultation legacy removal
     * (first domain fully cut over). This is now the *only* way a
     * consultation candidate gets priced -- there is no string-match
     * fallback left (consultationCandidates() passes buildCandidate() an
     * empty service-code list). No mapping, or a mapping with no priced
     * chargeable item, means genuinely unpriced: 'missing_catalog_price'
     * ("needs pricing", a normal admin-actionable state), not
     * buildCandidate()'s default 'missing_service_code' ("data problem") --
     * that distinction matters for how ChargesTab.vue badges it. Likewise,
     * an unresolved clinician tier (no clinician assigned to the
     * consultation, or one with no staff profile / unrecognized job title)
     * is 'missing_clinician_tier', not 'missing_catalog_price' -- the price
     * book may be fully configured and correct; pricing was simply never
     * attempted because we don't know which tier to look up.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $clinicianContext
     * @return array<string, mixed>
     */
    private function applyConsultationResolvedPrice(
        array $candidate,
        string $department,
        ?array $clinicianContext,
        ?string $performedAt,
        string $currencyCode,
    ): array {
        $tier = $this->consultationClinicianTier($clinicianContext);
        if ($tier === null) {
            // Distinct from "no price configured": we don't even know what
            // tier of clinician saw this patient (no clinician assigned, or
            // one with no staff profile / an unrecognized job title), so
            // pricing was never attempted. Collapsing this into
            // 'missing_catalog_price' sends admins to check price_book_entries
            // for a gap that isn't there -- the real fix is assigning a
            // recognized clinician to the consultation.
            $candidate['pricingStatus'] = 'missing_clinician_tier';

            return $candidate;
        }

        if ($department === '') {
            $candidate['pricingStatus'] = 'missing_catalog_price';

            return $candidate;
        }

        $scopeContext = app(CurrentPlatformScopeContextInterface::class);
        $resolved = app(ConsultationPricingResolver::class)->resolveViaExplicitMapping(
            mapping: null,
            tier: $tier,
            department: $department,
            quantity: 1.0,
            performedAt: $performedAt,
            tenantId: $scopeContext->tenantId(),
            facilityId: $scopeContext->facilityId(),
            currencyCode: $currencyCode,
        );

        if ($resolved === null || $resolved['pricingStatus'] !== 'priced') {
            $candidate['pricingStatus'] = 'missing_catalog_price';

            return $candidate;
        }

        $candidate['unitPrice'] = $resolved['unitPrice'];
        $candidate['lineTotal'] = $resolved['lineTotal'];
        $candidate['pricingStatus'] = 'priced';
        $candidate['pricingSource'] = 'chargeable_item';
        $candidate['suggestedLineItem']['unitPrice'] = $resolved['unitPrice'];
        $candidate['suggestedLineItem']['lineTotal'] = $resolved['lineTotal'];

        return $candidate;
    }

    /**
     * @param  array<string, array<string, mixed>>  $invoicedSources
     * @return array<int, array<string, mixed>>
     */
    private function orderCandidates(
        string $patientId,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $admissionId,
        string $currencyCode,
        array $invoicedSources,
    ): array {
        $candidates = [];

        foreach (ClinicalSourceKind::orderKinds() as $kind) {
            $candidates = array_merge($candidates, $this->candidatesForKind(
                $kind, $patientId, $encounterId, $appointmentId, $admissionId, $currencyCode, $invoicedSources,
            ));
        }

        return $candidates;
    }

    /**
     * One candidate per elapsed calendar day of an admission's stay (any part of a
     * day counts as a full day, minimum 1 — the admission day is always chargeable).
     * Each day is its own source so it can be captured/invoiced independently as the
     * stay progresses, the same way a single lab/pharmacy order is one candidate.
     *
     * @param  array<string, array<string, mixed>>  $invoicedSources
     * @return array<int, array<string, mixed>>
     */
    private function admissionBedDayCandidates(
        string $patientId,
        ?string $admissionId,
        string $currencyCode,
        array $invoicedSources,
    ): array {
        $query = AdmissionModel::query()
            ->with('bedResource')
            ->where('patient_id', $patientId)
            ->whereIn('status', ['admitted', 'discharged', 'transferred']);

        if ($admissionId !== null) {
            $query->where('id', $admissionId);
        }

        $this->applyPlatformScopeIfEnabled($query);

        $admissions = $query->orderByDesc('admitted_at')->limit(50)->get();

        $candidates = [];

        foreach ($admissions as $admission) {
            if ($admission->admitted_at === null) {
                continue;
            }

            $candidates = array_merge($candidates, $this->bedDayCandidatesForAdmission(
                $admission, $currencyCode, $invoicedSources,
            ));
        }

        return $candidates;
    }

    /**
     * @param  array<string, array<string, mixed>>  $invoicedSources
     * @return array<int, array<string, mixed>>
     */
    private function bedDayCandidatesForAdmission(
        AdmissionModel $admission,
        string $currencyCode,
        array $invoicedSources,
    ): array {
        $admittedAt = $admission->admitted_at;
        $endpoint = $admission->discharged_at ?? now();

        $elapsedSeconds = max(0, $endpoint->getTimestamp() - $admittedAt->getTimestamp());
        $days = min(max((int) ceil($elapsedSeconds / 86400), 1), 60);

        $wardLabel = trim((string) ($admission->bedResource?->ward_name ?: $admission->ward));
        $wardToken = $this->serviceCodeToken($wardLabel);
        $serviceCodes = array_values(array_unique(array_filter([
            $wardToken !== '' ? sprintf('BED-%s', $wardToken) : null,
            'BED-DAY',
        ])));
        $serviceName = $wardLabel !== '' ? sprintf('Bed Charge - %s', $wardLabel) : 'Bed Charge';

        $chargeableItemId = $this->normalizeNullableUuid($admission->bedResource?->chargeable_item_id);
        $domainCutOver = $chargeableItemId !== null;

        $candidates = [];

        for ($dayIndex = 1; $dayIndex <= $days; $dayIndex++) {
            $performedAt = $this->dateTimeString($admittedAt->copy()->addDays($dayIndex - 1));

            $candidate = $this->buildCandidate(
                sourceKind: 'admission_bed_day',
                sourceId: sprintf('%s:%d', $admission->id, $dayIndex),
                patientId: (string) $admission->patient_id,
                appointmentId: null,
                admissionId: (string) $admission->id,
                sourceNumber: $admission->admission_number,
                serviceCode: $serviceCodes,
                serviceName: $serviceName,
                serviceType: 'bed_day',
                sourceStatus: $admission->status,
                performedAt: $performedAt,
                quantity: 1,
                unit: 'day',
                currencyCode: $currencyCode,
                invoicedSources: $invoicedSources,
            );

            if ($domainCutOver) {
                $candidate = $this->applyBedDayResolvedPrice($candidate, $chargeableItemId, $performedAt, $currencyCode);
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * PricingEngine_Migration_Plan.md Phase 5, Bed-day (last domain, legacy
     * pricing fully removed). Beds have no shadow-diff (never did -- same
     * reason Consultation was excluded from Phase 2). Once a bed/ward's
     * facility_resources.chargeable_item_id has been assigned by a facility
     * admin (via the ward/bed admin screen), that price is served
     * unconditionally. An unassigned bed has genuinely no price to show
     * (buildCandidate() above skips the deleted legacy lookup entirely) --
     * 'missing_catalog_price', a normal admin-actionable state, not a
     * silent string-match rescue.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function applyBedDayResolvedPrice(array $candidate, string $chargeableItemId, ?string $performedAt, string $currencyCode): array
    {
        $scopeContext = app(CurrentPlatformScopeContextInterface::class);
        $resolved = app(ChargeResolverInterface::class)->resolvePrice(
            chargeableItemId: $chargeableItemId,
            quantityOrDuration: 1.0,
            asOfDate: $performedAt,
            tenantId: $scopeContext->tenantId(),
            facilityId: $scopeContext->facilityId(),
            payerContractId: null,
            currencyCode: $currencyCode,
        );

        if ($resolved['pricingStatus'] !== 'priced') {
            return $candidate;
        }

        $candidate['unitPrice'] = $resolved['unitPrice'];
        $candidate['lineTotal'] = $resolved['lineTotal'];
        $candidate['pricingStatus'] = 'priced';
        $candidate['pricingSource'] = 'chargeable_item';
        $candidate['suggestedLineItem']['unitPrice'] = $resolved['unitPrice'];
        $candidate['suggestedLineItem']['lineTotal'] = $resolved['lineTotal'];

        return $candidate;
    }

    /**
     * @param  array<string, array<string, mixed>>  $invoicedSources
     * @return array<int, array<string, mixed>>
     */
    private function candidatesForKind(
        ClinicalSourceKind $kind,
        string $patientId,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $admissionId,
        string $currencyCode,
        array $invoicedSources,
    ): array {
        $modelClass = $kind->modelClass();
        $catalogFk = $kind->catalogFk();

        $query = $modelClass::query()
            ->where('patient_id', $patientId)
            ->whereNull('entered_in_error_at');

        match ($kind) {
            ClinicalSourceKind::LABORATORY_ORDER => $query->where(function (Builder $builder): void {
                $builder->where('status', 'completed')->orWhereNotNull('resulted_at');
            }),
            ClinicalSourceKind::RADIOLOGY_ORDER => $query->where(function (Builder $builder): void {
                $builder->where('status', 'completed')->orWhereNotNull('completed_at');
            }),
            ClinicalSourceKind::PHARMACY_ORDER => $query->where(function (Builder $builder): void {
                $builder->whereIn('status', ['dispensed', 'partially_dispensed'])->orWhere('quantity_dispensed', '>', 0);
            }),
            ClinicalSourceKind::CLINICAL_PROCEDURE_ORDER => $query->where(function (Builder $builder): void {
                $builder->where('status', 'completed')->orWhereNotNull('completed_at');
            }),
            ClinicalSourceKind::THEATRE_PROCEDURE => $query->where(function (Builder $builder): void {
                $builder->where('status', 'completed')->orWhereNotNull('completed_at');
            }),
        };

        $this->applyClinicalContextFilters($query, $encounterId, $appointmentId, $admissionId);
        $this->applyPlatformScopeIfEnabled($query);

        match ($kind) {
            ClinicalSourceKind::LABORATORY_ORDER => $query->orderByDesc('resulted_at')->orderByDesc('ordered_at'),
            ClinicalSourceKind::RADIOLOGY_ORDER => $query->orderByDesc('completed_at')->orderByDesc('ordered_at'),
            ClinicalSourceKind::PHARMACY_ORDER => $query->orderByDesc('dispensed_at')->orderByDesc('ordered_at'),
            ClinicalSourceKind::CLINICAL_PROCEDURE_ORDER => $query->orderByDesc('completed_at')->orderByDesc('ordered_at'),
            ClinicalSourceKind::THEATRE_PROCEDURE => $query->orderByDesc('completed_at')->orderByDesc('scheduled_at'),
        };

        $orders = $query->limit(100)->get();
        $catalogItems = $this->clinicalCatalogIndex($orders->pluck($catalogFk)->all());
        // PricingEngine_Migration_Plan.md Phase 5: every order-domain's
        // legacy pricing path is now removed, so this is always true. Kept
        // as an explicit read of isLegacyPricingRemoved() (rather than a
        // literal true) so a future new order-domain that starts
        // un-migrated is handled correctly without touching this method.
        $legacyRemoved = $kind->isLegacyPricingRemoved();
        $domainCutOver = $legacyRemoved;

        return $orders
            ->map(function (mixed $order) use ($kind, $catalogFk, $catalogItems, $currencyCode, $invoicedSources, $domainCutOver, $legacyRemoved): array {
                $catalogItem = $catalogItems[(string) $order->{$catalogFk}] ?? null;

                [$serviceCode, $serviceName, $serviceType, $performedAt, $quantity, $unit] = $this->extractCandidateFields($order, $kind, $catalogItem);

                $candidate = $this->buildCandidate(
                    sourceKind: $kind->value,
                    sourceId: (string) $order->id,
                    patientId: (string) $order->patient_id,
                    appointmentId: $this->normalizeNullableUuid($order->appointment_id),
                    admissionId: $this->normalizeNullableUuid($order->admission_id),
                    sourceNumber: $order->order_number ?? $order->procedure_number ?? null,
                    serviceCode: $serviceCode,
                    serviceName: $serviceName,
                    serviceType: $serviceType,
                    sourceStatus: $order->status,
                    performedAt: $performedAt,
                    quantity: $quantity,
                    unit: $unit,
                    currencyCode: $currencyCode,
                    invoicedSources: $invoicedSources,
                );

                $chargeableItemId = $this->normalizeNullableUuid($order->{$catalogFk});
                if ($chargeableItemId === null) {
                    return $candidate;
                }

                $scopeContext = app(CurrentPlatformScopeContextInterface::class);
                $tenantId = $scopeContext->tenantId();
                $facilityId = $scopeContext->facilityId();

                // Shadow-diffing exists to verify the new engine agrees with a
                // still-live legacy price -- once a domain's legacy path is
                // actually deleted (not just flag-gated), there is nothing
                // left to compare against, so logging one would just record
                // permanent false "mismatches" against a lookup that no
                // longer runs.
                if (! $legacyRemoved) {
                    $this->dispatchShadowDiff($candidate, $chargeableItemId, $tenantId, $facilityId);
                }

                if ($domainCutOver) {
                    $candidate = $this->applyResolvedPrice($candidate, $chargeableItemId, $tenantId, $facilityId);
                }

                return $candidate;
            })
            ->all();
    }

    /**
     * PricingEngine_Migration_Plan.md Phase 2/3. The order's existing catalog
     * FK column doubles as its chargeable_item_id -- Phase 1's backfill
     * intentionally reused clinical_catalog_item ids for chargeable_items,
     * so this comparison produces real data without waiting on a domain's
     * own Phase 3 migration. Fire-and-forget: dispatch failures must never
     * affect the response this method returns.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function dispatchShadowDiff(array $candidate, string $chargeableItemId, ?string $tenantId, ?string $facilityId): void
    {
        ShadowDiffChargeCaptureCandidateJob::dispatch(
            sourceKind: (string) $candidate['sourceWorkflowKind'],
            sourceId: (string) $candidate['sourceWorkflowId'],
            chargeableItemId: $chargeableItemId,
            quantityOrDuration: (float) $candidate['quantity'],
            performedAt: $candidate['performedAt'],
            tenantId: $tenantId,
            facilityId: $facilityId,
            payerContractId: null,
            legacyCurrencyCode: (string) $candidate['currencyCode'],
            legacyServiceCode: $candidate['serviceCode'],
            legacyUnitPrice: (float) $candidate['unitPrice'],
            legacyPricingStatus: (string) $candidate['pricingStatus'],
        );
    }

    /**
     * PricingEngine_Migration_Plan.md Phase 3. Once a domain's flag is cut
     * over, serve the chargeable_item_id-resolved price instead of the
     * string-matched legacy one. Display fields (service name/code) are
     * untouched -- only the actual charge (unit price, total, status) changes.
     *
     * If the new resolver can't find a price (chargeable_item never
     * backfilled, or price_book_entries never populated for it -- the
     * backfill command is a one-time manual step, not a hook on catalog item
     * creation, so this drift is a real operational scenario, not just a
     * test fixture edge case), the candidate's already-computed legacy price
     * is left untouched rather than overwritten with a zero. Mirrors the
     * fallback bed-day/consultation already have for their own unmigrated
     * case -- this closes the same gap for the five order-domains.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function applyResolvedPrice(array $candidate, string $chargeableItemId, ?string $tenantId, ?string $facilityId): array
    {
        $resolved = app(ChargeResolverInterface::class)->resolvePrice(
            chargeableItemId: $chargeableItemId,
            quantityOrDuration: (float) $candidate['quantity'],
            asOfDate: $candidate['performedAt'],
            tenantId: $tenantId,
            facilityId: $facilityId,
            payerContractId: null,
            currencyCode: (string) $candidate['currencyCode'],
        );

        if ($resolved['pricingStatus'] !== 'priced') {
            return $candidate;
        }

        $candidate['unitPrice'] = $resolved['unitPrice'];
        $candidate['lineTotal'] = $resolved['lineTotal'];
        $candidate['pricingStatus'] = 'priced';
        $candidate['pricingSource'] = 'chargeable_item';
        $candidate['suggestedLineItem']['unitPrice'] = $resolved['unitPrice'];
        $candidate['suggestedLineItem']['lineTotal'] = $resolved['lineTotal'];

        return $candidate;
    }

    /**
     * @return array{mixed, mixed, string, ?string, float, string}
     */
    private function extractCandidateFields(mixed $order, ClinicalSourceKind $kind, ?array $catalogItem): array
    {
        return match ($kind) {
            ClinicalSourceKind::LABORATORY_ORDER => [
                $this->resolveServiceCode($order->test_code, $catalogItem),
                $this->resolveServiceName($order->test_name, $catalogItem),
                'laboratory',
                $this->dateTimeString($order->resulted_at ?? $order->updated_at ?? $order->ordered_at),
                1,
                $this->resolveUnit('test', $catalogItem),
            ],
            ClinicalSourceKind::RADIOLOGY_ORDER => [
                $this->resolveServiceCode($order->procedure_code, $catalogItem, $order->modality),
                $this->resolveServiceName($order->study_description, $catalogItem),
                'radiology',
                $this->dateTimeString($order->completed_at ?? $order->updated_at ?? $order->ordered_at),
                1,
                $this->resolveUnit('study', $catalogItem),
            ],
            ClinicalSourceKind::PHARMACY_ORDER => [
                $this->resolveServiceCode(
                    $order->substitution_made ? ($order->substituted_medication_code ?: $order->medication_code) : $order->medication_code,
                    $catalogItem,
                ),
                $this->resolveServiceName(
                    $order->substitution_made ? ($order->substituted_medication_name ?: $order->medication_name) : $order->medication_name,
                    $catalogItem,
                ),
                'pharmacy',
                $this->dateTimeString($order->dispensed_at ?? $order->updated_at ?? $order->ordered_at),
                max((float) ($order->quantity_dispensed ?? 0), 1),
                $this->resolvePharmacyBillableUnit($order, $catalogItem),
            ],
            ClinicalSourceKind::CLINICAL_PROCEDURE_ORDER => [
                $this->resolveServiceCode($order->procedure_code, $catalogItem, $order->procedure_setting),
                $this->resolveServiceName($order->procedure_description, $catalogItem),
                'procedure',
                $this->dateTimeString($order->completed_at ?? $order->updated_at ?? $order->ordered_at),
                1,
                $this->resolveUnit('procedure', $catalogItem),
            ],
            ClinicalSourceKind::THEATRE_PROCEDURE => [
                $this->resolveServiceCode(null, $catalogItem, $order->procedure_type),
                $this->resolveServiceName($order->procedure_name ?: $order->procedure_type, $catalogItem),
                'theatre',
                $this->dateTimeString($order->completed_at ?? $order->updated_at ?? $order->scheduled_at),
                1,
                $this->resolveUnit('procedure', $catalogItem),
            ],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $invoicedSources
     * @return array<string, mixed>
     */
    private function buildCandidate(
        string $sourceKind,
        string $sourceId,
        string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        mixed $sourceNumber,
        mixed $serviceCode,
        mixed $serviceName,
        string $serviceType,
        mixed $sourceStatus,
        ?string $performedAt,
        float $quantity,
        string $unit,
        string $currencyCode,
        array $invoicedSources,
    ): array {
        $serviceCodes = $this->normalizeServiceCodeCandidates($serviceCode);
        $normalizedServiceCode = $serviceCodes[0] ?? '';
        $label = trim((string) ($sourceNumber ?: $serviceName ?: $normalizedServiceCode ?: $sourceKind));
        // PricingEngine_Migration_Plan.md Phase 5: every domain's legacy
        // pricing path is now removed. $serviceCode is still passed through
        // for display (it's real data from the clinical catalog, not a
        // guess) but a price is never looked up here -- that's the callers'
        // job via applyResolvedPrice()/applyConsultationResolvedPrice()/
        // applyBedDayResolvedPrice(), all resolving through chargeable_item_id.
        // A candidate starts out unpriced; one of those methods upgrades it
        // when a chargeable item + price book entry exists.
        $resolvedDescription = trim((string) ($serviceName ?? $label));
        $resolvedUnit = trim($unit);
        $unitPrice = 0.0;
        $lineTotal = 0.0;
        $sourceKey = $this->sourceKey($sourceKind, $sourceId);
        $invoiceLink = $invoicedSources[$sourceKey] ?? null;
        $pricingStatus = $serviceCodes !== [] ? 'missing_catalog_price' : 'missing_service_code';

        $lineNotes = sprintf(
            'Charge capture from %s %s%s.',
            str_replace('_', ' ', $sourceKind),
            $label,
            $performedAt ? sprintf(' performed %s', $performedAt) : '',
        );

        return [
            'id' => $sourceKey,
            'sourceWorkflowKind' => $sourceKind,
            'sourceWorkflowId' => $sourceId,
            'sourceWorkflowLabel' => $label,
            'patientId' => $patientId,
            'appointmentId' => $appointmentId,
            'admissionId' => $admissionId,
            'sourceNumber' => $sourceNumber ? (string) $sourceNumber : null,
            'serviceCode' => $normalizedServiceCode !== '' ? $normalizedServiceCode : null,
            'serviceName' => $resolvedDescription,
            'serviceType' => $serviceType,
            'sourceStatus' => $sourceStatus ? (string) $sourceStatus : null,
            'performedAt' => $performedAt,
            'quantity' => round(max($quantity, 0), 2),
            'unit' => $resolvedUnit !== '' ? $resolvedUnit : $unit,
            'unitPrice' => $unitPrice,
            'lineTotal' => $lineTotal,
            'currencyCode' => $currencyCode,
            'pricingStatus' => $pricingStatus,
            'pricingSource' => null,
            'pricingSourceId' => null,
            'pricingLookupCodes' => $serviceCodes,
            'alreadyInvoiced' => $invoiceLink !== null,
            'invoiceId' => $invoiceLink['invoiceId'] ?? null,
            'invoiceNumber' => $invoiceLink['invoiceNumber'] ?? null,
            'invoiceStatus' => $invoiceLink['invoiceStatus'] ?? null,
            'suggestedLineItem' => [
                'description' => $resolvedDescription,
                'quantity' => round(max($quantity, 0), 2),
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal,
                'serviceCode' => $normalizedServiceCode !== '' ? $normalizedServiceCode : null,
                'unit' => $resolvedUnit !== '' ? $resolvedUnit : $unit,
                'notes' => $lineNotes,
                'sourceWorkflowKind' => $sourceKind,
                'sourceWorkflowId' => $sourceId,
                'sourceWorkflowLabel' => $label,
                'sourcePerformedAt' => $performedAt,
            ],
        ];
    }

    /**
     * billing-financial-state-remediation-plan.md, Phase 1: reads
     * billing_invoice_line_item_sources (kept in sync by
     * EloquentBillingInvoiceRepository whenever an invoice's line_items are
     * written) instead of loading every invoice and JSON-scanning its line_items
     * with a notes-regex fallback. A source only ever counts as "already
     * invoiced" if it was actually linked at write time -- no more silently
     * un-recognizing a manually-typed line item that never got
     * sourceWorkflowKind/Id populated.
     *
     * @return array<string, array<string, mixed>>
     */
    private function invoicedSourceIndex(string $patientId): array
    {
        $invoiceQuery = BillingInvoiceModel::query()
            ->where('patient_id', $patientId)
            ->whereNotIn('status', ['cancelled', 'voided']);

        $this->applyPlatformScopeIfEnabled($invoiceQuery);

        $invoices = $invoiceQuery->get(['id', 'invoice_number', 'status'])->keyBy('id');
        if ($invoices->isEmpty()) {
            return [];
        }

        $index = [];
        BillingInvoiceLineItemSourceModel::query()
            ->whereIn('billing_invoice_id', $invoices->keys())
            ->get(['billing_invoice_id', 'source_workflow_kind', 'source_workflow_id'])
            ->each(function (BillingInvoiceLineItemSourceModel $sourceRow) use (&$index, $invoices): void {
                $invoice = $invoices->get($sourceRow->billing_invoice_id);
                if ($invoice === null) {
                    return;
                }

                $index[$this->sourceKey($sourceRow->source_workflow_kind, $sourceRow->source_workflow_id)] = [
                    'invoiceId' => (string) $invoice->id,
                    'invoiceNumber' => $invoice->invoice_number,
                    'invoiceStatus' => $invoice->status,
                ];
            });

        return $index;
    }

    /**
     * @param  array<int, mixed>  $catalogItemIds
     * @return array<string, array<string, mixed>>
     */
    private function clinicalCatalogIndex(array $catalogItemIds): array
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $id): string => trim((string) $id),
                $catalogItemIds,
            ),
            static fn (string $id): bool => $id !== '',
        )));

        if ($normalizedIds === []) {
            return [];
        }

        $query = ClinicalCatalogItemModel::query()
            ->whereIn('id', $normalizedIds);

        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->get()
            ->mapWithKeys(static fn (ClinicalCatalogItemModel $item): array => [
                (string) $item->id => $item->toArray(),
            ])
            ->all();
    }

    private function sourceKey(string $kind, string $id): string
    {
        return sprintf('%s:%s', $kind, $id);
    }

    /**
     * @param  array<string, mixed>|null  $clinicianContext
     */
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

    /**
     * @param  array<int, int|null>  $userIds
     * @return array<int, array<string, mixed>>
     */
    private function consultationClinicianContextIndex(array $userIds): array
    {
        $normalizedUserIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $userId): int => (int) $userId, $userIds),
            static fn (int $userId): bool => $userId > 0,
        )));

        if ($normalizedUserIds === []) {
            return [];
        }

        $staffProfileQuery = StaffProfileModel::query()
            ->whereIn('user_id', $normalizedUserIds);
        $this->applyStaffPlatformScopeIfEnabled($staffProfileQuery);

        $staffProfiles = $staffProfileQuery->get();
        $staffProfileIds = $staffProfiles
            ->pluck('id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($staffProfileIds === []) {
            return [];
        }

        $regulatoryProfileQuery = StaffRegulatoryProfileModel::query()
            ->whereIn('staff_profile_id', $staffProfileIds);
        $this->applyStaffPlatformScopeIfEnabled($regulatoryProfileQuery);

        $regulatoryProfiles = $regulatoryProfileQuery
            ->get()
            ->mapWithKeys(static fn (StaffRegulatoryProfileModel $profile): array => [
                (string) $profile->staff_profile_id => $profile->toArray(),
            ])
            ->all();

        $specialtyAssignments = StaffProfileSpecialtyModel::query()
            ->whereIn('staff_profile_id', $staffProfileIds)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get()
            ->groupBy('staff_profile_id')
            ->map(static fn ($assignments) => $assignments->first());

        $specialtyIds = $specialtyAssignments
            ->map(static fn (?StaffProfileSpecialtyModel $assignment): ?string => $assignment ? (string) $assignment->specialty_id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $specialtyQuery = ClinicalSpecialtyModel::query()
            ->whereIn('id', $specialtyIds);
        $this->applyStaffPlatformScopeIfEnabled($specialtyQuery);

        $specialties = $specialtyIds !== []
            ? $specialtyQuery
                ->get()
                ->mapWithKeys(static fn (ClinicalSpecialtyModel $specialty): array => [
                    (string) $specialty->id => $specialty->toArray(),
                ])
                ->all()
            : [];

        $contextIndex = [];

        foreach ($staffProfiles as $staffProfile) {
            $staffProfileId = (string) $staffProfile->id;
            $assignment = $specialtyAssignments->get($staffProfileId);
            $specialtyId = $assignment ? (string) $assignment->specialty_id : null;

            $contextIndex[(int) $staffProfile->user_id] = [
                'profile' => $staffProfile->toArray(),
                'regulatoryProfile' => $regulatoryProfiles[$staffProfileId] ?? null,
                'specialty' => $specialtyId !== null ? ($specialties[$specialtyId] ?? null) : null,
            ];
        }

        return $contextIndex;
    }

    private function consultationOwnerUserId(AppointmentModel $appointment): ?int
    {
        foreach ([$appointment->consultation_owner_user_id, $appointment->clinician_user_id] as $userId) {
            $normalizedUserId = (int) $userId;
            if ($normalizedUserId > 0) {
                return $normalizedUserId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $clinicianContext
     */
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
            'SPECIALIST',
            'CONSULTANT',
            'CARDIOLOG',
            'DERMAT',
            'GYNAE',
            'NEURO',
            'OBSTET',
            'ORTHOP',
            'PAEDIATR',
            'PEDIATR',
            'PSYCHIATR',
            'RADIOLOG',
            'SURGEON',
            'UROLOG',
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

    /**
     * @param  array<int, string>  $needles
     */
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

    private function serviceCodeToken(string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9]+/', '-', strtoupper(trim($value)));
        $normalized = trim((string) $normalized, '-');

        return $normalized;
    }

    private function resolveServiceCode(mixed $preferredCode, ?array $clinicalCatalogItem, mixed $fallbackCode = null): ?string
    {
        $metadata = is_array($clinicalCatalogItem['metadata'] ?? null)
            ? $clinicalCatalogItem['metadata']
            : [];

        $candidateCodes = [
            $preferredCode,
            $metadata['billingServiceCode'] ?? null,
            $metadata['billing_service_code'] ?? null,
            $clinicalCatalogItem['code'] ?? null,
            $fallbackCode,
        ];

        foreach ($candidateCodes as $candidateCode) {
            $normalized = strtoupper(trim((string) $candidateCode));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  mixed|array<int, mixed>  $serviceCode
     * @return array<int, string>
     */
    private function normalizeServiceCodeCandidates(mixed $serviceCode): array
    {
        $rawCodes = is_array($serviceCode) ? $serviceCode : [$serviceCode];
        $codes = [];

        foreach ($rawCodes as $rawCode) {
            $code = strtoupper(trim((string) $rawCode));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function resolveServiceName(mixed $preferredName, ?array $clinicalCatalogItem, mixed $fallbackName = null): string
    {
        $candidateNames = [
            $preferredName,
            $clinicalCatalogItem['name'] ?? null,
            $fallbackName,
        ];

        foreach ($candidateNames as $candidateName) {
            $normalized = trim((string) $candidateName);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'Clinical service';
    }

    private function resolveUnit(string $fallbackUnit, ?array $clinicalCatalogItem): string
    {
        $catalogUnit = trim((string) ($clinicalCatalogItem['unit'] ?? ''));

        return $catalogUnit !== '' ? $catalogUnit : $fallbackUnit;
    }

    private function resolvePharmacyBillableUnit(mixed $order, ?array $clinicalCatalogItem): string
    {
        foreach ([
            $order->dispensed_unit,
            $order->prescribed_unit,
            $clinicalCatalogItem['unit'] ?? null,
        ] as $candidateUnit) {
            $normalizedUnit = trim((string) $candidateUnit);
            if ($normalizedUnit !== '') {
                return $normalizedUnit;
            }
        }

        return 'unit';
    }

    private function applyClinicalContextFilters(
        Builder $query,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $admissionId,
    ): void {
        if ($encounterId !== null) {
            $query->where('encounter_id', $encounterId);

            return;
        }

        if ($appointmentId !== null) {
            $query->where('appointment_id', $appointmentId);
        }

        if ($admissionId !== null) {
            $query->where('admission_id', $admissionId);
        }
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            && ! $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation')) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function applyStaffPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            && ! $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation')) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query, facilityColumn: null);
    }

    private function normalizeNullableUuid(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function dateTimeString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function buildMeta(array $candidates, string $currencyCode, bool $includeInvoiced): array
    {
        $pending = 0;
        $alreadyInvoiced = 0;
        $priced = 0;
        $missingPrice = 0;

        foreach ($candidates as $candidate) {
            if ((bool) ($candidate['alreadyInvoiced'] ?? false)) {
                $alreadyInvoiced++;
            } else {
                $pending++;
            }

            if (($candidate['pricingStatus'] ?? null) === 'priced') {
                $priced++;
            } else {
                $missingPrice++;
            }
        }

        return [
            'currencyCode' => $currencyCode,
            'includeInvoiced' => $includeInvoiced,
            'total' => count($candidates),
            'pending' => $pending,
            'alreadyInvoiced' => $alreadyInvoiced,
            'priced' => $priced,
            'missingPrice' => $missingPrice,
        ];
    }
}
