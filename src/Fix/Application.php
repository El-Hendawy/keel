<?php

declare(strict_types=1);

namespace Keel\Fix;

use Keel\Container\Container;
use Keel\Runtime\BuildManifest;
use Psr\Log\LoggerInterface;

/**
 * Routes inbound application messages to the compiled #[FixHandler]s, each in its own
 * scope. Invalid fields become a session Reject; unsupported types and handler failures a
 * BusinessMessageReject, so the counterparty always learns what happened to its message.
 */
final readonly class Application
{
    public function __construct(private Container $container)
    {
    }

    public function __invoke(Message $message, Session $session): void
    {
        $fix = $this->container->get(BuildManifest::class)->data['fix'] ?? null;
        $handler = $fix['handlers'][$message->type] ?? null;
        $now = microtime(true);
        if ($handler === null) {
            $session->businessReject($message, 3, "Unsupported MsgType {$message->type}.", $now);

            return;
        }
        $gateway = $this->container->get(FixGateway::class);
        $typed = $gateway->toObject($message); // FixError (bad field) propagates: the session rejects it
        $scope = $this->container->createScope();
        try {
            $callable = $scope->get($handler);
            \assert(\is_callable($callable));
            $callable($typed, new FixContext($session->settings->id, $message, $gateway));
        } catch (\Throwable $e) {
            $this->container->get(LoggerInterface::class)->error('FIX handler {handler} failed on seq {seq}: {error}', [
                'handler' => $handler, 'seq' => $message->seqNum(), 'error' => $e->getMessage(), 'exception' => $e,
            ]);
            $session->businessReject($message, 0, 'The message could not be processed.', $now);
        } finally {
            $scope->dispose();
        }
    }
}
