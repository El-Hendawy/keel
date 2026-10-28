<?php

declare(strict_types=1);

namespace Shop\Billing;

use Keel\Container\Attribute\Scoped;
use Keel\Database\Database;
use Keel\Support\IdGenerator;
use Psr\Clock\ClockInterface;
use Shop\Tenancy\CurrentTenant;

#[Scoped]
final readonly class InvoiceService
{
    public function __construct(
        private Database $db,
        private CurrentTenant $tenant,
        private IdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    public function createFor(string $orderId, int $amountCents): Invoice
    {
        $invoice = new Invoice($this->ids->generate(), $orderId, $amountCents, 'open', $this->clock->now());
        $this->db->execute(
            'INSERT INTO invoices (id, tenant_id, order_id, amount_cents, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$invoice->id, $this->tenant->id, $orderId, $amountCents, $invoice->status, $invoice->createdAt->format(\DATE_RFC3339_EXTENDED)],
        );

        return $invoice;
    }

    public function forOrder(string $orderId): ?Invoice
    {
        $row = $this->db->selectOne('SELECT * FROM invoices WHERE order_id = ? AND tenant_id = ?', [$orderId, $this->tenant->id]);

        return $row === null ? null : new Invoice(
            $row->string('id'),
            $row->string('order_id'),
            $row->int('amount_cents'),
            $row->string('status'),
            $row->dateTime('created_at'),
        );
    }
}
