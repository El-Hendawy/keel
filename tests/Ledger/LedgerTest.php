<?php

declare(strict_types=1);

namespace Keel\Tests\Ledger;

use Keel\Container\Scope;
use Keel\Database\ConnectionManager;
use Keel\Ledger\Entry;
use Keel\Ledger\InsufficientFunds;
use Keel\Ledger\Ledger;
use Keel\Ledger\LedgerConflict;
use Keel\Ledger\LedgerTampered;
use Keel\Ledger\UnbalancedTransaction;
use Keel\Money\Money;
use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use PHPUnit\Framework\TestCase;

final class LedgerTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot(__DIR__ . '/../Fixtures/Fintech/app.php', [
            'database' => ['dsn' => TestDatabase::dsn()],
            'log' => ['stream' => 'php://memory'],
        ]);
        $this->app->freshDatabase(__DIR__ . '/../Fixtures/Fintech/migrations');
    }

    public function testPostsBalancedTransactionsAndKeepsBalances(): void
    {
        $this->ledger(static function (Ledger $ledger): void {
            $eur = static fn (string $a): Money => Money::of($a, 'EUR');
            $first = $ledger->post([Entry::debit('assets:bank', $eur('100')), Entry::credit('liabilities:wallets:ana', $eur('100'))], 'Top-up', 'topup:1');
            $ledger->post([
                Entry::debit('liabilities:wallets:ana', $eur('30')),
                Entry::credit('liabilities:wallets:ben', $eur('29.50')),
                Entry::credit('income:fees', $eur('0.50')),
            ], 'Transfer with fee');

            self::assertSame('70.00', $ledger->balance('liabilities:wallets:ana', 'EUR')->negate()->amount);
            self::assertSame('29.50', $ledger->balance('liabilities:wallets:ben', 'EUR')->negate()->amount);
            self::assertSame('100.00', $ledger->balance('assets:bank', 'EUR')->amount);
            self::assertSame('0.00', $ledger->balance('assets:bank', 'USD')->amount);
            self::assertSame($first, $ledger->post([Entry::debit('assets:bank', $eur('100')), Entry::credit('liabilities:wallets:ana', $eur('100'))], 'Top-up', 'topup:1'), 'a retry returns the first transaction');
            self::assertSame('70.00', $ledger->balance('liabilities:wallets:ana', 'EUR')->negate()->amount, 'and posts nothing');

            $history = $ledger->history('liabilities:wallets:ana', 'EUR');
            self::assertSame(['30.00', '-100.00'], array_map(static fn (array $h): string => $h['amount']->amount, $history));
            self::assertSame(2, $ledger->verify());
        });
    }

    public function testRefusesWhatWouldBreakTheBooks(): void
    {
        $this->ledger(static function (Ledger $ledger): void {
            $eur = static fn (string $a): Money => Money::of($a, 'EUR');
            $ledger->post([Entry::debit('assets:bank', $eur('10')), Entry::credit('liabilities:wallets:ana', $eur('10'))], idempotencyKey: 'k1');

            foreach ([
                'unbalanced' => static fn () => $ledger->post([Entry::debit('a', $eur('10')), Entry::credit('b', $eur('9.99'))]),
                'one leg' => static fn () => $ledger->post([Entry::debit('a', $eur('10'))]),
                'mixed currencies' => static fn () => $ledger->post([Entry::debit('a', $eur('10')), Entry::credit('b', Money::of('10', 'USD'))]),
            ] as $why => $post) {
                try {
                    $post();
                    self::fail("Expected UnbalancedTransaction: $why.");
                } catch (UnbalancedTransaction $e) {
                    self::assertNotSame('', $e->getMessage());
                }
            }

            try {
                $ledger->post([Entry::debit('assets:bank', $eur('11')), Entry::credit('liabilities:wallets:ana', $eur('11'))], idempotencyKey: 'k1');
                self::fail('A reused idempotency key with other legs must be refused.');
            } catch (LedgerConflict $e) {
                self::assertSame(409, $e->status);
            }

            try {
                $ledger->post([Entry::debit('liabilities:wallets:ana', $eur('10.01')), Entry::credit('assets:bank', $eur('10.01'))], 'Withdrawal', limits: ['liabilities:wallets:ana' => 'credit']);
                self::fail('Overdrawing a limited wallet must be refused.');
            } catch (InsufficientFunds $e) {
                self::assertSame(422, $e->status);
            }
            self::assertSame('-10.00', $ledger->balance('liabilities:wallets:ana', 'EUR')->amount, 'the refused posting was rolled back');
            $ledger->post([Entry::debit('liabilities:wallets:ana', $eur('10')), Entry::credit('assets:bank', $eur('10'))], limits: ['liabilities:wallets:ana' => 'credit']);
            self::assertTrue($ledger->balance('liabilities:wallets:ana', 'EUR')->isZero());

            $reversal = $ledger->reverse($ledger->history('assets:bank', 'EUR')[0]['transaction']);
            self::assertNotSame('', $reversal);
            self::assertSame('-10.00', $ledger->balance('liabilities:wallets:ana', 'EUR')->amount);
            self::assertSame(3, $ledger->verify());
        });
    }

    public function testVerifyDetectsTampering(): void
    {
        $this->ledger(static function (Ledger $ledger): void {
            for ($i = 1; $i <= 3; $i++) {
                $ledger->post([Entry::debit('assets:bank', Money::of((string) $i, 'EUR')), Entry::credit('equity', Money::of((string) $i, 'EUR'))]);
            }
            self::assertSame(3, $ledger->verify());
        });
        $pdo = $this->app->container()->get(ConnectionManager::class)->writer();

        $pdo->exec("UPDATE keel_ledger_entries SET amount = 500 WHERE account = 'assets:bank' AND amount = 200");
        $this->assertTampered('#2');
        $pdo->exec("UPDATE keel_ledger_entries SET amount = 200 WHERE account = 'assets:bank' AND amount = 500");
        $this->ledger(static fn (Ledger $ledger): int => $ledger->verify());

        $pdo->exec('DELETE FROM keel_ledger_transactions WHERE seq = 3');
        $this->assertTampered('removed from the end');
    }

    public function testMoneyIsAnApiType(): void
    {
        $response = $this->app->post('/wallets/top-ups', ['wallet' => 'ana', 'amount' => ['amount' => '25.5', 'currency' => 'EUR'], 'fromIban' => 'DE89370400440532013000']);
        $response->assertStatus(200)->assertJsonPath('balance', ['amount' => '25.50', 'currency' => 'EUR']);

        $errors = $this->app->post('/wallets/top-ups', ['wallet' => 'ana', 'amount' => ['amount' => '1.234', 'currency' => 'EUR'], 'fromIban' => 'DE00370400440532013000', 'bic' => 'nope'])
            ->assertStatus(422)->json()['errors'] ?? [];
        \assert(\is_array($errors));
        $codes = array_column($errors, 'code', 'path');
        ksort($codes);
        self::assertSame(['amount.amount' => 'scale', 'bic' => 'bic', 'fromIban' => 'iban'], $codes);

        $this->app->post('/wallets/top-ups', ['wallet' => 'ana', 'amount' => ['amount' => 12.5, 'currency' => 'EURO'], 'fromIban' => 'DE89370400440532013000'])
            ->assertStatus(422);
    }

    /** @param \Closure(Ledger): mixed $work */
    private function ledger(\Closure $work): void
    {
        $this->app->inScope(static fn (Scope $scope): mixed => $work($scope->get(Ledger::class)));
    }

    private function assertTampered(string $message): void
    {
        try {
            $this->ledger(static fn (Ledger $ledger): int => $ledger->verify());
            self::fail('Expected LedgerTampered.');
        } catch (LedgerTampered $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
