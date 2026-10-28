# Money, ledgers and payments

Keel has the parts financial services build on: exact money, financial identifiers, an auditable
double-entry ledger, idempotency, encryption at rest and end to end, and a native [FIX](fix.md) engine.

## Money

```php
$price = Money::of('19.99', 'EUR');
$total = $price->times(3)->plus(Money::of('4.50', 'EUR'));    // 64.47 EUR
$vat = $total->times('0.21', Rounding::HalfUp);                 // 13.54 EUR
[$a, $b, $c] = Money::of('100', 'EUR')->split(3);              // 33.34, 33.33, 33.33
```

- Integer minor units, never a float: `0.10 + 0.20` is `0.30`.
- ISO 4217 currencies with their decimal places: EUR 2, JPY 0, KWD 3, CLF 4. More places than the currency
  has is an error, not a silent rounding.
- `times()` takes an integer or an exact decimal string; seven rounding modes (`HalfEven` by default).
- `allocate()` and `split()` never create or lose a minor unit: leftovers go to the largest remainders.
- Combining two currencies throws `CurrencyMismatch`; overflow throws instead of wrapping.

**As an API type**, `Money` is `{"amount": "19.99", "currency": "EUR"}`, amounts as strings so no client
parses them as floats. Inputs are validated, with paths: `amount.amount` (`decimal`, `scale`) and
`amount.currency` (`currency`). The OpenAPI document describes it.

```php
final readonly class TopUpInput
{
    public function __construct(
        public Money $amount,
        #[Iban] public string $fromIban,
        #[Bic] public ?string $bic = null,
    ) {}
}
```

In the database, store the minor units (`BIGINT`) and the currency, or a `#[Column(type: 'decimal')]`
string; in MongoDB, `Decimal128::fromMoney($money)`.

## Identifiers

| Constraint | Checks |
| --- | --- |
| `#[Iban]` | Electronic form, the country's length (over 80 countries), ISO 7064 mod 97-10 |
| `#[Bic]` | ISO 9362, 8 or 11 characters |
| `#[CardNumber]` | 12 to 19 digits and the Luhn check. `CardNumber::mask()` keeps the first six and last four digits |
| `#[CurrencyCode]` | An ISO 4217 code with a minor unit |
| `#[Decimal(scale: 2, positive: true)]` | An exact decimal in a string |

Card numbers put you in PCI DSS scope. Prefer your processor's tokens; if you must keep numbers, use
`#[Encrypted]`, log `CardNumber::mask()` only, and send them end-to-end encrypted.

## The ledger

```php
// app.php
->withDatabase()
->withLedger()        // keel_ledger_* tables, applied by `keel migrate`
```

```php
$ledger->post([
    Entry::debit('assets:bank:eur', Money::of('100.00', 'EUR')),
    Entry::credit("liabilities:wallets:$userId", Money::of('100.00', 'EUR')),
], 'Top-up', idempotencyKey: "topup:$paymentId");

$ledger->post([
    Entry::debit("liabilities:wallets:$from", $amount),
    Entry::credit("liabilities:wallets:$to", $amount->minus($fee)),
    Entry::credit('income:fees', $fee),
], 'Transfer', limits: ["liabilities:wallets:$from" => 'credit']);   // no overdraft

$ledger->balance("liabilities:wallets:$userId", 'EUR')->negate();   // what the wallet holds
$ledger->history("liabilities:wallets:$userId", 'EUR');
$ledger->reverse($transactionId);                                    // corrections are new transactions
```

| Guarantee | How |
| --- | --- |
| Books always balance | A transaction's legs sum to zero per currency, or `UnbalancedTransaction` |
| Nothing is edited | Entries are only inserted; corrections are reversing transactions |
| Retries are safe | The same idempotency key returns the first transaction; a different transaction under a used key is `LedgerConflict` (409) |
| No overdraft | `limits` are checked inside the transaction, after the legs are written; `InsufficientFunds` (422) rolls it back |
| Tampering is detected | Transactions are numbered without gaps and hash-chained over every leg; `verify()` / `keel ledger:verify` names the first transaction that does not match |
| Ordering | Posting locks the chain head (`FOR UPDATE`), so transactions are serialized |

Balances follow the debit-positive convention: assets and expenses are positive, liabilities, income and
equity negative. The ledger runs on SQLite, PostgreSQL and MySQL. It records the current tenant on every
row; add the tables to `database.tenant_tables` to make the tenant guard enforce it.

Hash chaining detects changes made outside the application (a script, a compromised database account); it
does not stop them. Ship `ledger:verify` results and the head hash to a separate system (or a write-once
store) to make the evidence durable.

## Payments plumbing

- **Idempotency keys** on every mutating endpoint: `Idempotency-Key` replays the stored response.
- **Webhooks** in, verified with `WebhookSigner::verify()`; out, signed and retried by the queue.
- **The outbox:** a payment recorded and its notification queued commit together.
- **Encryption:** [at rest and end to end](encryption.md).
- **Audit:** traces, structured logs with request ids, and the ledger's hash chain.
- **Trading:** the [FIX engine](fix.md) for order flow with brokers and exchanges.
