<?php

namespace App\Support\ClinicalOrders;

class ClinicalCurrentCare
{
    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function laboratory(array $order): array
    {
        if (self::isDraft($order)) {
            return self::baseFlags();
        }

        $status = self::normalize($order['status'] ?? null);
        $resultFlag = self::extractLaboratoryResultFlag($order['result_summary'] ?? null);
        $hasCriticalResult = $status === 'completed' && $resultFlag === 'critical';
        $hasAbnormalResult = $status === 'completed' && in_array($resultFlag, ['abnormal', 'inconclusive'], true);
        $isPending = in_array($status, ['ordered', 'collected', 'in_progress'], true);
        $isRecentlyCompleted = $status === 'completed'
            && self::isWithinDays($order['resulted_at'] ?? $order['ordered_at'] ?? null, 14);
        $requiresReview = $isPending || $hasCriticalResult || $hasAbnormalResult;

        return [
            'isCurrent' => $requiresReview || $isRecentlyCompleted,
            'requiresReview' => $requiresReview,
            'priorityRank' => self::laboratoryPriorityRank($status, $hasCriticalResult, $hasAbnormalResult),
            'isPending' => $isPending,
            'hasCriticalResult' => $hasCriticalResult,
            'hasAbnormalResult' => $hasAbnormalResult,
            'isRecentlyCompleted' => $isRecentlyCompleted,
            'workflowHint' => self::laboratoryWorkflowHint(
                $status,
                $hasCriticalResult,
                $hasAbnormalResult,
                $isPending,
                $isRecentlyCompleted,
            ),
            'nextAction' => self::laboratoryNextAction(
                $status,
                $hasCriticalResult,
                $hasAbnormalResult,
                $isPending,
                $isRecentlyCompleted,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function radiology(array $order): array
    {
        if (self::isDraft($order)) {
            return self::baseFlags();
        }

        $status = self::normalize($order['status'] ?? null);
        $reportSignal = self::radiologyReportSignal($order['report_summary'] ?? null);
        $hasCriticalReport = $status === 'completed' && $reportSignal === 'critical';
        $hasAbnormalReport = $status === 'completed' && $reportSignal === 'abnormal';
        $isPending = in_array($status, ['ordered', 'scheduled', 'in_progress'], true);
        $isRecentlyCompleted = $status === 'completed'
            && self::isWithinDays($order['completed_at'] ?? $order['ordered_at'] ?? null, 14);
        $requiresReview = $isPending || $hasCriticalReport || $hasAbnormalReport;

        return [
            'isCurrent' => $requiresReview || $isRecentlyCompleted,
            'requiresReview' => $requiresReview,
            'priorityRank' => self::radiologyPriorityRank($status, $hasCriticalReport, $hasAbnormalReport),
            'isPending' => $isPending,
            'hasCriticalReport' => $hasCriticalReport,
            'hasAbnormalReport' => $hasAbnormalReport,
            'isRecentlyCompleted' => $isRecentlyCompleted,
            'workflowHint' => self::radiologyWorkflowHint(
                $status,
                $hasCriticalReport,
                $hasAbnormalReport,
                $isPending,
                $isRecentlyCompleted,
            ),
            'nextAction' => self::radiologyNextAction(
                $status,
                $hasCriticalReport,
                $hasAbnormalReport,
                $isPending,
                $isRecentlyCompleted,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function pharmacy(array $order): array
    {
        $status = self::normalize($order['status'] ?? null);
        $reconciliationStatus = self::normalize($order['reconciliation_status'] ?? null);
        $formularyDecision = self::normalize($order['formulary_decision_status'] ?? null);
        $awaitingVerification = $status === 'dispensed' && blank($order['verified_at'] ?? null);
        $awaitingReconciliation = $status === 'dispensed'
            && in_array($reconciliationStatus, ['', 'pending', 'exception'], true);
        $hasPolicyIssue = ! in_array($status, ['dispensed', 'cancelled'], true)
            && (
                in_array($formularyDecision, ['non_formulary', 'restricted'], true)
                || (bool) ($order['substitution_made'] ?? false)
            );
        $isActiveWorkflow = in_array($status, ['pending', 'in_preparation', 'partially_dispensed'], true);
        $wasRecentlyDispensed = $status === 'dispensed'
            && self::isWithinDays($order['dispensed_at'] ?? $order['ordered_at'] ?? null, 30);
        $requiresReview = $awaitingVerification || $awaitingReconciliation || $hasPolicyIssue || $isActiveWorkflow;

        return [
            'isCurrent' => $requiresReview || $wasRecentlyDispensed,
            'requiresReview' => $requiresReview,
            'priorityRank' => self::pharmacyPriorityRank(
                $status,
                $awaitingVerification,
                $awaitingReconciliation,
                $hasPolicyIssue,
            ),
            'isActiveWorkflow' => $isActiveWorkflow,
            'awaitingVerification' => $awaitingVerification,
            'awaitingReconciliation' => $awaitingReconciliation,
            'hasPolicyIssue' => $hasPolicyIssue,
            'wasRecentlyDispensed' => $wasRecentlyDispensed,
            'workflowHint' => self::pharmacyWorkflowHint(
                $status,
                $reconciliationStatus,
                $awaitingVerification,
                $awaitingReconciliation,
                $hasPolicyIssue,
                $wasRecentlyDispensed,
            ),
            'nextAction' => self::pharmacyNextAction(
                $status,
                $reconciliationStatus,
                $awaitingVerification,
                $awaitingReconciliation,
                $hasPolicyIssue,
                $wasRecentlyDispensed,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function clinicalProcedure(array $order): array
    {
        if (self::isDraft($order)) {
            return self::baseFlags();
        }

        $status = self::normalize($order['status'] ?? null);
        $reportSignal = self::radiologyReportSignal($order['report_summary'] ?? null);
        $hasCriticalReport = $status === 'completed' && $reportSignal === 'critical';
        $hasAbnormalReport = $status === 'completed' && $reportSignal === 'abnormal';
        $isPending = in_array($status, ['ordered', 'scheduled', 'in_progress'], true);
        $isRecentlyCompleted = $status === 'completed'
            && self::isWithinDays($order['completed_at'] ?? $order['ordered_at'] ?? null, 14);
        $requiresReview = $isPending || $hasCriticalReport || $hasAbnormalReport;

        return [
            'isCurrent' => $requiresReview || $isRecentlyCompleted,
            'requiresReview' => $requiresReview,
            'priorityRank' => self::radiologyPriorityRank($status, $hasCriticalReport, $hasAbnormalReport),
            'isPending' => $isPending,
            'hasCriticalReport' => $hasCriticalReport,
            'hasAbnormalReport' => $hasAbnormalReport,
            'isRecentlyCompleted' => $isRecentlyCompleted,
            'workflowHint' => self::radiologyWorkflowHint(
                $status,
                $hasCriticalReport,
                $hasAbnormalReport,
                $isPending,
                $isRecentlyCompleted,
            ),
            'nextAction' => self::radiologyNextAction(
                $status,
                $hasCriticalReport,
                $hasAbnormalReport,
                $isPending,
                $isRecentlyCompleted,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $procedure
     * @return array<string, mixed>
     */
    public static function theatre(array $procedure): array
    {
        if (self::isDraft($procedure)) {
            return self::baseFlags();
        }

        $status = self::normalize($procedure['status'] ?? null);
        $isInProgress = $status === 'in_progress';
        $isUpcoming = in_array($status, ['planned', 'in_preop'], true);
        $wasRecentlyCompleted = $status === 'completed'
            && self::isWithinDays($procedure['completed_at'] ?? $procedure['scheduled_at'] ?? null, 30);
        $requiresReview = $isInProgress || $isUpcoming;

        return [
            'isCurrent' => $requiresReview || $wasRecentlyCompleted,
            'requiresReview' => $requiresReview,
            'priorityRank' => self::theatrePriorityRank($status),
            'isInProgress' => $isInProgress,
            'isUpcoming' => $isUpcoming,
            'wasRecentlyCompleted' => $wasRecentlyCompleted,
            'workflowHint' => self::theatreWorkflowHint(
                $status,
                $isInProgress,
                $isUpcoming,
                $wasRecentlyCompleted,
            ),
            'nextAction' => self::theatreNextAction(
                $status,
                $isInProgress,
                $isUpcoming,
                $wasRecentlyCompleted,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseFlags(): array
    {
        return [
            'isCurrent' => false,
            'requiresReview' => false,
            'priorityRank' => 0,
            'workflowHint' => null,
            'nextAction' => null,
        ];
    }

    /**
     * @return array{key: string, label: string, emphasis: string}
     */
    private static function nextAction(string $key, string $label, string $emphasis = 'primary'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'emphasis' => $emphasis,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function isDraft(array $payload): bool
    {
        return self::normalize($payload['entry_state'] ?? null) === ClinicalOrderEntryState::DRAFT->value;
    }

    private static function normalize(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private static function parseDate(mixed $value): ?int
    {
        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        $timestamp = strtotime($stringValue);

        return $timestamp === false ? null : $timestamp;
    }

    private static function isWithinDays(mixed $value, int $days): bool
    {
        $timestamp = self::parseDate($value);
        if ($timestamp === null) {
            return false;
        }

        $age = time() - $timestamp;
        if ($age < 0) {
            return false;
        }

        return $age <= $days * 24 * 60 * 60;
    }

    private static function extractLaboratoryResultFlag(mixed $resultSummary): ?string
    {
        $summary = self::normalize($resultSummary);
        if ($summary === '') {
            return null;
        }

        if (preg_match('/result flag:\s*([a-z _-]+)/i', (string) $resultSummary, $matches) !== 1) {
            return null;
        }

        $token = str_replace(' ', '_', self::normalize($matches[1] ?? null));

        if (str_contains($token, 'critical')) {
            return 'critical';
        }

        if (str_contains($token, 'abnormal')) {
            return 'abnormal';
        }

        if (str_contains($token, 'inconclusive')) {
            return 'inconclusive';
        }

        if (str_contains($token, 'normal')) {
            return 'normal';
        }

        return null;
    }

    private static function radiologyReportSignal(mixed $reportSummary): ?string
    {
        $summary = self::normalize($reportSummary);
        if ($summary === '') {
            return null;
        }

        if (
            str_contains($summary, 'critical finding')
            || str_contains($summary, 'urgent review')
            || str_contains($summary, 'immediate clinical action')
            || str_contains($summary, 'escalate')
        ) {
            return 'critical';
        }

        if (
            str_contains($summary, 'no acute abnormality')
            || str_contains($summary, 'no acute ')
            || str_contains($summary, 'normal study')
            || str_contains($summary, 'unremarkable')
        ) {
            return 'normal';
        }

        return 'abnormal';
    }

    private static function laboratoryPriorityRank(
        string $status,
        bool $hasCriticalResult,
        bool $hasAbnormalResult,
    ): int {
        if ($hasCriticalResult) {
            return 500;
        }

        if ($hasAbnormalResult) {
            return 450;
        }

        return match ($status) {
            'in_progress' => 400,
            'collected' => 380,
            'ordered' => 360,
            'completed' => 300,
            default => 0,
        };
    }

    private static function radiologyPriorityRank(
        string $status,
        bool $hasCriticalReport,
        bool $hasAbnormalReport,
    ): int {
        if ($hasCriticalReport) {
            return 500;
        }

        if ($hasAbnormalReport) {
            return 450;
        }

        return match ($status) {
            'in_progress' => 400,
            'scheduled' => 380,
            'ordered' => 360,
            'completed' => 300,
            default => 0,
        };
    }

    private static function pharmacyPriorityRank(
        string $status,
        bool $awaitingVerification,
        bool $awaitingReconciliation,
        bool $hasPolicyIssue,
    ): int {
        if ($awaitingVerification) {
            return 540;
        }

        if ($awaitingReconciliation) {
            return 520;
        }

        if ($hasPolicyIssue) {
            return 500;
        }

        return match ($status) {
            'partially_dispensed' => 490,
            'in_preparation' => 470,
            'pending' => 450,
            'dispensed' => 320,
            default => 0,
        };
    }

    private static function theatrePriorityRank(string $status): int
    {
        return match ($status) {
            'in_progress' => 500,
            'in_preop' => 450,
            'planned' => 400,
            'completed' => 300,
            default => 0,
        };
    }

    /**
     * @return array{key: string, label: string, emphasis: string}|null
     */
    private static function laboratoryNextAction(
        string $status,
        bool $hasCriticalResult,
        bool $hasAbnormalResult,
        bool $isPending,
        bool $isRecentlyCompleted,
    ): ?array {
        if ($hasCriticalResult) {
            return self::nextAction('review_result', 'Review critical result', 'warning');
        }

        if ($hasAbnormalResult) {
            return self::nextAction('review_result', 'Review abnormal result');
        }

        if ($isPending) {
            return self::nextAction('review_order', match ($status) {
                'in_progress' => 'Complete result',
                'collected' => 'Start processing',
                default => 'Collect specimen',
            });
        }

        if ($status === 'completed' || $isRecentlyCompleted) {
            return self::nextAction('review_result', 'Review result', 'secondary');
        }

        return null;
    }

    private static function laboratoryWorkflowHint(
        string $status,
        bool $hasCriticalResult,
        bool $hasAbnormalResult,
        bool $isPending,
        bool $isRecentlyCompleted,
    ): ?string {
        if ($hasCriticalResult) {
            return 'Critical laboratory result needs immediate review.';
        }

        if ($hasAbnormalResult) {
            return 'Abnormal laboratory result should be reviewed before follow-up.';
        }

        if ($isPending) {
            return match ($status) {
                'in_progress' => 'Laboratory processing is still in progress.',
                'collected' => 'Specimen has been collected and is waiting for processing.',
                default => 'Specimen is still waiting for collection.',
            };
        }

        if ($status === 'completed' || $isRecentlyCompleted) {
            return 'Completed result remains part of current care context.';
        }

        return null;
    }

    /**
     * @return array{key: string, label: string, emphasis: string}|null
     */
    private static function radiologyNextAction(
        string $status,
        bool $hasCriticalReport,
        bool $hasAbnormalReport,
        bool $isPending,
        bool $isRecentlyCompleted,
    ): ?array {
        if ($hasCriticalReport) {
            return self::nextAction('review_report', 'Review critical report', 'warning');
        }

        if ($hasAbnormalReport) {
            return self::nextAction('review_report', 'Review abnormal report');
        }

        if ($isPending) {
            return self::nextAction('review_order', match ($status) {
                'in_progress' => 'Complete report',
                'scheduled' => 'Start imaging',
                default => 'Schedule imaging',
            });
        }

        if ($status === 'completed' || $isRecentlyCompleted) {
            return self::nextAction('review_report', 'Review report', 'secondary');
        }

        return null;
    }

    private static function radiologyWorkflowHint(
        string $status,
        bool $hasCriticalReport,
        bool $hasAbnormalReport,
        bool $isPending,
        bool $isRecentlyCompleted,
    ): ?string {
        if ($hasCriticalReport) {
            return 'Critical imaging report needs immediate review.';
        }

        if ($hasAbnormalReport) {
            return 'Abnormal imaging report should be reviewed before follow-up.';
        }

        if ($isPending) {
            return match ($status) {
                'in_progress' => 'Imaging study is still in progress.',
                'scheduled' => 'Imaging is scheduled and awaiting completion.',
                default => 'Imaging order is still waiting to be scheduled.',
            };
        }

        if ($status === 'completed' || $isRecentlyCompleted) {
            return 'Completed imaging report remains part of current care context.';
        }

        return null;
    }

    /**
     * @return array{key: string, label: string, emphasis: string}|null
     */
    private static function pharmacyNextAction(
        string $status,
        string $reconciliationStatus,
        bool $awaitingVerification,
        bool $awaitingReconciliation,
        bool $hasPolicyIssue,
        bool $wasRecentlyDispensed,
    ): ?array {
        if ($awaitingVerification) {
            return self::nextAction('verify_dispense', 'Verify dispense');
        }

        if ($awaitingReconciliation) {
            return self::nextAction(
                $reconciliationStatus === 'exception'
                    ? 'resolve_reconciliation'
                    : 'review_reconciliation',
                $reconciliationStatus === 'exception'
                    ? 'Resolve reconciliation'
                    : 'Review reconciliation',
                $reconciliationStatus === 'exception' ? 'warning' : 'primary',
            );
        }

        if ($hasPolicyIssue) {
            return self::nextAction('review_policy', 'Review policy', 'warning');
        }

        return match ($status) {
            'pending' => self::nextAction('start_preparation', 'Start preparation'),
            'in_preparation' => self::nextAction('record_dispense', 'Record dispense'),
            'partially_dispensed' => self::nextAction('complete_dispense', 'Complete dispense'),
            'dispensed' => $wasRecentlyDispensed
                ? self::nextAction('open_order', 'Open order', 'secondary')
                : null,
            default => null,
        };
    }

    private static function pharmacyWorkflowHint(
        string $status,
        string $reconciliationStatus,
        bool $awaitingVerification,
        bool $awaitingReconciliation,
        bool $hasPolicyIssue,
        bool $wasRecentlyDispensed,
    ): ?string {
        if ($awaitingVerification) {
            return 'Dispense verification is still required before pharmacy work can close.';
        }

        if ($awaitingReconciliation) {
            return $reconciliationStatus === 'exception'
                ? 'Reconciliation exception still needs follow-up.'
                : 'Medication reconciliation is still pending.';
        }

        if ($hasPolicyIssue) {
            return 'Policy review is still required before dispense work continues.';
        }

        return match ($status) {
            'pending' => 'Medication is ready for preparation review.',
            'in_preparation' => 'Medication is being prepared for dispense.',
            'partially_dispensed' => 'Partial dispense was recorded and the remaining quantity still needs follow-up.',
            'dispensed' => $wasRecentlyDispensed
                ? 'Recently dispensed medication remains part of current care context.'
                : null,
            default => null,
        };
    }

    /**
     * @return array{key: string, label: string, emphasis: string}|null
     */
    private static function theatreNextAction(
        string $status,
        bool $isInProgress,
        bool $isUpcoming,
        bool $wasRecentlyCompleted,
    ): ?array {
        if ($isInProgress) {
            return self::nextAction('review_case', 'Complete procedure');
        }

        if ($isUpcoming) {
            return self::nextAction(
                'review_case',
                $status === 'in_preop' ? 'Start procedure' : 'Move to Pre-op',
            );
        }

        if ($status === 'completed' || $wasRecentlyCompleted) {
            return self::nextAction('review_case', 'Review completed case', 'secondary');
        }

        return null;
    }

    private static function theatreWorkflowHint(
        string $status,
        bool $isInProgress,
        bool $isUpcoming,
        bool $wasRecentlyCompleted,
    ): ?string {
        if ($isInProgress) {
            return 'Procedure is in progress and still needs active monitoring.';
        }

        if ($isUpcoming) {
            return $status === 'in_preop'
                ? 'Pre-op readiness still needs review.'
                : 'Scheduled procedure is still upcoming.';
        }

        if ($status === 'completed' || $wasRecentlyCompleted) {
            return 'Recently completed procedure remains part of current care context.';
        }

        return null;
    }
}
