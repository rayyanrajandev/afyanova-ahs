<?php

namespace App\Modules\Revenue\Infrastructure\Services;

use App\Modules\Revenue\Domain\Services\DocumentNumberAllocatorInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gapless, monotonic document numbers, one book per facility per period.
 *
 * The clinical modules generate identifiers as PREFIX + date + 6 random
 * characters and retry on collision. That is fine when a number only has to be
 * unique. A receipt number carries a stronger claim: an auditor reads a gap as
 * a destroyed receipt, so the sequence must have no holes and must never issue
 * the same number twice, including when two cashiers press Take payment in the
 * same millisecond.
 *
 * The counter row is therefore locked with SELECT ... FOR UPDATE, which
 * serialises concurrent allocators on that one row and nothing else. This is
 * the reason the test suite runs on PostgreSQL: SQLite's :memory: driver gives
 * every connection its own database, so a concurrency test against it would
 * pass without ever testing anything.
 */
class DocumentNumberAllocator implements DocumentNumberAllocatorInterface
{
    /**
     * Prefix and reset window per document type.
     *
     * 'annual' resets the counter each calendar year — what a Tanzanian
     * facility's auditors expect of a receipt book, and what keeps numbers
     * short enough to read back over a counter.
     */
    private const BOOKS = [
        'service_charge' => ['prefix' => 'CHG', 'period' => 'annual'],
        'payment' => ['prefix' => 'PMT', 'period' => 'annual'],
        'receipt' => ['prefix' => 'RCP', 'period' => 'annual'],
        'refund' => ['prefix' => 'REF', 'period' => 'annual'],
        'cashier_session' => ['prefix' => 'DRW', 'period' => 'daily'],
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function allocate(string $documentType, ?string $tenantId, ?string $facilityId): string
    {
        $book = self::BOOKS[$documentType] ?? throw new RuntimeException(
            sprintf('No document sequence is defined for "%s".', $documentType),
        );

        if (! $this->connection->transactionLevel()) {
            throw new RuntimeException(
                'Document numbers must be allocated inside the transaction that writes the '
                .'document, so a rollback releases the number instead of leaving a gap.',
            );
        }

        $periodKey = $this->periodKey($book['period']);

        $row = $this->connection->table('financial_document_sequences')
            ->where('facility_id', $facilityId)
            ->where('document_type', $documentType)
            ->where('period_key', $periodKey)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            // First document of this book. Two allocators can reach this point
            // at once — the row does not exist yet, so there is nothing for
            // either to lock — and exactly one insert must win.
            //
            // insertOrIgnore, not a try/catch around insert: on PostgreSQL a
            // constraint violation aborts the entire transaction, so catching
            // the duplicate-key error would leave every subsequent statement
            // failing with "current transaction is aborted". ON CONFLICT DO
            // NOTHING lets the loser continue and simply read the winner's row.
            $this->connection->table('financial_document_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'document_type' => $documentType,
                'period_key' => $periodKey,
                'prefix' => $book['prefix'],
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = $this->connection->table('financial_document_sequences')
                ->where('facility_id', $facilityId)
                ->where('document_type', $documentType)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException('Unable to open a document sequence book.');
            }
        }

        $value = (int) $row->next_value;

        $this->connection->table('financial_document_sequences')
            ->where('id', $row->id)
            ->update([
                'next_value' => $value + 1,
                'updated_at' => now(),
            ]);

        return sprintf('%s-%s-%06d', $row->prefix, $periodKey, $value);
    }

    private function periodKey(string $period): string
    {
        return match ($period) {
            'annual' => now()->format('Y'),
            'monthly' => now()->format('Ym'),
            'daily' => now()->format('Ymd'),
            default => 'all-time',
        };
    }
}
