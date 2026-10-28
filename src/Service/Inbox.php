<?php

declare(strict_types=1);

namespace Keel\Service;

use Keel\Container\Attribute\Scoped;
use Keel\Database\Database;
use Psr\Clock\ClockInterface;

/**
 * The receiving side of at-least-once delivery: handles each event id once.
 *
 *     #[Post('/events')]
 *     #[Authorize(scopes: ['events:publish'])]
 *     final class ReceiveEvents
 *     {
 *         public function __invoke(ServerRequestInterface $request, Inbox $inbox, Invoices $invoices): ResponseInterface
 *         {
 *             $event = CloudEvent::fromRequest($request);
 *             $inbox->once($event, fn () => match ($event->type) {
 *                 'order.placed' => $invoices->open($event->data['orderId']),
 *                 default => null,
 *             });
 *
 *             return Responses::empty(202);
 *         }
 *     }
 *
 * The event id is recorded in `keel_inbox` in the same transaction as the work, so a redelivery
 * after a crash either finds the work done or does it: never twice, never lost.
 */
#[Scoped]
final readonly class Inbox
{
    public function __construct(private Database $db, private ClockInterface $clock)
    {
    }

    /**
     * Runs $work unless this event was handled before.
     *
     * @param \Closure(): mixed $work
     * @return bool whether $work ran
     */
    public function once(CloudEvent $event, \Closure $work): bool
    {
        return $this->db->transaction(function (Database $db) use ($event, $work): bool {
            $key = substr(hash('sha256', $event->source . "\0" . $event->id), 0, 64);
            if ($db->selectOne('SELECT event_key FROM keel_inbox WHERE event_key = ?', [$key]) !== null) {
                return false;
            }
            $db->execute('INSERT INTO keel_inbox (event_key, source, event_id, type, received_at) VALUES (?, ?, ?, ?, ?)', [
                $key, substr($event->source, 0, 255), substr($event->id, 0, 255), substr($event->type, 0, 255), $this->clock->now()->getTimestamp(),
            ]);
            $work();

            return true;
        });
    }
}
