<?php
declare(strict_types=1);

namespace Abivia\Ledger\Messages;

use Abivia\Ledger\Exceptions\Breaker;
use Abivia\Ledger\Helpers\Revision;
use Abivia\Ledger\Http\Controllers\Api\ApiController;
use Abivia\Ledger\Http\Controllers\JournalEntryController;
use Abivia\Ledger\Models\LedgerAccount;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Batch extends Message
{
    /**
     * @var array The messages in the batch
     */
    public array $list;

    private static array $routes = [
        'account' => Account::class,
        'balance' => Balance::class,
        'currency' => Currency::class,
        'currency/query' => CurrencyQuery::class,
        'domain' => Domain::class,
        'domain/query' => DomainQuery::class,
        'entry' => Entry::class,
        'entry/query' => EntryQuery::class,
        'journal' => SubJournal::class,
        'journal/query' => SubJournalQuery::class,
        'reference' => Reference::class,
        'report' => Report::class,
    ];

    /**
     * @var bool Set when this batch is to be processed as a transaction.
     */
    public bool $transaction;

    /**
     * @inheritdoc
     */
    public static function fromArray(array $data, int $opFlags = self::OP_BATCH): self
    {
        if (!($opFlags & self::OP_BATCH)) {
            throw Breaker::withCode(
                Breaker::RULE_VIOLATION,
                [__('Only a batch operation is allowed.')]
            );
        }
        if (self::$inBatch) {
            throw Breaker::withCode(
                Breaker::RULE_VIOLATION,
                [__('Batch operations cannot be nested.')]
            );
        }
        $rules = LedgerAccount::rules();
        $limit = $rules->batch->limit;
        if ($limit === 0) {
            $limit = PHP_INT_MAX;
        }
        self::$inBatch = true;
        $batch = new static();
        $batch->list = [];

        try {
            $batch->transaction = $data['transaction'] ?? true;
            foreach ($data['list'] as $element) {
                // The method is entry_point/operation
                $method = strtolower(($element['method'] ?? ''));
                $route = explode('/', $method . '/');
                $messageClass = self::getMessageClass($method, $route[0]);
                if ($route[0] === '') {
                    throw Breaker::withCode(
                        Breaker::BAD_REQUEST,
                        [__('Empty or missing method.')]
                    );
                }
                if ($route[0] === 'report') {
                    if (!$rules->batch->allowReports) {
                        throw Breaker::withCode(
                            Breaker::BAD_REQUEST,
                            [__('Configuration prohibits reports in a batch.')]
                        );
                    }
                    $subOpFlags = self::OP_ADD;
                } else {
                    $subOpFlags = ApiController::getOpFlags($route[1]);
                }
                /** @var Message $message */
                $message = new $messageClass();
                $batch->list[] = $message->fromArray($element['payload'] ?? [], $subOpFlags);
                if (count($batch->list) > $limit) {
                    throw Breaker::withCode(
                        Breaker::BAD_REQUEST,
                        [__('Too many requests in batch, limit is :limit.', ['limit' => $limit])]
                    );
                }
            }
            $batch->copy($data, $opFlags);
        } finally {
            self::$inBatch = false;
        }

        return $batch;
    }

    /**
     * Get a route's entry point.
     * @param string $method
     * @param string $entry
     * @return string|null
     * @throws Breaker
     */
    private static function getMessageClass(string $method, string $entry): ?string
    {
        $messageClass = self::$routes[$method]
            ?? (self::$routes[$entry] ?? null);
        if ($messageClass === null) {
            throw Breaker::withCode(
                Breaker::BAD_REQUEST,
                [__('Method :method is invalid.', ['method' => $method])]
            );
        }
        return $messageClass;
    }

    private function isBulkAddCandidate(Message $message): bool
    {
        return $message instanceof Entry
            && (($message->getOpFlags() & self::ALL_OPS) === self::OP_ADD);
    }

    private function isBulkDeleteCandidate(Message $message): bool
    {
        return $message instanceof Entry
            && (($message->getOpFlags() & self::ALL_OPS) === self::OP_DELETE);
    }

    private function addCoalescingEnabled(): bool
    {
        return (bool) config('ledger.performance.batch.coalesce_entry_add', true);
    }

    private function deleteCoalescingEnabled(): bool
    {
        return (bool) config('ledger.performance.batch.coalesce_entry_delete', true);
    }

    private function coalescingMinGroup(): int
    {
        return max(2, (int) config('ledger.performance.batch.coalesce_min_group', 2));
    }

    private function performanceMetricsEnabled(): bool
    {
        return (bool) config('ledger.performance.metrics.enabled', false);
    }

    private function logBatchPerformance(array $context): void
    {
        if (!$this->performanceMetricsEnabled()) {
            return;
        }
        Log::channel(config('ledger.log'))
            ->info('ledger.performance.batch', $context);
    }

    /**
     * @throws Exception
     */
    public function run(): array
    {
        try {
            $step = -1;
            $results = ['batch' => []];
            if ($inTransaction = $this->transaction) {
                DB::beginTransaction();
            }
            Revision::startBatch();
            $count = count($this->list);
            $entryController = null;
            for ($step = 0; $step < $count; ++$step) {
                $message = $this->list[$step];
                if (
                    $this->transaction
                    && $this->addCoalescingEnabled()
                    && $this->isBulkAddCandidate($message)
                ) {
                    $bulkMessages = [$message];
                    while (
                        $step + 1 < $count
                        && $this->isBulkAddCandidate($this->list[$step + 1])
                    ) {
                        $bulkMessages[] = $this->list[++$step];
                    }
                    if (count($bulkMessages) >= $this->coalescingMinGroup()) {
                        $startedAt = microtime(true);
                        $entryController ??= new JournalEntryController();
                        $journalEntries = $entryController->addBulk($bulkMessages);
                        foreach ($bulkMessages as $index => $bulkMessage) {
                            $results['batch'][] = [
                                'entry' => $journalEntries[$index]->toResponse(
                                    $bulkMessage->getOpFlags()
                                )
                            ];
                        }
                        $this->logBatchPerformance([
                            'operation' => 'entry_add',
                            'coalesced' => true,
                            'group_size' => count($bulkMessages),
                            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                        ]);
                        continue;
                    }
                    $this->logBatchPerformance([
                        'operation' => 'entry_add',
                        'coalesced' => false,
                        'group_size' => count($bulkMessages),
                        'reason' => 'below_threshold',
                    ]);
                    foreach ($bulkMessages as $singleMessage) {
                        $result = $singleMessage->run();
                        $results['batch'][] = $result;
                        if ($result['errors'] ?? false) {
                            throw Breaker::withCode(Breaker::BATCH_FAILED);
                        }
                    }
                    continue;
                }
                if (
                    $this->transaction
                    && $this->deleteCoalescingEnabled()
                    && $this->isBulkDeleteCandidate($message)
                ) {
                    $bulkMessages = [$message];
                    while (
                        $step + 1 < $count
                        && $this->isBulkDeleteCandidate($this->list[$step + 1])
                    ) {
                        $bulkMessages[] = $this->list[++$step];
                    }
                    if (count($bulkMessages) >= $this->coalescingMinGroup()) {
                        $startedAt = microtime(true);
                        $entryController ??= new JournalEntryController();
                        $entryController->deleteBulk($bulkMessages);
                        foreach ($bulkMessages as $bulkMessage) {
                            $results['batch'][] = ['success' => true];
                        }
                        $this->logBatchPerformance([
                            'operation' => 'entry_delete',
                            'coalesced' => true,
                            'group_size' => count($bulkMessages),
                            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                        ]);
                        continue;
                    }
                    $this->logBatchPerformance([
                        'operation' => 'entry_delete',
                        'coalesced' => false,
                        'group_size' => count($bulkMessages),
                        'reason' => 'below_threshold',
                    ]);
                    foreach ($bulkMessages as $singleMessage) {
                        $result = $singleMessage->run();
                        $results['batch'][] = $result;
                        if ($result['errors'] ?? false) {
                            throw Breaker::withCode(Breaker::BATCH_FAILED);
                        }
                    }
                    continue;
                }
                $result = $message->run();
                $results['batch'][] = $result;
                // Abort if this step failed
                if ($result['errors'] ?? false) {
                    throw Breaker::withCode(Breaker::BATCH_FAILED);
                }
            }
        } catch (Exception $exception) {
            if ($this->transaction) {
                DB::rollBack();
            }
            if ($exception instanceof Breaker) {
                $exception->addError(__("Failed in step :step.", ['step' => $step]));
            }
            throw $exception;
        } finally {
            Revision::endBatch();
        }
        if ($inTransaction) {
            DB::commit();
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function validate(?int $opFlags): self
    {
        return $this;
    }

}
