<?php

namespace App\Modules\Billing\Infrastructure\Integrations\NHIF;

use App\Modules\Billing\Application\Exceptions\BillingInvoicePaymentRecordingNotAllowedException;
use App\Modules\Billing\Application\UseCases\RecordBillingInvoicePaymentUseCase;
use App\Modules\Billing\Domain\Integrations\NhifRemittanceInterface;
use App\Modules\Billing\Domain\ValueObjects\NhifRemittanceResult;
use App\Modules\Billing\Infrastructure\Models\BillingNhifClaimSubmissionModel;
use App\Modules\Billing\Infrastructure\Models\BillingNhifRemittanceItemModel;
use App\Modules\Billing\Infrastructure\Models\BillingNhifRemittanceModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SplFileObject;

class NhifRemittanceProcessor implements NhifRemittanceInterface
{
    public function __construct(
        private readonly RecordBillingInvoicePaymentUseCase $recordPaymentUseCase,
    ) {}

    public function parseFile(string $filePath, string $format = 'csv'): array
    {
        return match ($format) {
            'json' => $this->parseJson($filePath),
            default => $this->parseCsv($filePath),
        };
    }

    private function parseCsv(string $filePath): array
    {
        $file = new SplFileObject($filePath, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $headers = $file->current();
        if (!$headers || count($headers) < 2) {
            throw new \InvalidArgumentException('CSV file is empty or has no headers');
        }
        $file->next();

        $records = [];
        while (!$file->eof()) {
            $row = $file->fgetcsv();
            if (!$row || count($row) < 2) {
                continue;
            }

            $record = array_combine(
                array_map('trim', $headers),
                array_map('trim', $row),
            );

            if (empty($record['claim_reference'] ?? '')) {
                continue;
            }

            $records[] = [
                'claim_reference' => $record['claim_reference'],
                'member_number' => $record['member_number'] ?? '',
                'patient_name' => $record['patient_name'] ?? '',
                'claimed_amount' => (float) ($record['claimed_amount'] ?? 0),
                'approved_amount' => (float) ($record['approved_amount'] ?? 0),
                'rejected_amount' => (float) ($record['rejected_amount'] ?? 0),
                'settled_amount' => (float) ($record['settled_amount'] ?? 0),
                'decision' => $record['decision'] ?? '',
                'decision_reason' => $record['decision_reason'] ?? '',
                'raw' => $record,
            ];
        }

        return $records;
    }

    private function parseJson(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON file');
        }

        $items = $data['items'] ?? $data['claims'] ?? $data['data'] ?? $data;

        return array_map(fn($item) => [
            'claim_reference' => $item['claim_reference'] ?? $item['claimReference'] ?? '',
            'member_number' => $item['member_number'] ?? $item['memberNumber'] ?? '',
            'patient_name' => $item['patient_name'] ?? $item['patientName'] ?? '',
            'claimed_amount' => (float) ($item['claimed_amount'] ?? $item['claimedAmount'] ?? 0),
            'approved_amount' => (float) ($item['approved_amount'] ?? $item['approvedAmount'] ?? 0),
            'rejected_amount' => (float) ($item['rejected_amount'] ?? $item['rejectedAmount'] ?? 0),
            'settled_amount' => (float) ($item['settled_amount'] ?? $item['settledAmount'] ?? $item['amount'] ?? 0),
            'decision' => $item['decision'] ?? $item['status'] ?? '',
            'decision_reason' => $item['decision_reason'] ?? $item['decisionReason'] ?? $item['reason'] ?? '',
            'raw' => $item,
        ], $items);
    }

