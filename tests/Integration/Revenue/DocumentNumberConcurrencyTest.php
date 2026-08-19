<?php

use App\Modules\Revenue\Domain\Services\DocumentNumberAllocatorInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The allocator under genuine parallelism.
 *
 * This is the test the whole harness decision was made for. The retired suite
 * ran on SQLite :memory:, where every connection gets its own private
 * database — two cashiers cannot be simulated at all, and a concurrency test
 * would pass while testing nothing. Running on PostgreSQL makes
 * SELECT ... FOR UPDATE real, and makes this assertion mean something.
 *
 * These tests fork actual processes rather than opening extra connections in
 * one process, because that is the shape of the real failure: two cashiers, at
 * two terminals, pressing Take payment at the same moment.
 *
 * They live in the Integration suite rather than Feature because they must run
 * against committed data: RefreshDatabase wraps each Feature test in a
 * transaction, and a forked child on its own connection cannot see its
 * parent's uncommitted work. They clean up after themselves instead.
 */
afterEach(function (): void {
    DB::table('financial_document_sequences')->where('prefix', 'RCP')->delete();
});

it('issues a gapless, duplicate-free sequence to concurrent allocators', function (): void {
    $workers = 8;
    $perWorker = 10;
    $facilityId = (string) Str::uuid();

    $socketPairs = [];
    $pids = [];

    // Fork with no connection open. A child inherits the parent's PDO socket
    // descriptor, and several processes interleaving statements on one socket
    // is what produces "current transaction is aborted" rather than any
    // genuine lock contention. Dropping the connection first means every child
    // dials its own.
    DB::disconnect();

    for ($worker = 0; $worker < $workers; $worker++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Unable to fork a worker.');
        }

        if ($pid === 0) {
            // Child: its own connection, its own transactions.
            fclose($pair[0]);
            DB::purge();
            DB::reconnect();

            try {
                $issued = [];
                for ($i = 0; $i < $perWorker; $i++) {
                    $issued[] = DB::transaction(
                        fn (): string => app(DocumentNumberAllocatorInterface::class)
                            ->allocate('receipt', null, $facilityId),
                    );
                }
                fwrite($pair[1], json_encode(['ok' => true, 'numbers' => $issued]));
            } catch (Throwable $e) {
                fwrite($pair[1], json_encode(['ok' => false, 'error' => $e->getMessage()]));
            }

            fclose($pair[1]);
            exit(0);
        }

        fclose($pair[1]);
        $socketPairs[] = $pair[0];
        $pids[] = $pid;
    }

    $numbers = [];
    foreach ($socketPairs as $index => $socket) {
        $payload = stream_get_contents($socket);
        fclose($socket);
        pcntl_waitpid($pids[$index], $status);

        $result = json_decode($payload, true);
        expect($result['ok'] ?? false)
            ->toBeTrue('worker '.$index.' failed: '.($result['error'] ?? 'no response'));

        $numbers = array_merge($numbers, $result['numbers']);
    }

    $expectedCount = $workers * $perWorker;

    // No duplicates: no two receipts can carry the same number.
    expect($numbers)->toHaveCount($expectedCount)
        ->and(array_unique($numbers))->toHaveCount($expectedCount);

    // No gaps: an auditor reads a missing number as a destroyed receipt.
    $sequence = array_map(
        static fn (string $number): int => (int) substr($number, strrpos($number, '-') + 1),
        $numbers,
    );
    sort($sequence);

    expect($sequence)->toBe(range(1, $expectedCount));
});

it('releases the number when the transaction writing the document rolls back', function (): void {
    $facilityId = (string) Str::uuid();
    $allocator = app(DocumentNumberAllocatorInterface::class);

    $first = DB::transaction(fn (): string => $allocator->allocate('receipt', null, $facilityId));

    try {
        DB::transaction(function () use ($allocator, $facilityId): void {
            $allocator->allocate('receipt', null, $facilityId);
            throw new RuntimeException('payment failed after the number was taken');
        });
    } catch (RuntimeException) {
        // expected
    }

    $third = DB::transaction(fn (): string => $allocator->allocate('receipt', null, $facilityId));

    $year = now()->format('Y');

    // The rolled-back allocation must not burn a number, or every failed
    // payment would leave a hole in the receipt book.
    expect($first)->toBe("RCP-{$year}-000001")
        ->and($third)->toBe("RCP-{$year}-000002");
});

it('refuses to allocate outside a transaction', function (): void {
    app(DocumentNumberAllocatorInterface::class)->allocate('receipt', null, (string) Str::uuid());
})->throws(RuntimeException::class, 'inside the transaction');

it('keeps each facility on its own receipt book', function (): void {
    $allocator = app(DocumentNumberAllocatorInterface::class);
    $facilityA = (string) Str::uuid();
    $facilityB = (string) Str::uuid();

    $a1 = DB::transaction(fn (): string => $allocator->allocate('receipt', null, $facilityA));
    $b1 = DB::transaction(fn (): string => $allocator->allocate('receipt', null, $facilityB));
    $a2 = DB::transaction(fn (): string => $allocator->allocate('receipt', null, $facilityA));

    $year = now()->format('Y');

    expect($a1)->toBe("RCP-{$year}-000001")
        ->and($b1)->toBe("RCP-{$year}-000001")
        ->and($a2)->toBe("RCP-{$year}-000002");
});
