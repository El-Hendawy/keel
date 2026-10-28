<?php

declare(strict_types=1);

namespace Keel\Tests\Queue;

use Keel\Container\Scope;
use Keel\Database\ConnectionManager;
use Keel\Database\Database;
use Keel\Queue\FailedJobs;
use Keel\Tenancy\TenantContext;
use Keel\Tenancy\TenantViolation;
use Keel\Testing\TestApp;
use Keel\Webhooks\WebhookSigner;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Shop\Orders\ReportDailyOrders;

/** Queue (outbox), async listeners, webhooks, scheduler, pagination and the tenant guard on the shop example. */
final class BackgroundWorkTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../examples/shop';
    private const string TENANT = '11111111-1111-4111-8111-111111111111';
    private const string SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    /** @param array<string, mixed> $config */
    private static function boot(array $config = []): TestApp
    {
        $app = TestApp::boot(self::ROOT . '/app.php', array_replace_recursive([
            'database' => ['dsn' => \Keel\Testing\TestDatabase::dsn()],
            'log' => ['stream' => 'php://memory'],
            'merchants' => ['webhook_url' => 'https://merchant.example/hooks', 'webhook_secret' => self::SECRET],
            'webhooks' => ['unsafe_allow_private_networks' => true], // no DNS in tests
        ], $config));
        $app->freshDatabase(self::ROOT . '/migrations');

        return $app;
    }

    /** @return array<string, mixed> */
    private static function order(): array
    {
        return ['merchantId' => '22222222-2222-4222-8222-222222222222', 'items' => [['sku' => 'TEA', 'quantity' => 2, 'unitPriceCents' => 300]]];
    }

    private static function jobCount(TestApp $app, string $status = 'pending'): int
    {
        $statement = $app->container()->get(ConnectionManager::class)->writer()->prepare('SELECT COUNT(*) FROM keel_jobs WHERE status = ?');
        $statement->execute([$status]);

        return (int) $statement->fetchColumn();
    }

    public function testAnAsyncListenerSendsASignedWebhookAfterCommit(): void
    {
        $app = self::boot();
        $received = [];
        $app->http()->stub('POST', 'https://merchant.example/hooks', static function (RequestInterface $request) use (&$received): Response {
            $received[] = $request;

            return new Response(204);
        });

        $id = $app->post('/orders', self::order(), $app->tokenFor('ada', self::TENANT))->assertStatus(201)->get('id');
        self::assertSame([], $received, 'Nothing is sent inside the request.');
        self::assertSame(1, self::jobCount($app), 'The listener job was written with the order (outbox).');

        self::assertSame(2, $app->runJobs()); // the listener, then the webhook delivery it queued
        self::assertCount(1, $received);
        $request = $received[0];
        $body = (string) $request->getBody();
        self::assertSame(['type' => 'order.placed', 'data' => ['orderId' => $id, 'totalCents' => 600]], json_decode($body, true));
        $headers = [];
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }
        self::assertTrue(WebhookSigner::verify($headers, $body, self::SECRET, (int) $headers['webhook-timestamp']));
        self::assertFalse(WebhookSigner::verify($headers, $body . ' ', self::SECRET, (int) $headers['webhook-timestamp']));
        self::assertSame(0, self::jobCount($app));
    }

    public function testJobsRollBackWithTheTransaction(): void
    {
        $app = self::boot();
        $app->inScope(static function (Scope $scope): void {
            $scope->get(TenantContext::class)->set(self::TENANT);
            try {
                $scope->get(Database::class)->transaction(static function () use ($scope): void {
                    $scope->get(\Keel\Webhooks\Webhooks::class)->send('https://merchant.example/hooks', self::SECRET, 'test', ['a' => 1]);
                    throw new \RuntimeException('rolled back');
                });
            } catch (\RuntimeException) {
            }
        });
        self::assertSame(0, self::jobCount($app));
    }

    public function testFailingJobsAreRetriedWithBackoffThenKeptAsFailed(): void
    {
        $app = self::boot();
        $app->http()->stub('POST', 'https://merchant.example/hooks', [], 500);
        $app->post('/orders', self::order(), $app->tokenFor('ada', self::TENANT))->assertStatus(201);

        self::assertSame(2, $app->runJobs());
        self::assertSame(1, self::jobCount($app), 'The failed delivery waits for its retry.');
        self::assertSame(0, $app->runJobs(), 'Not before its backoff has passed.');

        for ($attempt = 2; $attempt <= 8; $attempt++) {
            $app->clock()->advance('+2 hours');
            self::assertSame(1, $app->runJobs());
        }
        self::assertSame(1, self::jobCount($app, 'failed'));

        $failed = new FailedJobs($app->container()->get(ConnectionManager::class)->writer());
        self::assertStringContainsString('HTTP 500', $failed->list()[0]['last_error']);
        self::assertSame(1, $failed->retry('all', $app->clock()->now()->getTimestamp()));
        self::assertSame(1, self::jobCount($app));
    }

    public function testWithoutAQueueJobsRunWhenTheScopeEnds(): void
    {
        $app = TestApp::boot(__DIR__ . '/../Fixtures/SyncJobs/app.php');
        $log = $app->container()->get(\Keel\Tests\Fixtures\SyncJobs\JobLog::class);
        $app->inScope(static function (Scope $scope) use ($log): void {
            $scope->get(\Keel\Queue\Queue::class)->push(new \Keel\Tests\Fixtures\SyncJobs\Ping('a'));
            self::assertSame([], $log->seen, 'Not inside the pushing scope.');
        });
        self::assertSame(['a'], $log->seen);
    }

    public function testWebhooksRefusePrivateNetworks(): void
    {
        $app = self::boot(['webhooks' => ['unsafe_allow_private_networks' => false]]);
        $guard = $app->container()->get(\Keel\Webhooks\UrlGuard::class);
        foreach (['http://example.com/', 'https://127.0.0.1/', 'https://169.254.169.254/latest', 'https://10.0.0.8/', 'https://[::1]/', 'https://user:pw@example.com/'] as $url) {
            try {
                $guard->check($url);
                self::fail("$url should be refused");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertTrue(\Keel\Webhooks\UrlGuard::isPublic('93.184.215.14'));
        self::assertFalse(\Keel\Webhooks\UrlGuard::isPublic('100.64.1.1'));
    }

    public function testScheduledTasksRunOncePerDueMinute(): void
    {
        $app = self::boot();
        $at = new \DateTimeImmutable('2026-01-02T03:00:00Z');
        self::assertSame([ReportDailyOrders::class], $app->runSchedule($at));
        self::assertSame([], $app->runSchedule($at), 'A second scheduler in the same minute does nothing.');
        self::assertSame([], $app->runSchedule(new \DateTimeImmutable('2026-01-02T03:01:00Z')));
    }

    public function testCursorPaginationIsStableAndTamperProof(): void
    {
        $app = self::boot();
        $user = $app->tokenFor('ada', self::TENANT);
        for ($i = 0; $i < 5; $i++) {
            $app->post('/orders', self::order(), $user)->assertStatus(201);
            $app->clock()->advance('+1 second');
        }
        $seen = [];
        $cursor = null;
        do {
            $page = $app->get('/orders?limit=2' . ($cursor === null ? '' : '&cursor=' . urlencode($cursor)), $user)->assertStatus(200);
            foreach ($page->json()['data'] as $order) {
                $seen[] = $order['id'];
            }
            $cursor = $page->get('page.nextCursor');
        } while (\is_string($cursor));
        self::assertCount(5, $seen);
        self::assertSame($seen, array_values(array_unique($seen)));

        $first = $app->get('/orders?limit=2', $user)->get('page.nextCursor');
        self::assertIsString($first);
        $app->get('/orders?cursor=' . urlencode('x' . $first), $user)->assertStatus(400);
    }

    public function testTheTenantGuardRefusesUnfilteredQueries(): void
    {
        $app = self::boot();
        $app->inScope(static function (Scope $scope): void {
            $db = $scope->get(Database::class);
            $tenant = $scope->get(TenantContext::class);

            self::assertThrows(static fn () => $db->select('SELECT * FROM orders'), 'has no tenant');
            $tenant->set(self::TENANT);
            self::assertThrows(static fn () => $db->select('SELECT * FROM orders WHERE id = ?', ['x']), 'without filtering by tenant_id');
            self::assertThrows(static fn () => $db->select('SELECT * FROM orders WHERE tenant_id = ?', ['99999999-9999-4999-8999-999999999999']), 'current tenant is not among its parameters');
            self::assertSame([], $db->select('SELECT * FROM orders WHERE tenant_id = ?', [self::TENANT]));
            self::assertSame([], $db->select('SELECT * FROM order_items WHERE order_id = ?', ['x']), 'Tables not listed are not guarded.');
            self::assertSame(0, $tenant->acrossTenants(static fn (): int => \count($db->select('SELECT * FROM orders'))));
        });
    }

    private static function assertThrows(\Closure $operation, string $message): void
    {
        try {
            $operation();
            self::fail("Expected a TenantViolation containing \"$message\".");
        } catch (TenantViolation $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
