<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Order;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Process a checkout payment request.
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
            'product_id' => 'required|integer',
            'customer_name' => 'required|string',
            'customer_email' => 'required|email',
            'customer_document' => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'payment_method' => 'required|in:pix,credit_card',
        ]);

        $store = Store::resolveByDomain($validated['domain']);

        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $product = $store->products()->findOrFail($validated['product_id']);
        $gateway = $store->gateways()->where('provider', 'suitpay')->first();

        if (!$gateway || !$gateway->api_key) {
            return response()->json(['error' => 'Gateway not configured'], 400);
        }

        // Criar o pedido local como pending
        $order = Order::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'customer_name' => $validated['customer_name'],
            'customer_email' => $validated['customer_email'],
            'customer_document' => $validated['customer_document'] ?? null,
            'customer_phone' => $validated['customer_phone'] ?? null,
            'amount' => $product->price,
            'payment_method' => $validated['payment_method'],
            'status' => 'pending',
        ]);

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