    public function reconcile(array $remittanceRecords, string $tenantId, string $facilityId): NhifRemittanceResult
    {
        $totalClaims = count($remittanceRecords);
        $totalAmount = 0;
        $matchedClaims = 0;
        $matchedAmount = 0;
        $unmatchedAmount = 0;
        $errors = [];

        foreach ($remittanceRecords as $record) {
            $claimRef = $record['claim_reference'];
            $totalAmount += $record['settled_amount'];

            $submission = BillingNhifClaimSubmissionModel::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('nhif_claim_reference', $claimRef)
                ->first();

            if (!$submission) {
                $unmatchedAmount += $record['settled_amount'];
                $errors[] = "Claim reference {$claimRef} not found in submissions";
                continue;
            }

            $matchedClaims++;
            $matchedAmount += $record['settled_amount'];

            $case = ClaimsInsuranceCaseModel::find($submission->claims_insurance_case_id);

            if ($case) {
                $case->update([
                    'reconciliation_status' => 'matched',
                    'approved_amount' => ($case->approved_amount ?? 0) + $record['approved_amount'],
                    'rejected_amount' => ($case->rejected_amount ?? 0) + $record['rejected_amount'],
                    'settled_amount' => ($case->settled_amount ?? 0) + $record['settled_amount'],
                    'settlement_reference' => $record['remittance_reference'] ?? $submission->nhif_claim_reference,
                    'settled_at' => now(),
                    'decision_reason' => $record['decision_reason'] ?: $case->decision_reason,
                ]);
            }

            $submission->update([
                'submission_status' => strtolower($record['decision'] ?? 'settled'),
                'acknowledged_at' => now(),
            ]);

            // billing-financial-state-remediation-plan.md, Phase 2: an NHIF
            // settlement previously never touched the linked invoice itself --
            // the claim/submission would show settled while the invoice stayed
            // issued/partially_paid forever. Record the settlement as an actual
            // invoice payment now. Wrapped defensively (already paid/cancelled/
            // voided invoices, or a missing link, must not abort the batch --
            // same tolerance this method already applies to unmatched claims).
            if ($submission->billing_invoice_id !== null && $record['settled_amount'] > 0) {
                try {
                    $this->recordPaymentUseCase->execute(
                        billingInvoiceId: (string) $submission->billing_invoice_id,
                        amount: (float) $record['settled_amount'],
                        payerType: 'insurance',
                        paymentMethod: 'nhif',
                        paymentReference: $record['remittance_reference'] ?? $claimRef,
                        note: 'NHIF remittance reconciliation',
                    );
                } catch (BillingInvoicePaymentRecordingNotAllowedException $exception) {
                    $errors[] = sprintf(
                        'Claim %s settled but invoice payment could not be recorded: %s',
                        $claimRef,
                        $exception->getMessage(),
                    );
                }
            }
        }

        return new NhifRemittanceResult(
            success: true,
            remittanceReference: $remittanceRecords[0]['remittance_reference'] ?? 'unknown',
            totalClaims: $totalClaims,
            matchedClaims: $matchedClaims,
            totalAmount: $totalAmount,
            matchedAmount: $matchedAmount,
            unmatchedAmount: $unmatchedAmount,
            message: "Matched {$matchedClaims} of {$totalClaims} claims ({$matchedAmount} of {$totalAmount})",
            errors: $errors,
        );
    }

