<?php

declare(strict_types=1);

namespace Keel\Tests\Service;

use Keel\Console\DeploymentGenerator;
use Keel\Service\CloudEvent;
use Keel\Testing\TestApp;
use Keel\Testing\TestDatabase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Two copies of one service, "orders" calling "billing" in process, as they would over the network. */
final class ServicesTest extends TestCase
{
    private const string KEY = 'base64:c2VydmljZS10ZXN0LWtleS1zaGFyZWQtYnktYm90aCE=';

    private TestApp $orders;

    private TestApp $billing;

    protected function setUp(): void
    {
        $file = static fn (string $name): string => 'sqlite:' . sys_get_temp_dir() . "/keel-mesh-$name-" . getmypid() . '.sqlite';
        $this->billing = $this->boot('billing', $file('billing'), ['auth' => ['jwt' => ['audience' => 'billing']]]);
        $this->orders = $this->boot('orders', $file('orders'), [
            'auth' => ['jwt' => ['audience' => 'orders']],
            'services' => ['billing' => ['url' => 'http://billing.internal', 'scopes' => ['invoices:write', 'events:publish']]],
            'events' => ['source' => 'https://orders.example.com', 'subscribers' => ['order.placed' => ['billing']]],
        ]);
        // The network: requests from orders to billing are served by the billing app.
        $this->orders->http()->stub('POST', 'http://billing.internal/*', fn (RequestInterface $request): ResponseInterface => $this->billing->send(
            new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders(), (string) $request->getBody(), '1.1', ['REMOTE_ADDR' => '10.0.0.7']),
        )->response);
    }

    public function testCallsCarryCredentialsTenantTraceAndDeadline(): void
    {
        $headers = $this->orders->tokenFor('user-1', 't1', ['*']) + ['X-Request-Id' => 'req-0123456789', 'Keel-Timeout-Ms' => '5000'];
        $facts = $this->orders->post('/orders/o-42/invoice', null, $headers)->assertStatus(200)->json();

        self::assertSame('service:orders', $facts['caller']);
        self::assertSame('service', $facts['kind']);
        self::assertSame('t1', $facts['tenant'], 'the tenant travels inside the service token');
        self::assertSame('req-0123456789', $facts['requestId']);
        self::assertIsInt($facts['timeoutMs']);
        self::assertGreaterThan(0, $facts['timeoutMs']);
        self::assertLessThanOrEqual(5000, $facts['timeoutMs']);
        self::assertSame('o-42', $facts['orderId']);
        $sent = $this->orders->http()->sent()[0];
        self::assertStringStartsWith('Bearer ', $sent->getHeaderLine('Authorization'));
        // traceparent is added by the default client (ResilientHttpClient), which tests replace.
    }

    public function testDeadlines(): void
    {
        $user = $this->orders->tokenFor('user-1', 't1', ['*']);
        $this->orders->post('/orders/o-1/invoice', null, $user + ['Keel-Timeout-Ms' => '0'])->assertStatus(504)->assertJsonPath('code', 'deadline_exceeded');
        $this->orders->post('/orders/o-1/invoice', null, $user + ['grpc-timeout' => '2S'])->assertStatus(200);
        self::assertSame([], array_slice($this->orders->http()->sent(), 1), 'one call only');
    }

    public function testBillingRefusesCallersWithoutTheScopeOrTheAudience(): void
    {
        // A JWT meant for "orders" is not accepted by billing, even with every scope and the same signing key.
        $jwt = $this->orders->container()->get(\Keel\Auth\Jwt\JwtTokens::class)->issue(new \Keel\Auth\Principal('service:x', 't1', ['*'], \Keel\Auth\Principal::SERVICE));
        $this->billing->post('/invoices', ['orderId' => 'x'], ['Authorization' => "Bearer $jwt"])->assertStatus(401);
        $forBilling = $this->orders->container()->get(\Keel\Auth\Jwt\JwtTokens::class)->issue(new \Keel\Auth\Principal('service:x', 't1', ['orders:read'], \Keel\Auth\Principal::SERVICE), audience: 'billing');
        $this->billing->post('/invoices', ['orderId' => 'x'], ['Authorization' => "Bearer $forBilling"])->assertStatus(403);
        $this->billing->post('/invoices', ['orderId' => 'x'])->assertStatus(401);
    }

    public function testIntegrationEventsAreDeliveredOnceThroughTheOutbox(): void
    {
        $user = $this->orders->tokenFor('user-1', 't1', ['*']);
        $eventId = $this->orders->post('/orders/o-7/place', null, $user)->assertStatus(200)->json()['eventId'];
        self::assertSame([], $this->orders->http()->sent(), 'nothing is sent before the outbox is processed');

        self::assertSame(1, $this->orders->runJobs());
        $delivery = $this->orders->http()->sent()[0];
        self::assertSame('http://billing.internal/events', (string) $delivery->getUri());
        self::assertSame(CloudEvent::MEDIA_TYPE, $delivery->getHeaderLine('Content-Type'));
        $event = json_decode((string) $delivery->getBody(), true);
        self::assertSame(['1.0', $eventId, 'https://orders.example.com', 'order.placed', 'o-7', ['orderId' => 'o-7'], 't1'], [$event['specversion'], $event['id'], $event['source'], $event['type'], $event['subject'], $event['data'], $event['keeltenant']]);

        // A redelivery (the worker crashed after the POST) is acknowledged but not handled again.
        $again = $this->billing->send(new ServerRequest('POST', 'http://billing.internal/events', $delivery->getHeaders(), (string) $delivery->getBody()));
        $again->assertStatus(200)->assertJsonPath('handled', false)->assertJsonPath('total', 1);
    }

    public function testCloudEventsBinaryMode(): void
    {
        $event = CloudEvent::fromRequest(new ServerRequest('POST', '/events', ['ce-specversion' => '1.0', 'ce-id' => 'e1', 'ce-source' => '/billing', 'ce-type' => 'invoice.paid', 'Content-Type' => 'application/json'], '{"invoice":"i1"}'));
        self::assertSame(['e1', '/billing', 'invoice.paid', ['invoice' => 'i1']], [$event->id, $event->source, $event->type, $event->data]);

        $this->expectExceptionMessage('specversion');
        CloudEvent::fromRequest(new ServerRequest('POST', '/events', ['ce-specversion' => '0.3', 'ce-id' => 'e1', 'ce-source' => '/b', 'ce-type' => 't'], '{}'));
    }

    public function testDeploymentFiles(): void
    {
        $root = sys_get_temp_dir() . '/keel-deploy-' . getmypid();
        @mkdir($root);
        $generator = new DeploymentGenerator($root);
        self::assertSame(['Dockerfile', '.dockerignore'], $generator->docker());
        self::assertSame(['deploy/kubernetes/orders.yaml'], $generator->kubernetes('orders', 'registry.example.com/orders:1.0', 3));

        $dockerfile = (string) file_get_contents("$root/Dockerfile");
        self::assertStringContainsString('vendor/bin/keel build --env=prod', $dockerfile);
        self::assertStringContainsString('CMD ["vendor/bin/keel", "run", "--host=0.0.0.0", "--port=8080"]', $dockerfile);
        $yaml = (string) file_get_contents("$root/deploy/kubernetes/orders.yaml");
        foreach (['kind: Deployment', 'replicas: 3', 'path: /health/ready', 'path: /health/live', 'kind: HorizontalPodAutoscaler', 'kind: PodDisruptionBudget', 'minAvailable: 2', 'image: registry.example.com/orders:1.0', 'runAsNonRoot: true'] as $line) {
            self::assertStringContainsString($line, $yaml);
        }
        if (\function_exists('yaml_parse_file')) {
            self::assertCount(4, (array) yaml_parse($yaml, -1));
        }

        $this->expectException(\RuntimeException::class);
        try {
            $generator->docker();
        } finally {
            array_map('unlink', ["$root/Dockerfile", "$root/.dockerignore", "$root/deploy/kubernetes/orders.yaml"]);
        }
    }

    /** @param array<string, mixed> $config */
    private function boot(string $name, string $dsn, array $config): TestApp
    {
        @unlink(substr($dsn, 7));
        $app = TestApp::boot(__DIR__ . '/../Fixtures/Mesh/app.php', array_replace_recursive([
            'app' => ['key' => self::KEY],
            'service' => ['name' => $name],
            'database' => ['dsn' => getenv('KEEL_TEST_DATABASE_DSN') ? TestDatabase::dsn() : $dsn],
            'log' => ['stream' => 'php://memory'],
        ], $config));
        $app->freshDatabase(__DIR__ . '/../Fixtures/Mesh/migrations');

        return $app;
    }
}
