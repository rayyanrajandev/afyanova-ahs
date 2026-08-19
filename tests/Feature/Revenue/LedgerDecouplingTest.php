<?php

use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Encounter\Application\UseCases\GetEncounterCloseReadinessUseCase;
use App\Modules\InpatientWard\Infrastructure\Repositories\EloquentInpatientWardFollowUpRailRepository;
use App\Modules\Patient\Application\UseCases\GetPatientSummaryUseCase;
use App\Modules\PatientFlow\Application\UseCases\GetActiveVisitJourneyUseCase;
use App\Modules\Payer\Domain\Repositories\PatientInsuranceRepositoryInterface;
use App\Modules\Payer\Domain\Repositories\PayerContractRepositoryInterface;
use App\Modules\Payer\Presentation\Http\Controllers\PatientInsuranceController;
use App\Modules\Revenue\Domain\Services\OutstandingBalanceReaderInterface;
use App\Modules\Revenue\Infrastructure\Services\LedgerOutstandingBalanceReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 1 guarantee: no live clinical surface reaches into the Billing module
 * any more, so Phase 2 can delete it outright.
 *
 * This is the test that makes the deletion safe. Without it the coupling would
 * be re-established by the next person who needs a balance on a screen, and
 * the failure would only surface once Billing was already gone.
 */
it('resolves every re-pointed consumer from the container', function (string $abstract): void {
    expect(app($abstract))->toBeObject();
})->with([
    OutstandingBalanceReaderInterface::class,
    PatientInsuranceRepositoryInterface::class,
    PayerContractRepositoryInterface::class,
    GetPatientSummaryUseCase::class,
    GetActiveVisitJourneyUseCase::class,
    GetEncounterCloseReadinessUseCase::class,
    EloquentInpatientWardFollowUpRailRepository::class,
    UpdateAppointmentStatusUseCase::class,
    PatientInsuranceController::class,
]);

it('binds the outstanding-balance reader to the prepaid ledger', function (): void {
    // Phase 1 bound this to a null reader because no ledger existed; Phase 3
    // swapped the binding and not one consumer changed, which is the whole
    // reason they were routed through the contract.
    expect(app(OutstandingBalanceReaderInterface::class))
        ->toBeInstanceOf(LedgerOutstandingBalanceReader::class);
});

it('reports nothing outstanding for a patient with no charges', function (): void {
    $reader = app(OutstandingBalanceReaderInterface::class);
    $unknownPatient = (string) Str::uuid();

    expect($reader->outstandingCountForPatient($unknownPatient))->toBe(0)
        ->and($reader->latestDocumentForPatient($unknownPatient))->toBeNull()
        ->and($reader->outstandingDocumentsForAdmission((string) Str::uuid()))->toBe([]);

    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    expect($reader->patientsWithOutstanding([$a, $b]))->toBe([$a => false, $b => false]);
});

it('has no reference to the retired Billing, Pos or ClaimsInsurance modules', function (): void {
    // Phase 1 shipped this with an allowlist of files that still named the
    // Billing module because Phase 2 was going to delete them. Phase 2 did,
    // so the allowlist is gone and the assertion is now absolute.
    $offenders = [];

    foreach ([app_path(), base_path('routes'), base_path('config'), base_path('database')] as $root) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach (['Modules\\Billing\\', 'Modules\\Pos\\', 'Modules\\ClaimsInsurance\\'] as $retired) {
                if (str_contains($contents, $retired)) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                    break;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has removed every retired module directory', function (): void {
    expect(is_dir(app_path('Modules/Billing')))->toBeFalse()
        ->and(is_dir(app_path('Modules/Pos')))->toBeFalse()
        ->and(is_dir(app_path('Modules/ClaimsInsurance')))->toBeFalse();
});

it('has dropped the retired schema but kept the pricing and payer tables', function (): void {
    $schema = DB::getSchemaBuilder();

    foreach (['billing_invoices', 'billing_invoice_payments', 'pos_sales', 'pos_register_sessions',
        'cash_billing_accounts', 'claims_insurance_cases', 'consultation_mappings',
        'gl_journal_entries', 'billing_service_catalog_items'] as $dropped) {
        expect($schema->hasTable($dropped))->toBeFalse("expected {$dropped} to be dropped");
    }

    foreach (['chargeable_items', 'price_book_entries', 'price_book_entry_audit_logs',
        'billing_payer_contracts', 'patient_insurance_records'] as $kept) {
        expect($schema->hasTable($kept))->toBeTrue("expected {$kept} to survive");
    }
});

it('has retired the billing, pos and claims permission namespaces', function (): void {
    $retired = DB::table('permissions')
        ->where(function ($query): void {
            foreach (['billing.', 'billing-', 'pos.', 'pos-', 'claims.', 'claims-'] as $prefix) {
                $query->orWhere('name', 'like', $prefix.'%');
            }
        })
        ->pluck('name')
        ->all();

    expect($retired)->toBe([]);

    // The reception insurance routes are still live and still guarded.
    expect(DB::table('permissions')->where('name', 'patients.insurance.manage')->exists())->toBeTrue()
        ->and(DB::table('permissions')->where('name', 'patients.insurance.verify')->exists())->toBeTrue();
});
