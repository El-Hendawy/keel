<?php

declare(strict_types=1);

namespace Keel\Ledger;

use Keel\Container\Attribute\Scoped;
use Keel\Database\Database;
use Keel\Database\Row;
use Keel\Money\Money;
use Keel\Support\IdGenerator;
use Keel\Tenancy\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * A double-entry ledger in the application database: balances you can audit.
 *
 *     $ledger->post([
 *         Entry::debit('assets:bank:eur', Money::of('100.00', 'EUR')),
 *         Entry::credit("liabilities:wallets:$userId", Money::of('100.00', 'EUR')),
 *     ], 'Top-up', idempotencyKey: "topup:$paymentId");
 *
 *     $ledger->balance("liabilities:wallets:$userId", 'EUR');   // Money, credit-positive for liabilities: see balance()
 *
 * - Every transaction balances per currency (debits equal credits) or is refused.
 * - Entries are never updated or deleted; corrections are new, reversing transactions (reverse()).
 * - Transactions are numbered without gaps and hash-chained: each hash covers the previous one
 *   and every leg, so verify() detects any row changed, removed or inserted after the fact.
 * - An idempotency key makes retries safe: posting the same key again returns the first
 *   transaction, and a different transaction under a used key is refused.
 * - `limits` refuse a posting that would overdraw an account: a wallet (a liability) must stay
 *   on the credit side, `["liabilities:wallets:$id" => 'credit']`; a cash account on the debit side.
 *
 * Posting locks the chain head, so transactions are serialized; that is the price of a gapless,
 * verifiable sequence.
 */
