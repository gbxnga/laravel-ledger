<?php

namespace Abivia\Ledger\Http\Controllers;

use Abivia\Ledger\Exceptions\Breaker;
use Abivia\Ledger\Messages\EntityRef;
use Abivia\Ledger\Messages\Entry;
use Abivia\Ledger\Messages\EntryQuery;
use Abivia\Ledger\Messages\Message;
use Abivia\Ledger\Models\JournalEntry;
use Abivia\Ledger\Models\JournalReference;
use Abivia\Ledger\Models\LedgerAccount;
use Abivia\Ledger\Models\LedgerCurrency;
use Abivia\Ledger\Models\LedgerDomain;
use Abivia\Ledger\Models\SubJournal;
use Abivia\Ledger\Traits\Audited;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JournalEntryController extends Controller
{
    use Audited;

    private const BALANCE_CHUNK_DEFAULT = 500;
    private const DETAIL_CHUNK_DEFAULT = 1000;

    /**
     * @var LedgerCurrency|null The currency for this entry.
     */
    private ?LedgerCurrency $ledgerCurrency;

    /**
     * @var LedgerDomain|null The domain for this entry.
     */
    private ?LedgerDomain $ledgerDomain;

    /**
     * Add an entry to the journal.
     *
     * @param Entry $message
     * @return JournalEntry
     * @throws Breaker
     * @throws Exception
     */
    public function add(Entry $message): JournalEntry
    {
        $inTransaction = false;
        $startedAt = microtime(true);
        // Ensure that the entry is in balance and the contents are valid.
        $this->validateEntry($message, Message::OP_ADD);

        try {
            DB::beginTransaction();
            $inTransaction = true;
            // Store message basic details.
            $journalEntry = new JournalEntry();
            $journalEntry->fillFromMessage($message);
            $journalEntry->save();
            $journalEntry->refresh();
            // Create the detail records
            $stats = $this->addDetails($journalEntry, $message);
            DB::commit();
            $inTransaction = false;
            $this->auditLog($message);
            $this->logPerformanceMetric('entry_add', array_merge(
                $stats,
                [
                    'entries' => 1,
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                ]
            ));
        } catch (Exception $exception) {
            if ($inTransaction) {
                DB::rollBack();
            }
            throw $exception;
        }

        return $journalEntry;
    }

    /**
     * Add multiple journal entries with coalesced detail and balance writes.
     *
     * @param Entry[] $messages
     * @return JournalEntry[]
     * @throws Breaker
     * @throws Exception
     */
    public function addBulk(array $messages): array
    {
        if (count($messages) === 0) {
            return [];
        }
        $startedAt = microtime(true);
        $inTransaction = false;
        $journalEntryIds = [];
        $detailRows = [];
        $groupedBalanceDeltas = [];
        try {
            if (DB::transactionLevel() === 0) {
                DB::beginTransaction();
                $inTransaction = true;
            }
            foreach ($messages as $message) {
                if (!($message instanceof Entry)) {
                    throw new Exception(__('Bulk add expects entry messages.'));
                }
                $this->validateEntry($message, Message::OP_ADD);
                $journalEntry = new JournalEntry();
                $journalEntry->fillFromMessage($message);
                $journalEntry->save();
                $journalEntryIds[] = $journalEntry->journalEntryId;
                $this->appendEntryRowsAndBalanceDeltas(
                    $journalEntry,
                    $message,
                    $this->ledgerDomain->domainUuid,
                    $this->ledgerCurrency->code,
                    $this->ledgerCurrency->decimals,
                    $detailRows,
                    $groupedBalanceDeltas
                );
            }
            $this->insertDetailRows($detailRows);
            $this->applyGroupedBalanceDeltas($groupedBalanceDeltas, true);
            $journalEntries = $this->reloadJournalEntries($journalEntryIds);
            if ($inTransaction) {
                DB::commit();
                $inTransaction = false;
            }
            foreach ($messages as $message) {
                $this->auditLog($message);
            }
            $this->logPerformanceMetric(
                'entry_add_bulk',
                [
                    'entries' => count($messages),
                    'detail_rows' => count($detailRows),
                    'detail_chunks' => $this->detailChunkCount(count($detailRows)),
                    'balance_keys' => $this->groupedBalanceKeyCount($groupedBalanceDeltas),
                    'balance_chunks' => $this->groupedBalanceChunkCount($groupedBalanceDeltas),
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                ]
            );
        } catch (Exception $exception) {
            if ($inTransaction) {
                DB::rollBack();
            }
            throw $exception;
        }

        return $journalEntries;
    }

    /**
     * Write the journal detail records and update balances.
     *
     * @param JournalEntry $journalEntry
     * @param Entry $message
     * @return array<string, int>
     */
    private function addDetails(JournalEntry $journalEntry, Entry $message): array
    {
        $detailRows = [];
        $groupedBalanceDeltas = [];
        $this->appendEntryRowsAndBalanceDeltas(
            $journalEntry,
            $message,
            $this->ledgerDomain->domainUuid,
            $this->ledgerCurrency->code,
            $this->ledgerCurrency->decimals,
            $detailRows,
            $groupedBalanceDeltas
        );
        $detailRowCount = count($detailRows);
        if ($detailRowCount === 0) {
            return [
                'detail_rows' => 0,
                'detail_chunks' => 0,
                'balance_keys' => 0,
                'balance_chunks' => 0,
            ];
        }
        $this->insertDetailRows($detailRows);
        $this->applyGroupedBalanceDeltas(
            $groupedBalanceDeltas,
            true
        );
        return [
            'detail_rows' => $detailRowCount,
            'detail_chunks' => $this->detailChunkCount($detailRowCount),
            'balance_keys' => $this->groupedBalanceKeyCount($groupedBalanceDeltas),
            'balance_chunks' => $this->groupedBalanceChunkCount($groupedBalanceDeltas),
        ];
    }

    /**
     * Append detail rows and aggregate balance deltas for a journal entry.
     *
     * @param JournalEntry $journalEntry
     * @param Entry $message
     * @param string $domainUuid
     * @param string $currency
     * @param int $decimals
     * @param array<int, array<string, mixed>> $detailRows
     * @param array<string, array<string, array{decimals: int, deltas: array<string, string>}>> $groupedBalanceDeltas
     * @return void
     */
    private function appendEntryRowsAndBalanceDeltas(
        JournalEntry $journalEntry,
        Entry $message,
        string $domainUuid,
        string $currency,
        int $decimals,
        array &$detailRows,
        array &$groupedBalanceDeltas
    ): void {
        foreach ($message->details as $detail) {
            $detailRows[] = [
                'journalEntryId' => $journalEntry->journalEntryId,
                'ledgerUuid' => $detail->account->uuid,
                'amount' => $detail->amount,
                'journalReferenceUuid' => $detail->reference->journalReferenceUuid ?? null,
            ];
            if (!isset($groupedBalanceDeltas[$domainUuid][$currency])) {
                $groupedBalanceDeltas[$domainUuid][$currency] = [
                    'decimals' => $decimals,
                    'deltas' => [],
                ];
            }
            $deltas = &$groupedBalanceDeltas[$domainUuid][$currency]['deltas'];
            if (!isset($deltas[$detail->account->uuid])) {
                $deltas[$detail->account->uuid] = '0';
            }
            $deltas[$detail->account->uuid] = bcadd(
                $deltas[$detail->account->uuid],
                $detail->amount,
                $decimals
            );
        }
    }

    /**
     * Apply grouped balance deltas in bulk.
     *
     * @param array<string, array<string, array{decimals: int, deltas: array<string, string>}>> $groupedBalanceDeltas
     * @param bool $allowCreateMissing
     * @return void
     * @throws Exception
     */
    private function applyGroupedBalanceDeltas(
        array $groupedBalanceDeltas,
        bool $allowCreateMissing
    ): void {
        foreach ($groupedBalanceDeltas as $domainUuid => $currencyData) {
            foreach ($currencyData as $currency => $balanceGroup) {
                $this->applyBalanceDeltas(
                    $balanceGroup['deltas'],
                    $domainUuid,
                    $currency,
                    $allowCreateMissing,
                    $balanceGroup['decimals']
                );
            }
        }
    }

    /**
     * Write detail rows in bounded chunks.
     *
     * @param array<int, array<string, mixed>> $detailRows
     * @return void
     */
    private function insertDetailRows(array $detailRows): void
    {
        foreach (array_chunk($detailRows, $this->detailChunkSize()) as $chunk) {
            DB::table('journal_details')->insert($chunk);
        }
    }

    /**
     * Reload journal entries in one query while preserving input order.
     *
     * @param array<int> $journalEntryIds
     * @return array<int, JournalEntry>
     * @throws Exception
     */
    private function reloadJournalEntries(array $journalEntryIds): array
    {
        if (count($journalEntryIds) === 0) {
            return [];
        }
        $entriesById = JournalEntry::whereIn('journalEntryId', $journalEntryIds)
            ->get()
            ->keyBy('journalEntryId');
        $ordered = [];
        foreach ($journalEntryIds as $journalEntryId) {
            /** @var JournalEntry|null $journalEntry */
            $journalEntry = $entriesById->get($journalEntryId);
            if ($journalEntry === null) {
                throw new Exception(
                    __('Journal entry :id could not be reloaded after create.', ['id' => $journalEntryId])
                );
            }
            $ordered[] = $journalEntry;
        }

        return $ordered;
    }

    /**
     * @param array<string, array<string, array{decimals: int, deltas: array<string, string>}>> $groupedBalanceDeltas
     * @return int
     */
    private function groupedBalanceKeyCount(array $groupedBalanceDeltas): int
    {
        $count = 0;
        foreach ($groupedBalanceDeltas as $currencyData) {
            foreach ($currencyData as $balanceGroup) {
                $count += count($balanceGroup['deltas']);
            }
        }

        return $count;
    }

    /**
     * @param array<string, array<string, array{decimals: int, deltas: array<string, string>}>> $groupedBalanceDeltas
     * @return int
     */
    private function groupedBalanceChunkCount(array $groupedBalanceDeltas): int
    {
        $chunks = 0;
        $chunkSize = $this->balanceChunkSize();
        foreach ($groupedBalanceDeltas as $currencyData) {
            foreach ($currencyData as $balanceGroup) {
                $chunks += (int) ceil(count($balanceGroup['deltas']) / $chunkSize);
            }
        }

        return $chunks;
    }

    private function detailChunkCount(int $rowCount): int
    {
        if ($rowCount === 0) {
            return 0;
        }

        return (int) ceil($rowCount / $this->detailChunkSize());
    }

    private function performanceMetricsEnabled(): bool
    {
        return (bool) config('ledger.performance.metrics.enabled', false);
    }

    private function logPerformanceMetric(string $operation, array $context): void
    {
        if (!$this->performanceMetricsEnabled()) {
            return;
        }
        Log::channel(config('ledger.log'))
            ->info('ledger.performance.' . $operation, $context);
    }

    /**
     * Apply net balance deltas by account in a concurrency-safe manner.
     *
     * @param array<string, string> $balanceDeltas
     * @param string $domainUuid
     * @param string $currency
     * @param bool $allowCreateMissing
     * @param int $decimals
     * @return void
     * @throws Exception
     */
    private function applyBalanceDeltas(
        array $balanceDeltas,
        string $domainUuid,
        string $currency,
        bool $allowCreateMissing,
        int $decimals
    ): void {
        if (count($balanceDeltas) === 0) {
            return;
        }
        $ledgerUuids = array_keys($balanceDeltas);
        $timestamp = now()->format('Y-m-d H:i:s.u');
        $zeroBalance = bcadd('0', '0', $decimals);
        if ($allowCreateMissing) {
            $seedRows = [];
            foreach ($ledgerUuids as $ledgerUuid) {
                $seedRows[] = [
                    'ledgerUuid' => $ledgerUuid,
                    'domainUuid' => $domainUuid,
                    'currency' => $currency,
                    'balance' => $zeroBalance,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
            foreach (array_chunk($seedRows, $this->balanceChunkSize()) as $chunk) {
                DB::table('ledger_balances')->insertOrIgnore($chunk);
            }
        }
        $existingBalances = [];
        foreach (array_chunk($ledgerUuids, $this->balanceChunkSize()) as $chunkUuids) {
            $chunkBalances = DB::table('ledger_balances')
                ->where('domainUuid', $domainUuid)
                ->where('currency', $currency)
                ->whereIn('ledgerUuid', $chunkUuids)
                ->lockForUpdate()
                ->get(['ledgerUuid', 'balance']);
            foreach ($chunkBalances as $chunkBalance) {
                $existingBalances[$chunkBalance->ledgerUuid] = $chunkBalance->balance;
            }
        }
        if (!$allowCreateMissing && count($existingBalances) !== count($ledgerUuids)) {
            $missingUuids = array_diff($ledgerUuids, array_keys($existingBalances));
            throw new Exception(
                __('Ledger balance rows are missing for account(s): :accounts', [
                    'accounts' => implode(', ', $missingUuids),
                ])
            );
        }
        $upsertRows = [];
        foreach ($balanceDeltas as $ledgerUuid => $delta) {
            $existingBalance = $existingBalances[$ledgerUuid] ?? $zeroBalance;
            $balance = bcadd($existingBalance, $delta, $decimals);
            $upsertRows[] = [
                'ledgerUuid' => $ledgerUuid,
                'domainUuid' => $domainUuid,
                'currency' => $currency,
                'balance' => $balance,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        foreach (array_chunk($upsertRows, $this->balanceChunkSize()) as $chunk) {
            DB::table('ledger_balances')->upsert(
                $chunk,
                ['ledgerUuid', 'domainUuid', 'currency'],
                ['balance', 'updated_at']
            );
        }
    }

    private function balanceChunkSize(): int
    {
        return max(
            1,
            (int) config('ledger.performance.entry.balance_chunk', self::BALANCE_CHUNK_DEFAULT)
        );
    }

    private function detailChunkSize(): int
    {
        return max(
            1,
            (int) config('ledger.performance.entry.detail_chunk', self::DETAIL_CHUNK_DEFAULT)
        );
    }

    /**
     * Delete an entry and reverse balance changes.
     *
     * @param Entry $message
     * @throws Breaker
     */
    public function delete(Entry $message)
    {
        $inTransaction = false;
        // Ensure that the message contents are valid.
        $message->validate(Message::OP_DELETE);

        try {
            DB::beginTransaction();
            $inTransaction = true;
            // Get the Journal entry
            $journalEntry = $this->fetch($message->id);
            $journalEntry->checkRevision($message->revision ?? null);
            $journalEntry->checkUnlocked();

            // We need the currency for balance adjustments.
            $this->getCurrency($journalEntry->currency);

            // Delete the detail records and update balances
            $this->deleteDetails($journalEntry);

            // Delete the journal entry
            $journalEntry->delete();
            DB::commit();
            $inTransaction = false;
            $this->auditLog($message);
        } catch (Exception $exception) {
            if ($inTransaction) {
                DB::rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param JournalEntry $journalEntry
     * @return void
     */
    private function deleteDetails(JournalEntry $journalEntry): void
    {
        $journalDetails = DB::table('journal_details')
            ->where('journalEntryId', $journalEntry->journalEntryId)
            ->get(['ledgerUuid', 'amount']);
        $balanceDeltas = [];
        foreach ($journalDetails as $oldDetail) {
            if (!isset($balanceDeltas[$oldDetail->ledgerUuid])) {
                $balanceDeltas[$oldDetail->ledgerUuid] = '0';
            }
            $balanceDeltas[$oldDetail->ledgerUuid] = bcsub(
                $balanceDeltas[$oldDetail->ledgerUuid],
                $oldDetail->amount,
                $this->ledgerCurrency->decimals
            );
        }
        DB::table('journal_details')
            ->where('journalEntryId', $journalEntry->journalEntryId)
            ->delete();
        $this->applyBalanceDeltas(
            $balanceDeltas,
            $journalEntry->domainUuid,
            $journalEntry->currency,
            false,
            $this->ledgerCurrency->decimals
        );
    }

    /**
     * Get a journal entry by ID.
     *
     * @param int $id
     * @return JournalEntry
     * @throws Breaker
     */
    private function fetch(int $id): JournalEntry
    {
        /** @noinspection PhpDynamicAsStaticMethodCallInspection */
        $journalEntry = JournalEntry::find($id);
        if ($journalEntry === null) {
            throw Breaker::withCode(
                Breaker::RULE_VIOLATION,
                [__('Journal entry :id does not exist', ['id' => $id])]
            );
        }

        return $journalEntry;
    }

    /**
     * Fetch a Journal Entry.
     *
     * @param Entry $message
     * @return JournalEntry
     * @throws Breaker
     */
    public function get(Entry $message): JournalEntry
    {
        $message->validate(Message::OP_GET);
        return $this->fetch($message->id);
    }

    /**
     * Get currency details.
     *
     * @throws Breaker
     */
    private function getCurrency($currency)
    {
        /** @noinspection PhpDynamicAsStaticMethodCallInspection */
        $this->ledgerCurrency = LedgerCurrency::find($currency);
        if ($this->ledgerCurrency === null) {
            throw Breaker::withCode(
                Breaker::INVALID_DATA,
                [__('Currency :code not found.', ['code' => $currency])]
            );
        }
    }

    /**
     * Place a lock on a journal entry.
     *
     * @param Entry $message
     * @return JournalEntry
     * @throws Breaker
     */
    public function lock(Entry $message): JournalEntry
    {
        $message->validate(Message::OP_LOCK);
        $inTransaction = false;
        try {
            DB::beginTransaction();
            $inTransaction = true;

            $journalEntry = $this->fetch($message->id);
            $journalEntry->checkRevision($message->revision ?? null);
            $journalEntry->locked = $message->lock;
            $journalEntry->save();
            $journalEntry->refresh();
            DB::commit();
            $this->auditLog($message);
            $inTransaction = false;
        } catch (Exception $exception) {
            if ($inTransaction) {
                DB::rollBack();
            }
            throw $exception;
        }

        return $journalEntry;
    }

    /**
     * Process a query request.
     *
     * @param EntryQuery $message
     * @param int $opFlags
     * @return Collection Collection contains JournalEntry records.
     * @throws Breaker
     */
    public function query(EntryQuery $message, int $opFlags): Collection
    {
        $message->validate($opFlags);
        $query = $message->query();
        $query->orderBy('transDate')
            ->orderBy('journalEntryId');

        //$foo = $query->toSql();

        return $query->get();
    }

    /**
     * Perform a Journal Entry operation.
     *
     * @param Entry $message
     * @param int|null $opFlags
     * @return JournalEntry|null
     * @throws Breaker
     */
    public function run(Entry $message, ?int $opFlags = null): ?JournalEntry
    {
        // TODO: add POST operation.
        $opFlags ??= $message->getOpFlags();
        switch ($opFlags & Message::ALL_OPS) {
            case Message::OP_ADD:
                return $this->add($message);
            case Message::OP_DELETE:
                $this->delete($message);
                return null;
            case Message::OP_GET:
                return $this->get($message);
            case Message::OP_LOCK:
                return $this->lock($message);
            case Message::OP_UPDATE:
                return $this->update($message);
            default:
                throw Breaker::withCode(Breaker::BAD_REQUEST, 'Unknown or invalid operation.');
        }
    }

    /**
     * Update a Journal Entry.
     *
     * @param Entry $message
     * @return JournalEntry
     * @throws Breaker
     */
    public function update(Entry $message): JournalEntry
    {
        $this->validateEntry($message, Message::OP_UPDATE);
        $inTransaction = false;
        try {
            DB::beginTransaction();
            $inTransaction = true;

            $journalEntry = $this->fetch($message->id);
            $journalEntry->checkRevision($message->revision ?? null);
            $journalEntry->checkUnlocked();

            $journalEntry->fillFromMessage($message);
            $this->updateDetails($journalEntry, $message);
            $journalEntry->save();
            $journalEntry->refresh();
            DB::commit();
            $inTransaction = false;
            $this->auditLog($message);
        } catch (Exception $exception) {
            if ($inTransaction) {
                DB::rollBack();
            }
            throw $exception;
        }

        return $journalEntry;
    }

    /**
     * Update entry details by undoing the existing details and creating the new ones.
     *
     * @param JournalEntry $journalEntry
     * @param Entry $message
     * @return void
     */
    protected function updateDetails(JournalEntry $journalEntry, Entry $message): void
    {
        // Remove existing details, undoing balance changes
        $this->deleteDetails($journalEntry);
        $this->addDetails($journalEntry, $message);
    }

    /**
     * Resolve accounts for all detail lines in one pass.
     *
     * @param array $details
     * @return array<int, LedgerAccount|null>
     */
    private function prefetchDetailAccounts(array $details): array
    {
        $codes = [];
        $uuids = [];
        foreach ($details as $detail) {
            if (isset($detail->account->code)) {
                $codes[$detail->account->code] = true;
            }
            if (isset($detail->account->uuid)) {
                $uuids[$detail->account->uuid] = true;
            }
        }
        $accountsByCode = count($codes)
            ? LedgerAccount::whereIn('code', array_keys($codes))->get()->keyBy('code')
            : collect();
        $accountsByUuid = count($uuids)
            ? LedgerAccount::whereIn('ledgerUuid', array_keys($uuids))->get()->keyBy('ledgerUuid')
            : collect();
        $accountsByLine = [];
        foreach ($details as $line => $detail) {
            $ledgerAccount = null;
            if (isset($detail->account->uuid)) {
                $ledgerAccount = $accountsByUuid->get($detail->account->uuid);
            } elseif (isset($detail->account->code)) {
                $ledgerAccount = $accountsByCode->get($detail->account->code);
            }
            if (
                $ledgerAccount !== null
                && isset($detail->account->code)
                && $detail->account->code !== $ledgerAccount->code
            ) {
                $ledgerAccount = null;
            }
            if ($ledgerAccount !== null) {
                $detail->account->uuid = $ledgerAccount->ledgerUuid;
            }
            $accountsByLine[$line] = $ledgerAccount;
        }

        return $accountsByLine;
    }

    /**
     * Resolve all detail references in one pass.
     *
     * @param array $details
     * @param Entry $message
     * @param array $errors
     * @return array<int, JournalReference|null>
     */
    private function prefetchDetailReferences(array $details, Entry $message, array &$errors): array
    {
        $codes = [];
        $uuids = [];
        $skipLine = [];
        foreach ($details as $line => $detail) {
            if (!isset($detail->reference)) {
                continue;
            }
            if (!isset($detail->reference->domain)) {
                $detail->reference->domain = $message->domain;
            } elseif (!$detail->reference->domain->sameAs($message->domain)) {
                $errors[] = __(
                    'Reference in Detail line :line has a mismatched domain.',
                    compact('line')
                );
                $skipLine[$line] = true;
                continue;
            }
            if (isset($detail->reference->journalReferenceUuid)) {
                $uuids[$detail->reference->journalReferenceUuid] = true;
            } elseif (isset($detail->reference->code)) {
                $codes[$detail->reference->code] = true;
            }
        }
        $referencesByCode = count($codes)
            ? JournalReference::where('domainUuid', $message->domain->uuid)
                ->whereIn('code', array_keys($codes))
                ->get()
                ->keyBy('code')
            : collect();
        $referencesByUuid = count($uuids)
            ? JournalReference::where('domainUuid', $message->domain->uuid)
                ->whereIn('journalReferenceUuid', array_keys($uuids))
                ->get()
                ->keyBy('journalReferenceUuid')
            : collect();
        $referencesByLine = [];
        foreach ($details as $line => $detail) {
            if (!isset($detail->reference) || isset($skipLine[$line])) {
                continue;
            }
            if (isset($detail->reference->journalReferenceUuid)) {
                $resolvedReference = $referencesByUuid->get($detail->reference->journalReferenceUuid);
            } else {
                $resolvedReference = $referencesByCode->get($detail->reference->code ?? '');
            }
            $referencesByLine[$line] = $resolvedReference;
        }

        return $referencesByLine;
    }

    /**
     * Perform an integrity check on the message.
     *
     * @param Entry $message
     * @param int $opFlag
     * @throws Breaker
     */
    private function validateEntry(Entry $message, int $opFlag)
    {
        // First the basics
        $message->validate($opFlag);
        $errors = [];

        // Get the domain
        $message->domain ??= new EntityRef(LedgerAccount::rules()->domain->default);
        $this->ledgerDomain = LedgerDomain::findWith($message->domain)->first();
        if ($this->ledgerDomain === null) {
            $errors[] = __('Domain :domain not found.', ['domain' => $message->domain]);
        } else {
            $message->domain->uuid = $this->ledgerDomain->domainUuid;

            // Get the currency, use the domain default if none provided
            $message->currency ??= $this->ledgerDomain->currencyDefault;
            $this->getCurrency($message->currency);
        }

        // If a journal is supplied, verify the code
        if (isset($message->journal)) {
            $subJournal = SubJournal::findWith($message->journal)->first();
            if ($subJournal === null) {
                $errors[] = __('Journal :code not found.', ['code' => $message->journal->code]);
            }
            $message->journal->uuid = $subJournal->subJournalUuid;
        }

        if ($this->ledgerDomain === null && count($errors) !== 0) {
            // Without the currency there is no point in going further.
            throw Breaker::withCode(Breaker::RULE_VIOLATION, $errors);
        }

        $accountsByLine = $this->prefetchDetailAccounts($message->details);
        $referencesByLine = $this->prefetchDetailReferences($message->details, $message, $errors);

        // Normalize the amounts and check for balance
        $postToCategory = LedgerAccount::rules()->account->postToCategory;
        $balance = '0';
        $unique = [];
        $precision = $this->ledgerCurrency->decimals;
        foreach ($message->details as $line => $detail) {
            // Make sure the account is valid and that we have the uuid
            $ledgerAccount = $accountsByLine[$line] ?? null;
            if ($ledgerAccount === null) {
                $errors[] = __(
                    'Detail line :line has an invalid account :account/:uuid.',
                    [
                        'line' => $line,
                        'account' => $detail->account->code ?? 'null',
                        'uuid' => $detail->account->uuid ?? 'null'
                    ]
                );
                continue;
            }
            if (!$postToCategory && $ledgerAccount->category) {
                $errors[] = __(
                    "Can't post to category account :code",
                    ['code' => $ledgerAccount->code]
                );
            }
            // Check that each account only appears once.
            if (isset($unique[$ledgerAccount->ledgerUuid])) {
                $errors[] = __(
                    'The account :code cannot appear more than once in an entry',
                    ['code' => $ledgerAccount->code]
                );
                continue;
            }
            $unique[$ledgerAccount->ledgerUuid] = true;

            // Make sure any reference is valid and that we have the uuid
            if (isset($detail->reference)) {
                if (array_key_exists($line, $referencesByLine)) {
                    $resolvedReference = $referencesByLine[$line];
                    if ($resolvedReference === null) {
                        $errors[] = __(
                            'Reference :code does not exist.',
                            ['code' => $detail->reference->code ?? '[undefined]']
                        );
                    } else {
                        $detail->reference->journalReferenceUuid = $resolvedReference->journalReferenceUuid;
                    }
                }
            }
            $balance = bcadd($balance, $detail->normalizeAmount($precision), $precision);
        }
        if (bccomp($balance, '0') !== 0) {
            $errors[] = __('Entry amounts are out of balance by :balance.', compact('balance'));
        }
        if (count($errors) !== 0) {
            throw Breaker::withCode(Breaker::RULE_VIOLATION, $errors);
        }
    }

}
