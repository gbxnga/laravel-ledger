<?php /** @noinspection PhpParamsInspection */

namespace Abivia\Ledger\Tests\Feature;

use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\LedgerBalance;
use Abivia\Ledger\Tests\TestCaseWithMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class JournalEntryPerformanceTest extends TestCaseWithMigrations
{
    use CommonChecks;
    use CreateLedgerTrait;
    use RefreshDatabase;

    protected function addAccount(string $code, string $parentCode, bool $debit): void
    {
        $requestData = [
            'code' => $code,
            'debit' => $debit,
            'credit' => !$debit,
            'parent' => [
                'code' => $parentCode,
            ],
            'names' => [
                [
                    'name' => "Account $code",
                    'language' => 'en',
                ]
            ]
        ];
        $response = $this->json(
            'post', 'api/ledger/account/add', $requestData
        );
        $this->isSuccessful($response, 'account');
    }

    /**
     * @param array<string, bool> $usedCodes
     * @return string[]
     */
    private function reserveCodes(array &$usedCodes, int $startCode, int $count): array
    {
        $codes = [];
        $candidate = $startCode;
        while (count($codes) < $count) {
            $code = (string) $candidate;
            if (!isset($usedCodes[$code])) {
                $usedCodes[$code] = true;
                $codes[] = $code;
            }
            ++$candidate;
        }

        return $codes;
    }

    /**
     * Create sales entries and return their id/revision data for delete calls.
     *
     * @return array<int, array{id: int, revision: string}>
     */
    private function createSalesEntries(int $entryCount): array
    {
        $entries = [];
        for ($index = 0; $index < $entryCount; ++$index) {
            $amount = number_format($index + 1, 2, '.', '');
            $requestData = [
                'currency' => 'CAD',
                'description' => "Delete batch source $index",
                'date' => '2021-11-12',
                'details' => [
                    ['code' => '1310', 'debit' => $amount],
                    ['code' => '4110', 'credit' => $amount],
                ],
            ];
            $response = $this->json(
                'post',
                'api/ledger/entry/add',
                $requestData
            );
            $actual = $this->isSuccessful($response);
            $entries[] = [
                'id' => $actual->entry->id,
                'revision' => $actual->entry->revision,
            ];
        }

        return $entries;
    }

    public function setUp(): void
    {
        parent::setUp();
        LedgerAccount::resetRules();
        self::$expectContent = 'entry';
    }

    public function testAddUsesBulkDetailAndBalanceQueries()
    {
        $response = $this->createLedger(['template'], ['template' => 'manufacturer_1.0']);
        $this->isSuccessful($response, 'ledger');

        $debitParent = LedgerAccount::where('category', true)
            ->where('debit', true)
            ->where('code', '<>', '')
            ->first();
        $creditParent = LedgerAccount::where('category', true)
            ->where('credit', true)
            ->where('code', '<>', '')
            ->first();
        $this->assertNotNull($debitParent);
        $this->assertNotNull($creditParent);

        $pairCount = 20;
        $usedCodes = array_fill_keys(LedgerAccount::pluck('code')->all(), true);
        $debitCodes = $this->reserveCodes($usedCodes, 8000, $pairCount);
        $creditCodes = $this->reserveCodes($usedCodes, 9000, $pairCount);
        foreach ($debitCodes as $code) {
            $this->addAccount($code, $debitParent->code, true);
        }
        foreach ($creditCodes as $code) {
            $this->addAccount($code, $creditParent->code, false);
        }

        $details = [];
        for ($index = 0; $index < $pairCount; ++$index) {
            $amount = number_format($index + 1, 2, '.', '');
            $details[] = [
                'code' => $debitCodes[$index],
                'debit' => $amount,
            ];
            $details[] = [
                'code' => $creditCodes[$index],
                'credit' => $amount,
            ];
        }

        $requestData = [
            'currency' => 'CAD',
            'clearing' => true,
            'description' => 'High-volume query count check',
            'date' => '2021-11-12',
            'details' => $details,
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->json(
            'post', 'api/ledger/entry/add', $requestData
        );
        DB::disableQueryLog();

        $actual = $this->isSuccessful($response);
        $queries = DB::getQueryLog();
        $journalDetailInserts = 0;
        $ledgerBalanceSelects = 0;
        $ledgerBalanceInserts = 0;
        $ledgerBalanceUpdates = 0;
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $prefix = ltrim($sql);
            if (str_starts_with($prefix, 'insert') && preg_match('/\bjournal_details\b/', $sql)) {
                ++$journalDetailInserts;
            }
            if (str_starts_with($prefix, 'select') && preg_match('/\bledger_balances\b/', $sql)) {
                ++$ledgerBalanceSelects;
            }
            if (str_starts_with($prefix, 'insert') && preg_match('/\bledger_balances\b/', $sql)) {
                ++$ledgerBalanceInserts;
            }
            if (str_starts_with($prefix, 'update') && preg_match('/\bledger_balances\b/', $sql)) {
                ++$ledgerBalanceUpdates;
            }
        }

        $detailChunk = max(1, (int) config('ledger.performance.entry.detail_chunk', 1000));
        $balanceChunk = max(1, (int) config('ledger.performance.entry.balance_chunk', 500));
        $detailRows = $pairCount * 2;
        $expectedBalancePasses = (int) ceil($detailRows / $balanceChunk);
        $this->assertSame((int) ceil($detailRows / $detailChunk), $journalDetailInserts);
        $this->assertSame($expectedBalancePasses, $ledgerBalanceSelects);
        $this->assertSame($expectedBalancePasses * 2, $ledgerBalanceInserts);
        $this->assertSame(0, $ledgerBalanceUpdates);
        $this->assertEquals(
            $pairCount * 2,
            DB::table('journal_details')->where('journalEntryId', $actual->entry->id)->count()
        );
    }

    public function testBatchCoalescesConsecutiveEntryAdds()
    {
        $response = $this->createLedger(['template'], ['template' => 'manufacturer_1.0']);
        $this->isSuccessful($response, 'ledger');

        $requestData = [
            'transaction' => true,
            'list' => [],
        ];
        for ($index = 0; $index < 5; ++$index) {
            $amount = number_format($index + 1, 2, '.', '');
            $requestData['list'][] = [
                'method' => 'entry/add',
                'payload' => [
                    'currency' => 'CAD',
                    'description' => "Batch entry $index",
                    'date' => '2021-11-12',
                    'details' => [
                        ['code' => '1310', 'debit' => $amount],
                        ['code' => '4110', 'credit' => $amount],
                    ],
                ],
            ];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->json(
            'post', 'api/ledger/batch', $requestData
        );
        DB::disableQueryLog();

        $actual = $this->isSuccessful($response, 'batch');
        $this->assertCount(5, $actual->batch);
        $queries = DB::getQueryLog();
        $journalDetailInserts = 0;
        $ledgerBalanceSelects = 0;
        $ledgerBalanceInserts = 0;
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $prefix = ltrim($sql);
            if (str_starts_with($prefix, 'insert') && preg_match('/\bjournal_details\b/', $sql)) {
                ++$journalDetailInserts;
            }
            if (str_starts_with($prefix, 'select') && preg_match('/\bledger_balances\b/', $sql)) {
                ++$ledgerBalanceSelects;
            }
            if (str_starts_with($prefix, 'insert') && preg_match('/\bledger_balances\b/', $sql)) {
                ++$ledgerBalanceInserts;
            }
        }

        $detailChunk = max(1, (int) config('ledger.performance.entry.detail_chunk', 1000));
        $balanceChunk = max(1, (int) config('ledger.performance.entry.balance_chunk', 500));
        $detailRows = 5 * 2;
        $expectedBalancePasses = (int) ceil(2 / $balanceChunk);
        $this->assertSame((int) ceil($detailRows / $detailChunk), $journalDetailInserts);
        $this->assertSame($expectedBalancePasses, $ledgerBalanceSelects);
        $this->assertSame($expectedBalancePasses * 2, $ledgerBalanceInserts);
    }

    public function testBatchCoalescesConsecutiveEntryDeletes()
    {
        $response = $this->createLedger(['template'], ['template' => 'manufacturer_1.0']);
        $this->isSuccessful($response, 'ledger');
        $createdEntries = $this->createSalesEntries(5);

        $requestData = [
            'transaction' => true,
            'list' => [],
        ];
        foreach ($createdEntries as $createdEntry) {
            $requestData['list'][] = [
                'method' => 'entry/delete',
                'payload' => [
                    'id' => $createdEntry['id'],
                    'revision' => $createdEntry['revision'],
                ],
            ];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->json(
            'post', 'api/ledger/batch', $requestData
        );
        DB::disableQueryLog();

        $actual = $this->isSuccessful($response, 'batch');
        $this->assertCount(5, $actual->batch);
        $queries = DB::getQueryLog();
        $journalDetailSelects = 0;
        $journalDetailDeletes = 0;
        $journalEntryDeletes = 0;
        $ledgerBalanceSelects = 0;
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $prefix = ltrim($sql);
            if (str_starts_with($prefix, 'select') && preg_match('/\bjournal_details\b/', $sql)) {
                ++$journalDetailSelects;
            }
            if (str_starts_with($prefix, 'delete') && preg_match('/\bjournal_details\b/', $sql)) {
                ++$journalDetailDeletes;
            }
            if (str_starts_with($prefix, 'delete') && preg_match('/\bjournal_entries\b/', $sql)) {
                ++$journalEntryDeletes;
            }
            if (str_starts_with($prefix, 'select') && preg_match('/\bledger_balances\b/', $sql)) {
                ++$ledgerBalanceSelects;
            }
        }

        $entryChunkCount = (int) ceil(
            count($createdEntries) / max(1, (int) config('ledger.performance.entry.detail_chunk', 1000))
        );
        $balanceChunk = max(1, (int) config('ledger.performance.entry.balance_chunk', 500));
        $expectedBalancePasses = (int) ceil(2 / $balanceChunk);
        $this->assertSame($entryChunkCount, $journalDetailSelects);
        $this->assertSame($entryChunkCount, $journalDetailDeletes);
        $this->assertSame($entryChunkCount, $journalEntryDeletes);
        $this->assertSame($expectedBalancePasses, $ledgerBalanceSelects);

        $accounts = LedgerAccount::whereIn('code', ['1310', '4110'])
            ->pluck('ledgerUuid', 'code');
        $this->assertCount(2, $accounts);
        foreach (['1310', '4110'] as $code) {
            $ledgerBalance = LedgerBalance::where('ledgerUuid', $accounts[$code])
                ->where('currency', 'CAD')
                ->first();
            $this->assertNotNull($ledgerBalance);
            $this->assertEquals('0.00', $ledgerBalance->balance, "Code $code");
        }
    }

    public function testBatchCoalesceCanBeDisabled()
    {
        config(['ledger.performance.batch.coalesce_entry_add' => false]);
        $response = $this->createLedger(['template'], ['template' => 'manufacturer_1.0']);
        $this->isSuccessful($response, 'ledger');

        $requestData = [
            'transaction' => true,
            'list' => [],
        ];
        for ($index = 0; $index < 5; ++$index) {
            $amount = number_format($index + 1, 2, '.', '');
            $requestData['list'][] = [
                'method' => 'entry/add',
                'payload' => [
                    'currency' => 'CAD',
                    'description' => "Batch entry $index",
                    'date' => '2021-11-12',
                    'details' => [
                        ['code' => '1310', 'debit' => $amount],
                        ['code' => '4110', 'credit' => $amount],
                    ],
                ],
            ];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->json(
            'post', 'api/ledger/batch', $requestData
        );
        DB::disableQueryLog();

        $actual = $this->isSuccessful($response, 'batch');
        $this->assertCount(5, $actual->batch);
        $queries = DB::getQueryLog();
        $journalDetailInserts = 0;
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            if (str_starts_with(ltrim($sql), 'insert') && preg_match('/\bjournal_details\b/', $sql)) {
                ++$journalDetailInserts;
            }
        }
        $this->assertSame(5, $journalDetailInserts);
    }

    public function testBatchDeleteCoalesceCanBeDisabled()
    {
        config(['ledger.performance.batch.coalesce_entry_delete' => false]);
        $response = $this->createLedger(['template'], ['template' => 'manufacturer_1.0']);
        $this->isSuccessful($response, 'ledger');
        $createdEntries = $this->createSalesEntries(5);

        $requestData = [
            'transaction' => true,
            'list' => [],
        ];
        foreach ($createdEntries as $createdEntry) {
            $requestData['list'][] = [
                'method' => 'entry/delete',
                'payload' => [
                    'id' => $createdEntry['id'],
                    'revision' => $createdEntry['revision'],
                ],
            ];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->json(
            'post', 'api/ledger/batch', $requestData
        );
        DB::disableQueryLog();

        $actual = $this->isSuccessful($response, 'batch');
        $this->assertCount(5, $actual->batch);
        $queries = DB::getQueryLog();
        $journalDetailDeletes = 0;
        $journalEntryDeletes = 0;
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $prefix = ltrim($sql);
            if (str_starts_with($prefix, 'delete') && preg_match('/\bjournal_details\b/', $sql)) {
                ++$journalDetailDeletes;
            }
            if (str_starts_with($prefix, 'delete') && preg_match('/\bjournal_entries\b/', $sql)) {
                ++$journalEntryDeletes;
            }
        }
        $this->assertSame(5, $journalDetailDeletes);
        $this->assertSame(5, $journalEntryDeletes);
    }

    public function testBatchTimingBenchmark()
    {
        $response = $this->createLedger(['template'], ['template' => 'manufacturer_1.0']);
        $this->isSuccessful($response, 'ledger');

        $entryCount = 100;
        $requestData = [
            'transaction' => true,
            'list' => [],
        ];
        for ($index = 0; $index < $entryCount; ++$index) {
            $amount = number_format(($index % 10) + 1, 2, '.', '');
            $requestData['list'][] = [
                'method' => 'entry/add',
                'payload' => [
                    'currency' => 'CAD',
                    'description' => "Benchmark entry $index",
                    'date' => '2021-11-12',
                    'details' => [
                        ['code' => '1310', 'debit' => $amount],
                        ['code' => '4110', 'credit' => $amount],
                    ],
                ],
            ];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $response = $this->json(
            'post', 'api/ledger/batch', $requestData
        );
        $elapsedMs = (microtime(true) - $start) * 1000;
        DB::disableQueryLog();

        $actual = $this->isSuccessful($response, 'batch');
        $this->assertCount($entryCount, $actual->batch);
        $queries = DB::getQueryLog();
        $journalDetailInserts = 0;
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            if (str_starts_with(ltrim($sql), 'insert') && preg_match('/\bjournal_details\b/', $sql)) {
                ++$journalDetailInserts;
            }
        }
        $this->assertGreaterThan(0, $elapsedMs);
        $detailChunk = max(1, (int) config('ledger.performance.entry.detail_chunk', 1000));
        $this->assertSame((int) ceil(($entryCount * 2) / $detailChunk), $journalDetailInserts);
        fwrite(
            STDERR,
            sprintf(
                "Benchmark: %d entries posted in %.2fms (%0.2f entries/sec)\n",
                $entryCount,
                $elapsedMs,
                ($entryCount * 1000) / $elapsedMs
            )
        );
    }

}
