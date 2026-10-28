<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateInvoiceWhenOrderPlaced
{
    public function handle(OrderPlaced $event): void
    {
        DB::table('invoices')->insert([
            'id' => (string) Str::orderedUuid(),
            'tenant_id' => $event->tenantId,
            'order_id' => $event->orderId,
            'amount_cents' => $event->totalCents,
            'status' => 'open',
            'created_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ]);
    }
}
