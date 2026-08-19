<?php

namespace App\Modules\Revenue\Domain\Services;

use App\Modules\Revenue\Domain\ValueObjects\RevenueDocumentSummary;

/**
 * The one question clinical surfaces are allowed to ask the revenue ledger:
 * does this patient owe anything, and what are the documents behind it?
 *
 * Four call sites used to answer this by querying BillingInvoiceModel
 * directly — the patient summary's stats block and activity feed, the
 * patient-flow board's per-visit billing chip, and the inpatient ward's
 * follow-up rail. Each carried its own notion of which invoice statuses count
 * as "outstanding", so the board and the summary could disagree about the same
 * patient.
 *
 * Routing all four through one contract means the prepaid ledger can replace
 * the invoice ledger underneath them without any of them changing, and it
 * makes "outstanding" a single definition owned by Revenue.
 */
interface OutstandingBalanceReaderInterface
{
    /**
     * How many outstanding financial documents this patient has.
     */
    public function outstandingCountForPatient(string $patientId): int;

    /**
     * Bulk variant for list rendering — one query for a whole queue rather
     * than one per row.
     *
     * @param  list<string>  $patientIds
     * @return array<string, bool> keyed by patient id; true when outstanding
     */
    public function patientsWithOutstanding(array $patientIds): array;

    /**
     * The patient's most recent financial document, for activity feeds.
     */
    public function latestDocumentForPatient(string $patientId): ?RevenueDocumentSummary;

    /**
     * Outstanding documents raised against an admission, for the ward
     * follow-up rail.
     *
     * @return list<RevenueDocumentSummary>
     */
    public function outstandingDocumentsForAdmission(string $admissionId): array;
}
