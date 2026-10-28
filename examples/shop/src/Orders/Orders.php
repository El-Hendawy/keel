<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Container\Attribute\Scoped;
use Keel\Database\Database;
use Keel\Database\Row;
use Keel\Support\IdGenerator;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shop\Tenancy\CurrentTenant;

/** Order persistence and rules for the current tenant. */
#[Scoped]
final readonly class Orders
{
    public function __construct(
        private Database $db,
        private CurrentTenant $tenant,
        private IdGenerator $ids,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    public function create(CreateOrderInput $input): Order
    {
        $lines = array_map(
            static fn (LineItem $item): OrderLine => new OrderLine($item->sku, $item->quantity, $item->unitPriceCents),
            $input->items,
        );
        $order = new Order(
            $this->ids->generate(),
            $this->tenant->id,
            $input->merchantId,
            OrderStatus::Placed,
            $input->note,
            $lines,
            array_sum(array_map(static fn (OrderLine $l): int => $l->quantity * $l->unitPriceCents, $lines)),
            $this->clock->now(),
        );

        $this->db->transaction(function (Database $db) use ($order): void {
            $db->execute(
                'INSERT INTO orders (id, tenant_id, merchant_id, status, note, total_cents, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$order->id, $order->tenantId, $order->merchantId, $order->status->value, $order->note, $order->totalCents, $order->createdAt->format(\DATE_RFC3339_EXTENDED)],
            );
            foreach ($order->lines as $position => $line) {
                $db->execute(
                    'INSERT INTO order_items (order_id, position, sku, quantity, unit_price_cents) VALUES (?, ?, ?, ?, ?)',
                    [$order->id, $position, $line->sku, $line->quantity, $line->unitPriceCents],
                );
            }
            // Listeners run inside the transaction: an invoice failure rolls back the order.
            $this->events->dispatch(new OrderPlaced($order->id, $order->tenantId, $order->totalCents));
        });

        return $order;
    }

    public function find(string $id): ?Order
    {
        $row = $this->db->selectOne('SELECT * FROM orders WHERE id = ? AND tenant_id = ?', [$id, $this->tenant->id]);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /** @return list<Order> */
    public function latest(int $limit, ?OrderStatus $status): array
    {
        $rows = $status === null
            ? $this->db->select('SELECT * FROM orders WHERE tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT ?', [$this->tenant->id, $limit])
            : $this->db->select('SELECT * FROM orders WHERE tenant_id = ? AND status = ? ORDER BY created_at DESC, id DESC LIMIT ?', [$this->tenant->id, $status->value, $limit]);

        return $this->hydrate($rows);
    }

    /**
     * Loads the lines of every order in one query: explicit eager loading, no N+1.
     *
     * @param list<Row> $rows
     * @return list<Order>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (Row $row): string => $row->string('id'), $rows);
        $lines = [];
        foreach ($this->db->select('SELECT * FROM order_items WHERE order_id IN (:ids) ORDER BY order_id, position', ['ids' => $ids]) as $item) {
            $lines[$item->string('order_id')][] = new OrderLine($item->string('sku'), $item->int('quantity'), $item->int('unit_price_cents'));
        }

        return array_map(static fn (Row $row): Order => new Order(
            $row->string('id'),
            $row->string('tenant_id'),
            $row->string('merchant_id'),
            OrderStatus::from($row->string('status')),
            $row->nullableString('note'),
            $lines[$row->string('id')] ?? [],
            $row->int('total_cents'),
            $row->dateTime('created_at'),
        ), $rows);
    }
}