    /**
     * Async counterpart to processFile(): operates on a remittance row that
     * already exists (created as 'pending' by the upload controller before
     * this runs in a queued job), instead of creating one only on success.
     * Always persists a terminal status + error_message onto that row so
     * BillingNhifRemittanceController::history()/show() can serve as the
     * polling UI with no new tracking model or endpoints.
     */
    public function reconcileStoredFile(
        BillingNhifRemittanceModel $remittance,
        string $filePath,
        string $format = 'csv',
    ): NhifRemittanceResult {
        $records = $this->parseFile($filePath, $format);

        if (empty($records)) {
            $remittance->fill([
                'status' => 'failed',
                'error_message' => 'No records found in file',
                'processed_at' => now(),
            ])->save();

            return new NhifRemittanceResult(
                success: false,
                remittanceReference: (string) $remittance->remittance_reference,
                message: 'No records found in file',
            );
        }

        $tenantId = (string) $remittance->tenant_id;
        $facilityId = (string) $remittance->facility_id;

        $remittanceRef = $records[0]['raw']['remittance_reference']
            ?? $records[0]['raw']['remittanceReference']
            ?? basename($filePath);

        $duplicate = BillingNhifRemittanceModel::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('remittance_reference', $remittanceRef)
            ->whereKeyNot($remittance->getKey())
            ->first();

        if ($duplicate) {
            $remittance->fill([
                'status' => 'failed',
                'error_message' => 'Remittance already processed',
                'processed_at' => now(),
            ])->save();

            return new NhifRemittanceResult(
                success: false,
                remittanceReference: $remittanceRef,
                message: 'Remittance already processed',
                rawData: $duplicate->toArray(),
            );
        }

        DB::beginTransaction();
        try {
            $reconcileResult = $this->reconcile($records, $tenantId, $facilityId);

            $remittance->fill([
                'remittance_reference' => $remittanceRef,
                'payer_name' => $records[0]['raw']['payer_name'] ?? 'NHIF',
                'total_amount' => $reconcileResult->totalAmount,
                'total_claims' => $reconcileResult->totalClaims,
                'matched_claims' => $reconcileResult->matchedClaims,
                'matched_amount' => $reconcileResult->matchedAmount,
                'unmatched_amount' => $reconcileResult->unmatchedAmount,
                'raw_data' => $records,
                'status' => $reconcileResult->matchedClaims > 0 ? 'completed' : 'partial',
                'error_message' => null,
                'processed_at' => now(),
            ])->save();

            foreach ($records as $record) {
                $submission = BillingNhifClaimSubmissionModel::query()
                    ->where('tenant_id', $tenantId)
                    ->where('facility_id', $facilityId)
                    ->where('nhif_claim_reference', $record['claim_reference'])
                    ->first();

                BillingNhifRemittanceItemModel::create([
                    'billing_nhif_remittance_id' => $remittance->id,
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'claim_reference' => $record['claim_reference'],
                    'member_number' => $record['member_number'] ?? null,
                    'patient_name' => $record['patient_name'] ?? null,
                    'claimed_amount' => $record['claimed_amount'],
                    'approved_amount' => $record['approved_amount'],
                    'rejected_amount' => $record['rejected_amount'],
                    'settled_amount' => $record['settled_amount'],
                    'decision' => $record['decision'],
                    'decision_reason' => $record['decision_reason'],
                    'raw_data' => $record['raw'],
                    'reconciliation_status' => $submission ? 'matched' : 'unmatched',
                    'matched_claim_submission_id' => $submission?->id,
                    'matched_claims_insurance_case_id' => $submission?->claims_insurance_case_id,
                ]);
            }

            DB::commit();

            return new NhifRemittanceResult(
                success: true,
                remittanceReference: $remittanceRef,
                totalClaims: $reconcileResult->totalClaims,
                matchedClaims: $reconcileResult->matchedClaims,
                totalAmount: $reconcileResult->totalAmount,
                matchedAmount: $reconcileResult->matchedAmount,
                unmatchedAmount: $reconcileResult->unmatchedAmount,
                message: $reconcileResult->message,
                rawData: $remittance->toArray(),
                errors: $reconcileResult->errors,
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to process NHIF remittance', [
                'remittanceId' => $remittance->id,
                'error' => $e->getMessage(),
            ]);

            $remittance->fill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ])->save();

            return new NhifRemittanceResult(
                success: false,
                remittanceReference: $remittanceRef,
                message: $e->getMessage(),
                errors: [$e->getMessage()],
            );
        }
    }

    public function processFile(
        string $filePath,
        string $tenantId,
        string $facilityId,
        string $format = 'csv',
        ?string $originalFilename = null,
        ?int $userId = null,
    ): NhifRemittanceResult {
        $records = $this->parseFile($filePath, $format);

        if (empty($records)) {
            return new NhifRemittanceResult(
                success: false,
                remittanceReference: 'unknown',
                message: 'No records found in file',
            );
        }

        $remittanceRef = $records[0]['raw']['remittance_reference']
            ?? $records[0]['raw']['remittanceReference']
            ?? basename($filePath);

        $duplicate = BillingNhifRemittanceModel::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('remittance_reference', $remittanceRef)
            ->first();

        if ($duplicate) {
            return new NhifRemittanceResult(
                success: false,
                remittanceReference: $remittanceRef,
                message: 'Remittance already processed',
                rawData: $duplicate->toArray(),
            );
        }

        DB::beginTransaction();
        try {
            $reconcileResult = $this->reconcile($records, $tenantId, $facilityId);

            $remittance = BillingNhifRemittanceModel::create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'remittance_reference' => $remittanceRef,
                'remittance_date' => now()->toDateString(),
                'payer_name' => $records[0]['raw']['payer_name'] ?? 'NHIF',
                'total_amount' => $reconcileResult->totalAmount,
                'total_claims' => $reconcileResult->totalClaims,
                'matched_claims' => $reconcileResult->matchedClaims,
                'matched_amount' => $reconcileResult->matchedAmount,
                'unmatched_amount' => $reconcileResult->unmatchedAmount,
                'source' => 'upload',
                'original_filename' => $originalFilename,
                'raw_data' => $records,
                'status' => $reconcileResult->matchedClaims > 0 ? 'completed' : 'partial',
                'processed_at' => now(),
                'uploaded_by_user_id' => $userId,
            ]);

            foreach ($records as $record) {
                $submission = BillingNhifClaimSubmissionModel::query()
                    ->where('tenant_id', $tenantId)
                    ->where('facility_id', $facilityId)
                    ->where('nhif_claim_reference', $record['claim_reference'])
                    ->first();

                BillingNhifRemittanceItemModel::create([
                    'billing_nhif_remittance_id' => $remittance->id,
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'claim_reference' => $record['claim_reference'],
                    'member_number' => $record['member_number'] ?? null,
                    'patient_name' => $record['patient_name'] ?? null,
                    'claimed_amount' => $record['claimed_amount'],
                    'approved_amount' => $record['approved_amount'],
                    'rejected_amount' => $record['rejected_amount'],
                    'settled_amount' => $record['settled_amount'],
                    'decision' => $record['decision'],
                    'decision_reason' => $record['decision_reason'],
                    'raw_data' => $record['raw'],
                    'reconciliation_status' => $submission ? 'matched' : 'unmatched',
                    'matched_claim_submission_id' => $submission?->id,
                    'matched_claims_insurance_case_id' => $submission?->claims_insurance_case_id,
                ]);
            }

            DB::commit();

            return new NhifRemittanceResult(
                success: true,
                remittanceReference: $remittanceRef,
                totalClaims: $reconcileResult->totalClaims,
                matchedClaims: $reconcileResult->matchedClaims,
                totalAmount: $reconcileResult->totalAmount,
                matchedAmount: $reconcileResult->matchedAmount,
                unmatchedAmount: $reconcileResult->unmatchedAmount,
                message: $reconcileResult->message,
                rawData: $remittance->toArray(),
                errors: $reconcileResult->errors,
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to process NHIF remittance', [
                'file' => $originalFilename,
                'error' => $e->getMessage(),
            ]);

            return new NhifRemittanceResult(
                success: false,
                remittanceReference: $remittanceRef,
                message: $e->getMessage(),
                errors: [$e->getMessage()],
            );
        }
    }
}
