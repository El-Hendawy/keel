<?php

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The same three endpoints as the Keel example, with the query builder (not Eloquent) for a like-for-like comparison. */
final class OrderController
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'merchantId' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku' => ['required', 'string', 'min:1', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'between:1,1000'],
            'items.*.unitPriceCents' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $id = (string) Str::orderedUuid();
        $createdAt = now()->format('Y-m-d\TH:i:s.vP');
        $total = array_sum(array_map(fn (array $i) => $i['quantity'] * $i['unitPriceCents'], $data['items']));

        DB::transaction(function () use ($id, $tenant, $data, $total, $createdAt) {
            DB::table('orders')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'merchant_id' => $data['merchantId'], 'status' => 'placed',
                'note' => $data['note'] ?? null, 'total_cents' => $total, 'created_at' => $createdAt,
            ]);
            foreach ($data['items'] as $position => $item) {
                DB::table('order_items')->insert([
                    'order_id' => $id, 'position' => $position, 'sku' => $item['sku'], 'quantity' => $item['quantity'], 'unit_price_cents' => $item['unitPriceCents'],
                ]);
            }
            event(new OrderPlaced($id, $tenant, $total));
        });

        return response()->json([
            'id' => $id, 'merchantId' => $data['merchantId'], 'status' => 'placed', 'note' => $data['note'] ?? null,
            'items' => array_map(fn (array $i) => ['sku' => $i['sku'], 'quantity' => $i['quantity'], 'unitPriceCents' => $i['unitPriceCents']], $data['items']),
            'totalCents' => $total, 'createdAt' => $createdAt,
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenant($request);
        $order = DB::table('orders')->where('id', $id)->where('tenant_id', $tenant)->first();
        if ($order === null) {
            return response()->json(['title' => 'Not Found', 'status' => 404, 'detail' => "Order $id does not exist."], 404);
        }
        $items = DB::table('order_items')->where('order_id', $id)->orderBy('position')->get();

        return response()->json([
            'id' => $order->id, 'merchantId' => $order->merchant_id, 'status' => $order->status, 'note' => $order->note,
            'items' => $items->map(fn ($i) => ['sku' => $i->sku, 'quantity' => (int) $i->quantity, 'unitPriceCents' => (int) $i->unit_price_cents])->all(),
            'totalCents' => (int) $order->total_cents, 'createdAt' => $order->created_at,
        ]);
    }

    private function tenant(Request $request): string
    {
        $id = (string) $request->header('X-Tenant-Id', '');
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 400, 'Send the tenant id as a UUID in the X-Tenant-Id header.');

        return strtolower($id);
    }
}