#[Scoped]
final readonly class Ledger
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private Database $db,
        private ClockInterface $clock,
        private IdGenerator $ids,
        private TenantContext $tenant,
    ) {
    }

    /**
     * @param list<Entry> $entries at least two legs that balance per currency
     * @param array<string, scalar|null> $metadata
     * @param array<string, 'debit'|'credit'> $limits accounts whose balance may not cross to the other side
     * @return string the transaction id
     */
    public function post(array $entries, string $description = '', ?string $idempotencyKey = null, array $metadata = [], array $limits = []): string
    {
        self::assertBalanced($entries);
        $metadataJson = json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $tenant = $this->tenant->current();

        return $this->db->transaction(function (Database $db) use ($entries, $description, $idempotencyKey, $metadataJson, $tenant, $limits): string {
            if ($idempotencyKey !== null) {
                $existing = $db->selectOne('SELECT id FROM keel_ledger_transactions WHERE idempotency_key = ?', [$idempotencyKey]);
                if ($existing !== null) {
                    $id = $existing->string('id');
                    if (self::fingerprint($this->legs($id)) !== self::fingerprint(self::rows($entries))) {
                        throw new LedgerConflict("Idempotency key \"$idempotencyKey\" was already used for a different transaction ($id).");
                    }

                    return $id;
                }
            }
            $head = $db->driver() === 'sqlite'
                ? $db->selectOne('SELECT seq, hash FROM keel_ledger_head WHERE id = 1')
                : $db->rawQuery('SELECT seq, hash FROM keel_ledger_head WHERE id = 1 FOR UPDATE')[0] ?? null;
            if ($head === null) {
                throw new \LogicException('keel_ledger_head is empty: run the ledger migration (->withLedger(), then `keel migrate`).');
            }
            $seq = $head->int('seq') + 1;
            $id = $this->ids->generate();
            $postedAt = $this->clock->now()->getTimestamp();
            $legs = self::rows($entries);
            $hash = self::hash($head->string('hash'), $seq, $id, $tenant, $description, $metadataJson, $postedAt, $legs);

            $db->execute(
                'INSERT INTO keel_ledger_transactions (id, seq, idempotency_key, tenant_id, description, metadata, posted_at, previous_hash, hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $seq, $idempotencyKey, $tenant, $description, $metadataJson, $postedAt, $head->string('hash'), $hash],
            );
            foreach ($legs as $leg => [$account, $currency, $amount]) {
                $db->execute(
                    'INSERT INTO keel_ledger_entries (id, transaction_id, leg, account, currency, amount, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$this->ids->generate(), $id, $leg, $account, $currency, $amount, $tenant],
                );
            }
            $db->execute('UPDATE keel_ledger_head SET seq = ?, hash = ? WHERE id = 1', [$seq, $hash]);

            foreach ($limits as $account => $side) {
                foreach (array_unique(array_column(array_filter($legs, static fn (array $l): bool => $l[0] === $account), 1)) as $currency) {
                    $balance = $this->sum($account, $currency);
                    if ($side === 'credit' ? $balance > 0 : $balance < 0) {
                        throw new InsufficientFunds("Posting would overdraw $account in $currency.");
                    }
                }
            }

            return $id;
        });
    }

    /** Posts the opposite of a transaction, linked to it in the metadata. */
    public function reverse(string $transactionId, string $description = '', ?string $idempotencyKey = null): string
    {
        $legs = $this->legs($transactionId);
        if ($legs === []) {
            throw new \InvalidArgumentException("Ledger transaction $transactionId does not exist.");
        }
        $entries = array_map(static fn (array $l): Entry => new Entry($l[0], Money::ofMinor(-$l[2], $l[1])), $legs);

        return $this->post($entries, $description === '' ? "Reversal of $transactionId" : $description, $idempotencyKey ?? "reversal:$transactionId", ['reverses' => $transactionId]);
    }

    /**
     * The sum of an account's legs: positive means net debit. For liability and income accounts
     * (credit-normal), negate it to read the amount owed: `$ledger->balance('liabilities:x', 'EUR')->negate()`.
     */
    public function balance(string $account, string $currency): Money
    {
        return Money::ofMinor($this->sum($account, $currency), $currency);
    }

    /**
     * An account's legs, newest first.
     *
     * @return list<array{transaction: string, seq: int, amount: Money, description: string, postedAt: int}>
     */
    public function history(string $account, string $currency, int $limit = 100): array
    {
        $rows = $this->db->select(
            'SELECT t.id, t.seq, t.description, t.posted_at, e.amount FROM keel_ledger_entries e JOIN keel_ledger_transactions t ON t.id = e.transaction_id WHERE e.account = ? AND e.currency = ? ORDER BY t.seq DESC, e.leg',
            [$account, $currency],
        );

        return array_map(static fn (Row $r): array => [
            'transaction' => $r->string('id'),
            'seq' => $r->int('seq'),
            'amount' => Money::ofMinor($r->int('amount'), $currency),
            'description' => $r->string('description'),
            'postedAt' => $r->int('posted_at'),
        ], \array_slice($rows, 0, $limit));
    }

    /**
     * Recomputes the hash chain from the first transaction.
     *
     * @return int the number of transactions verified
     * @throws LedgerTampered naming the first transaction that does not match
     */
    public function verify(int $batch = 500): int
    {
        $previous = self::GENESIS;
        $expectedSeq = 1;
        while (true) {
            $transactions = $this->db->select(
                'SELECT id, seq, tenant_id, description, metadata, posted_at, previous_hash, hash FROM keel_ledger_transactions WHERE seq >= ? AND seq < ? ORDER BY seq',
                [$expectedSeq, $expectedSeq + $batch],
            );
            if ($transactions === []) {
                break;
            }
            foreach ($transactions as $t) {
                $seq = $t->int('seq');
                if ($seq !== $expectedSeq) {
                    throw new LedgerTampered("Ledger transaction #$expectedSeq is missing.");
                }
                $id = $t->string('id');
                $hash = self::hash($previous, $seq, $id, $t->nullableString('tenant_id'), $t->string('description'), $t->string('metadata'), $t->int('posted_at'), $this->legs($id));
                if ($t->string('previous_hash') !== $previous || !hash_equals($t->string('hash'), $hash)) {
                    throw new LedgerTampered("Ledger transaction #$seq ($id) does not match its hash: it or its entries were changed.");
                }
                $previous = $hash;
                $expectedSeq++;
            }
        }
        $head = $this->db->selectOne('SELECT seq, hash FROM keel_ledger_head WHERE id = 1');
        if ($head === null || $head->int('seq') !== $expectedSeq - 1 || $head->string('hash') !== $previous) {
            throw new LedgerTampered('The ledger head does not match the last transaction: transactions were removed from the end.');
        }

        return $expectedSeq - 1;
    }

    private function sum(string $account, string $currency): int
    {
        $row = $this->db->selectOne('SELECT COALESCE(SUM(amount), 0) AS total FROM keel_ledger_entries WHERE account = ? AND currency = ?', [$account, $currency]);

        return $row === null ? 0 : $row->int('total');
    }

    /** @return list<array{0: string, 1: string, 2: int}> account, currency, signed minor amount, in posting order */
    private function legs(string $transactionId): array
    {
        return array_map(
            static fn (Row $r): array => [$r->string('account'), $r->string('currency'), $r->int('amount')],
            $this->db->select('SELECT account, currency, amount FROM keel_ledger_entries WHERE transaction_id = ? ORDER BY leg', [$transactionId]),
        );
    }

    /**
     * @param list<Entry> $entries
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function rows(array $entries): array
    {
        return array_map(static fn (Entry $e): array => [$e->account, $e->amount->currency, $e->amount->minor()], $entries);
    }

    /** @param list<Entry> $entries */
    private static function assertBalanced(array $entries): void
    {
        if (\count($entries) < 2) {
            throw new UnbalancedTransaction('A ledger transaction needs at least two entries.');
        }
        $totals = [];
        foreach ($entries as $entry) {
            if ($entry->amount->isZero()) {
                throw new UnbalancedTransaction("The entry for {$entry->account} is zero.");
            }
            $totals[$entry->amount->currency] = ($totals[$entry->amount->currency] ?? 0) + $entry->amount->minor();
        }
        foreach ($totals as $currency => $total) {
            if ($total !== 0) {
                throw new UnbalancedTransaction('Debits and credits differ by ' . Money::ofMinor($total, $currency) . '.');
            }
        }
    }

    /** @param list<array{0: string, 1: string, 2: int}> $legs */
    private static function fingerprint(array $legs): string
    {
        return hash('sha256', json_encode($legs, \JSON_THROW_ON_ERROR));
    }

    /** @param list<array{0: string, 1: string, 2: int}> $legs */
    private static function hash(string $previous, int $seq, string $id, ?string $tenant, string $description, string $metadata, int $postedAt, array $legs): string
    {
        return hash('sha256', $previous . json_encode([$seq, $id, $tenant, $description, $metadata, $postedAt, $legs], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }
}
