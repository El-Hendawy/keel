<?php

declare(strict_types=1);

namespace Keel\Fix;

/** What a FIX handler knows about the message it is handling, and how to answer. */
final readonly class FixContext
{
    public function __construct(
        public string $sessionId,
        public Message $raw,
        private FixGateway $gateway,
    ) {
    }

    /** Sends a message back on the session the current message came from. */
    public function reply(object $message): void
    {
        $this->gateway->send($this->sessionId, $message);
    }

    /** Sends on another session of this process, e.g. to route an order to a venue. */
    public function send(string $sessionId, object $message): void
    {
        $this->gateway->send($sessionId, $message);
    }
}
