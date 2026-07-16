<?php

namespace App\Http\Controllers\API;

use App\Exceptions\UnipayException;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Order;
use App\Services\UnipayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Processa um checkout com 1 ou N produtos via Unipay (FastSoft Brasil).
     *
     * Payload:
     *   domain, items: [{product_id, qty}], customer_*, payment_method (pix|credit_card|boleto)
     *   credit_card: card_token, installments, card_brand?, card_last4?
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
            'payment_method' => 'required|in:pix,credit_card,boleto',
            'card_token' => 'required_if:payment_method,credit_card|string|max:500',
            'installments' => 'nullable|integer|min:1|max:12',
            'card_brand' => 'nullable|string|max:30',
            'card_last4' => 'nullable|string|max:4',
            'shipping_method_id' => 'nullable|integer',
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

        $gateway = $store->gateways()->where('provider', 'unipay')->first();
        if (!$gateway || !$gateway->secret_key) {
            return response()->json(['error' => 'Gateway Unipay not configured'], 400);
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

        // Calcula valor do frete.
        $shippingMethodId = $validated['shipping_method_id'] ?? null;
        $shippingPrice = null;

        if ($shippingMethodId) {
            $shippingMethod = $store->shippingMethods()
                ->where('id', $shippingMethodId)
                ->where('is_active', true)
                ->first();

            if (!$shippingMethod) {
                return response()->json(['error' => 'Shipping method not found or inactive'], 400);
            }

            $methodPrice = $shippingMethod->price ? (float) $shippingMethod->price : 0;
            $minValueFree = $shippingMethod->min_value_free_shipping
                ? (float) $shippingMethod->min_value_free_shipping
                : null;

            $shippingPrice = ($minValueFree !== null && $total >= $minValueFree) ? 0 : $methodPrice;
        }

        $finalTotal = round($total + ($shippingPrice ?? 0), 2);

        // Cria pedido + itens em transação (atomicidade).
        $order = DB::transaction(function () use ($store, $validated, $orderItemsData, $finalTotal, $shippingMethodId, $shippingPrice) {
            $ship = $validated['shipping_address'] ?? [];
            $order = Order::create([
                'store_id' => $store->id,
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'customer_document' => $validated['customer_document'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'amount' => $finalTotal,
                'payment_method' => $validated['payment_method'],
                'status' => Order::STATUS_PENDING,
                'installments' => $validated['installments'] ?? 1,
                'shipping_method_id' => $shippingMethodId,
                'shipping_price' => $shippingPrice,
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

        $service = new UnipayService($gateway);
        $postbackUrl = rtrim(config('app.url'), '/') . '/api/webhook/unipay';
        $ip = $request->ip();

        try {
            switch ($validated['payment_method']) {
                case 'pix':
                    $payload = UnipayService::buildPixPayload($order, $postbackUrl, 1, $ip);
                    break;

                case 'credit_card':
                    if (empty($validated['card_token'])) {
                        return response()->json(['error' => 'card_token é obrigatório para credit_card'], 422);
                    }
                    $order->update([
                        'card_token' => $validated['card_token'],
                        'card_brand' => $validated['card_brand'] ?? null,
                        'card_last4' => $validated['card_last4'] ?? null,
                        'installments' => $validated['installments'] ?? 1,
                    ]);
                    $payload = UnipayService::buildCardPayload(
                        $order,
                        $validated['card_token'],
                        (int) ($validated['installments'] ?? 1),
                        $postbackUrl,
                        $ip
                    );
                    break;

                case 'boleto':
                    $payload = UnipayService::buildBoletoPayload($order, $postbackUrl, 3, $ip);
                    break;

                default:
                    return response()->json(['error' => 'Unsupported payment_method'], 422);
            }

            $result = $service->createTransaction($payload);
        } catch (UnipayException $e) {
            Log::error('Unipay createTransaction falhou', [
                'order_id' => $order->id,
                'status' => $e->statusCode,
                'body' => $e->body,
                'message' => $e->getMessage(),
            ]);

            $order->update(['status' => Order::STATUS_FAILED]);

            return response()->json([
                'error' => 'Falha ao criar transação na Unipay',
                'message' => $e->getMessage(),
                'details' => $e->body,
            ], 422);
        }

        // Persiste dados retornados pela Unipay.
        $updateData = [];
        $transactionId = $result['id'] ?? $result['data']['id'] ?? null;
        if ($transactionId) {
            $updateData['gateway_transaction_id'] = (string) $transactionId;
        }

        $returnedStatus = $result['status'] ?? $result['data']['status'] ?? null;
        $mappedStatus = Order::mapFastSoftStatus($returnedStatus);
        if ($mappedStatus) {
            $updateData['status'] = $mappedStatus;
        } elseif ($validated['payment_method'] !== 'credit_card') {
            $updateData['status'] = Order::STATUS_WAITING_PAYMENT;
        } else {
            $updateData['status'] = Order::STATUS_PROCESSING;
        }

        // PIX: qrcode (copia e cola) + expiração.
        $pixData = $result['pix'] ?? $result['data']['pix'] ?? null;
        if (is_array($pixData)) {
            if (!empty($pixData['qrcode'])) {
                $updateData['pix_copia_cola'] = $pixData['qrcode'];
            }
            if (!empty($pixData['expirationDate'])) {
                $updateData['gateway_expires_at'] = $pixData['expirationDate'];
            }
        }

        // Boleto: url + barcode + linha digitável.
        $boletoData = $result['boleto'] ?? $result['data']['boleto'] ?? null;
        if (is_array($boletoData)) {
            if (!empty($boletoData['url'])) {
                $updateData['boleto_url'] = $boletoData['url'];
            }
            if (!empty($boletoData['barcode'])) {
                $updateData['boleto_barcode'] = $boletoData['barcode'];
            }
            if (!empty($boletoData['digitableLine'])) {
                $updateData['boleto_digitable_line'] = $boletoData['digitableLine'];
            }
            if (!empty($boletoData['expirationDate'])) {
                $updateData['gateway_expires_at'] = $boletoData['expirationDate'];
            }
        }

        // Cartão: brand + last4 se retornados.
        $cardData = $result['card'] ?? $result['data']['card'] ?? null;
        if (is_array($cardData)) {
            if (!empty($cardData['brand'])) {
                $updateData['card_brand'] = $cardData['brand'];
            }
            if (!empty($cardData['lastDigits'])) {
                $updateData['card_last4'] = $cardData['lastDigits'];
            }
        }

        if (!empty($updateData)) {
            $order->update($updateData);
        }

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->fresh()->status,
            'payment_method' => $order->payment_method,
            'gateway_transaction_id' => $order->gateway_transaction_id,
            'pix_qrcode' => $order->pix_qrcode,
            'pix_copia_cola' => $order->pix_copia_cola,
            'boleto_url' => $order->boleto_url,
            'boleto_barcode' => $order->boleto_barcode,
            'boleto_digitable_line' => $order->boleto_digitable_line,
            'card_brand' => $order->card_brand,
            'card_last4' => $order->card_last4,
            'installments' => $order->installments,
            'gateway_expires_at' => $order->gateway_expires_at?->toISOString(),
        ]);
    }

    /**
     * Retorna o status de pagamento/pedido (PIX, cartão ou boleto).
     */
    public function getPixStatus(int $orderId)
    {
        $order = Order::with('store')->find($orderId);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'pix_qrcode' => $order->pix_qrcode,
            'pix_copia_cola' => $order->pix_copia_cola,
            'boleto_url' => $order->boleto_url,
            'boleto_barcode' => $order->boleto_barcode,
            'boleto_digitable_line' => $order->boleto_digitable_line,
            'card_brand' => $order->card_brand,
            'card_last4' => $order->card_last4,
            'installments' => $order->installments,
            'total' => $order->amount,
            'gateway_expires_at' => $order->gateway_expires_at?->toISOString(),
            'created_at' => $order->created_at?->toISOString(),
            'store_name' => $order->store?->name,
        ]);
    }

    /**
     * Recebe o webhook/postback da Unipay (FastSoft Brasil).
     *
     * Payload esperado (conforme doc):
     *   { type, objectId, data: { id, status, pix, boleto, card, ... } }
     *
     * Decisão: sem verificação de assinatura/IP (mesmo padrão do mock anterior).
     */
    public function webhook(Request $request)
    {
        $payload = $request->all();

        $data = $payload['data'] ?? $payload;
        $transactionId = $data['id'] ?? $payload['objectId'] ?? $payload['transaction_id'] ?? null;
        $status = $data['status'] ?? $payload['status'] ?? null;

        if (!$transactionId) {
            return response()->json(['error' => 'Invalid payload: missing transaction id'], 400);
        }

        $order = Order::where('gateway_transaction_id', (string) $transactionId)->first();

        if ($order) {
            $mapped = Order::mapFastSoftStatus($status);
            if ($mapped) {
                $order->update(['status' => $mapped]);
            }

            // Atualiza campos extras se vieram no webhook.
            $extra = [];

            $pixData = $data['pix'] ?? null;
            if (is_array($pixData)) {
                if (!empty($pixData['qrcode']) && !$order->pix_copia_cola) {
                    $extra['pix_copia_cola'] = $pixData['qrcode'];
                }
                if (!empty($pixData['expirationDate']) && !$order->gateway_expires_at) {
                    $extra['gateway_expires_at'] = $pixData['expirationDate'];
                }
            }

            $boletoData = $data['boleto'] ?? null;
            if (is_array($boletoData)) {
                if (!empty($boletoData['url']) && !$order->boleto_url) {
                    $extra['boleto_url'] = $boletoData['url'];
                }
                if (!empty($boletoData['barcode']) && !$order->boleto_barcode) {
                    $extra['boleto_barcode'] = $boletoData['barcode'];
                }
                if (!empty($boletoData['digitableLine']) && !$order->boleto_digitable_line) {
                    $extra['boleto_digitable_line'] = $boletoData['digitableLine'];
                }
                if (!empty($boletoData['expirationDate']) && !$order->gateway_expires_at) {
                    $extra['gateway_expires_at'] = $boletoData['expirationDate'];
                }
            }

            $cardData = $data['card'] ?? null;
            if (is_array($cardData)) {
                if (!empty($cardData['brand']) && !$order->card_brand) {
                    $extra['card_brand'] = $cardData['brand'];
                }
                if (!empty($cardData['lastDigits']) && !$order->card_last4) {
                    $extra['card_last4'] = $cardData['lastDigits'];
                }
            }

            if (!empty($extra)) {
                $order->update($extra);
            }
        }

        return response()->json(['received' => true]);
    }
}