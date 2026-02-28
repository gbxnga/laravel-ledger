<?php /** @noinspection PhpParamsInspection */

namespace Abivia\Ledger\Tests\Feature;

use Abivia\Ledger\Http\Controllers\JournalEntryController;
use Abivia\Ledger\Messages\Entry;
use Abivia\Ledger\Messages\Message;
use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\LedgerBalance;
use Abivia\Ledger\Models\LedgerDomain;
use Abivia\Ledger\Tests\TestCaseWithMigrations;
use Illuminate\Support\Facades\DB;

class JournalEntryConcurrencyStressTest extends TestCaseWithMigrations
{
    use CommonChecks;
    use CreateLedgerTrait;

    public function setUp(): void
    {
        parent::setUp();
        LedgerAccount::resetRules();
        self::$expectContent = 'entry';
    }

    public function testConcurrentPostingStress()
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for concurrency stress test.');
        }
        if (config('database.connections.sqlite.database') === ':memory:') {
            $this->markTestSkipped(
                'Concurrent multi-process stress test requires a shared (non in-memory) database.'
            );
        }

        $response = $this->createLedger(['template'], ['template' => 'manufacturer_1.0']);
        $this->isSuccessful($response, 'ledger');
        DB::disconnect();
        DB::reconnect();

        $workers = 4;
        $entriesPerWorker = 25;
        $children = [];
        for ($worker = 0; $worker < $workers; ++$worker) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    DB::disconnect();
                    DB::reconnect();
                    if (config('database.default') === 'sqlite') {
                        DB::statement('PRAGMA busy_timeout = 15000');
                    }
                    $controller = new JournalEntryController();
                    for ($entry = 0; $entry < $entriesPerWorker; ++$entry) {
                        $posted = false;
                        for ($attempt = 0; $attempt < 5; ++$attempt) {
                            try {
                                $message = Entry::fromArray(
                                    [
                                        'currency' => 'CAD',
                                        'description' => "Concurrent worker $worker entry $entry",
                                        'date' => '2021-11-12',
                                        'details' => [
                                            ['code' => '1310', 'debit' => '1.00'],
                                            ['code' => '4110', 'credit' => '1.00'],
                                        ],
                                    ],
                                    Message::OP_ADD
                                );
                                $controller->add($message);
                                $posted = true;
                                break;
                            } catch (\Throwable $exception) {
                                if (
                                    str_contains(
                                        strtolower($exception->getMessage()),
                                        'database is locked'
                                    )
                                ) {
                                    usleep(100000);
                                    continue;
                                }
                                throw $exception;
                            }
                        }
                        if (!$posted) {
                            throw new \RuntimeException('Retry limit exceeded posting concurrent entry.');
                        }
                    }
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $child) {
            $status = 0;
            $wait = pcntl_waitpid($child, $status);
            $this->assertNotSame(-1, $wait);
        }

        $domain = LedgerDomain::where('code', 'CORP')->first();
        $this->assertNotNull($domain);
        $receivables = LedgerAccount::where('code', '1310')->first();
        $sales = LedgerAccount::where('code', '4110')->first();
        $this->assertNotNull($receivables);
        $this->assertNotNull($sales);
        $receivableBalance = LedgerBalance::where([
            ['ledgerUuid', '=', $receivables->ledgerUuid],
            ['domainUuid', '=', $domain->domainUuid],
            ['currency', '=', 'CAD'],
        ])->first();
        $salesBalance = LedgerBalance::where([
            ['ledgerUuid', '=', $sales->ledgerUuid],
            ['domainUuid', '=', $domain->domainUuid],
            ['currency', '=', 'CAD'],
        ])->first();
        $this->assertNotNull($receivableBalance);
        $this->assertNotNull($salesBalance);
        $expect = number_format($workers * $entriesPerWorker, 2, '.', '');
        $this->assertEquals("-$expect", $receivableBalance->balance);
        $this->assertEquals($expect, $salesBalance->balance);
    }
}
