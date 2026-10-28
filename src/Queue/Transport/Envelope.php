<?php

declare(strict_types=1);

namespace Keel\Queue\Transport;

/** A job as it travels through a broker. */
final readonly class Envelope
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $type,
        public string $payload,
        public ?string $tenantId = null,
        public ?string $traceparent = null,
        public int $attempts = 0,
    ) {
    }

    public function encode(): string
    {
        return json_encode(['id' => $this->id, 'queue' => $this->queue, 'type' => $this->type, 'payload' => $this->payload, 'tenant' => $this->tenantId, 'trace' => $this->traceparent, 'attempts' => $this->attempts], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $json): self
    {
        $d = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($d) || !\is_string($d['id'] ?? null) || !\is_string($d['queue'] ?? null) || !\is_string($d['type'] ?? null) || !\is_string($d['payload'] ?? null)) {
            throw new \UnexpectedValueException('Malformed job envelope.');
        }

        return new self(
            $d['id'],
            $d['queue'],
            $d['type'],
            $d['payload'],
            \is_string($d['tenant'] ?? null) ? $d['tenant'] : null,
            \is_string($d['trace'] ?? null) ? $d['trace'] : null,
            \is_int($d['attempts'] ?? null) ? $d['attempts'] : 0,
        );
    }

    public function withAttempts(int $attempts): self
    {
        return new self($this->id, $this->queue, $this->type, $this->payload, $this->tenantId, $this->traceparent, $attempts);
    }
}
