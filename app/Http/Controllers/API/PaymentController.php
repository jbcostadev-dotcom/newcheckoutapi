<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Processa um checkout com 1 ou N produtos.
     *
     * Payload:
     *   domain, items: [{product_id, qty}], customer_*, payment_method
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty' => 'nullable|integer|min:1',
            'customer_name' => 'required|string',
            'customer_email' => 'required|email',
            'customer_document' => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'payment_method' => 'required|in:pix,credit_card',
            'shipping_address' => 'nullable|array',
            'shipping_address.cep' => 'nullable|string|max:9',
            'shipping_address.logradouro' => 'nullable|string|max:255',
            'shipping_address.numero' => 'nullable|string|max:30',
            'shipping_address.complemento' => 'nullable|string|max:120',
            'shipping_address.bairro' => 'nullable|string|max:120',
            'shipping_address.cidade' => 'nullable|string|max:120',
            'shipping_address.uf' => 'nullable|string|max:2',
        ]);

        $store = Store::resolveByDomain($validated['domain']);

        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $gateway = $store->gateways()->where('provider', 'suitpay')->first();
        if (!$gateway || !$gateway->api_key) {
            return response()->json(['error' => 'Gateway not configured'], 400);
        }

        // Agrupa itens por product_id somando qty (defensivo contra duplicação).
        $grouped = [];
        foreach ($validated['items'] as $item) {
            $pid = (int) $item['product_id'];
            $qty = max(1, (int) ($item['qty'] ?? 1));
            if (isset($grouped[$pid])) {
                $grouped[$pid] += $qty;
            } else {
                $grouped[$pid] = $qty;
            }
        }

        $productIds = array_keys($grouped);
        $products = $store->products()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return response()->json(['error' => 'No active products found'], 404);
        }

        // Calcula total e monta lista de itens com snapshot.
        $orderItemsData = [];
        $total = 0.0;

        foreach ($grouped as $pid => $qty) {
            $product = $products->get($pid);
            if (!$product) {
                return response()->json(['error' => "Product {$pid} not found or inactive"], 404);
            }
            $unitPrice = (float) $product->price;
            $orderItemsData[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'unit_price' => $unitPrice,
                'qty' => $qty,
            ];
            $total += $unitPrice * $qty;
        }

        $total = round($total, 2);

        // Cria pedido + itens em transação (atomicidade).
        $order = DB::transaction(function () use ($store, $validated, $orderItemsData, $total) {
            $ship = $validated['shipping_address'] ?? [];
            $order = Order::create([
                'store_id' => $store->id,
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'customer_document' => $validated['customer_document'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'amount' => $total,
                'payment_method' => $validated['payment_method'],
                'status' => 'pending',
                'shipping_cep' => $ship['cep'] ?? null,
                'shipping_logradouro' => $ship['logradouro'] ?? null,
                'shipping_numero' => $ship['numero'] ?? null,
                'shipping_complemento' => $ship['complemento'] ?? null,
                'shipping_bairro' => $ship['bairro'] ?? null,
                'shipping_cidade' => $ship['cidade'] ?? null,
                'shipping_uf' => $ship['uf'] ?? null,
            ]);

            foreach ($orderItemsData as $itemData) {
                $order->items()->create($itemData);
            }

            return $order;
        });

        // Simulação da integração Suitpay (Mock para ambiente de desenvolvimento)
        // TODO: Substituir por chamada HTTP real à API da Suitpay.
        if ($validated['payment_method'] === 'pix') {
            $order->update([
                'gateway_transaction_id' => 'SUITPAY_PIX_' . rand(1000, 9999),
                'pix_qrcode' => 'base64_img_mock',
                'pix_copia_cola' => '00020126580014br.gov.bcb.pix...mock',
            ]);

            return response()->json([
                'order_id' => $order->id,
                'status' => 'pending',
                'pix_qrcode' => $order->pix_qrcode,
                'pix_copia_cola' => $order->pix_copia_cola,
            ]);
        }

        // Simulação de cartão de crédito transparente
        $order->update([
            'status' => 'paid',
            'gateway_transaction_id' => 'SUITPAY_CC_' . rand(1000, 9999),
        ]);

        return response()->json([
            'order_id' => $order->id,
            'status' => 'paid',
            'message' => 'Pagamento aprovado com sucesso',
        ]);
    }

    /**
     * Receive webhook / postback from Suitpay.
     */
    public function webhook(Request $request)
    {
        $transactionId = $request->input('transaction_id');
        $status = $request->input('status');

        if (!$transactionId) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $order = Order::where('gateway_transaction_id', $transactionId)->first();

        if ($order) {
            if ($status === 'PAID') {
                $order->update(['status' => 'paid']);
            } elseif ($status === 'REFUSED') {
                $order->update(['status' => 'failed']);
            }
        }

        return response()->json(['received' => true]);
    }
}