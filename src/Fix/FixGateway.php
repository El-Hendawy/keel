<?php

declare(strict_types=1);

namespace Keel\Fix;

use Keel\Container\Attribute\Singleton;
use Keel\Runtime\BuildManifest;

/**
 * Sends typed or raw messages on the sessions of this FIX process (`keel fix:run`).
 * Inject it into services that run in that process: handlers, or code they call.
 */
#[Singleton]
final class FixGateway
{
    /** @var array<string, Session> */
    private array $sessions = [];

    public function __construct(private readonly BuildManifest $manifest)
    {
    }

    /** @internal called by the Engine */
    public function attach(Session $session): void
    {
        $this->sessions[$session->settings->id] = $session;
    }

    public function session(string $id): Session
    {
        return $this->sessions[$id] ?? throw new FixError("No FIX session \"$id\" in this process.");
    }

    public function isLoggedOn(string $id): bool
    {
        return isset($this->sessions[$id]) && $this->sessions[$id]->isLoggedOn();
    }

    /** @param object $message a #[FixMessage] instance, or a raw Message */
    public function send(string $sessionId, object $message): void
    {
        $this->session($sessionId)->send($this->toMessage($message), microtime(true));
    }

    public function toMessage(object $message): Message
    {
        if ($message instanceof Message) {
            return $message;
        }
        $fix = $this->manifest->data['fix'] ?? null;
        $type = $fix['classes'][$message::class] ?? throw new FixError($message::class . ' is not a #[FixMessage] class.');
        $encode = [$fix['codec'], $fix['messages'][$type]['encoder']];
        \assert(\is_callable($encode));
        /** @var list<array{0: int, 1: string}> $fields */
        $fields = $encode($message);

        return new Message((string) $type, $fields);
    }

    /** @return object the typed message for a raw one */
    public function toObject(Message $message): object
    {
        $fix = $this->manifest->data['fix'] ?? null;
        $codec = $fix['messages'][$message->type] ?? throw new FixError("Unsupported MsgType {$message->type}.", 11, Tag::MSG_TYPE);
        $decode = [$fix['codec'], $codec['decoder']];
        \assert(\is_callable($decode));
        $object = $decode($message->fields);
        \assert(\is_object($object));

        return $object;
    }
}
