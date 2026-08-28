<?php

namespace App\Services;

use App\Models\Gateway;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Upsell;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PostPurchasePixService
{
    /** Store the extra payment separately; the original purchase remains paid. */
    public function stage(Order $order, Upsell $offer, Product $product, float $amount, ?array $attributes, Gateway $gateway, array $result): array
    {
        $data = $result['data'] ?? $result;
        $pix = $data['pix'] ?? [];
        $transactionId = $data['id'] ?? $data['transaction_id'] ?? null;
        $code = $pix['qrcode'] ?? $pix['copyPaste'] ?? null;
        if (!$transactionId || !$code) {
            throw new RuntimeException('A gateway não retornou os dados completos do Pix adicional.');
        }

        $type = $offer->offer_type === 'downsell' ? 'downsell' : 'upsell';
        $payment = [
            'offer_type' => $type,
            'offer_id' => $offer->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'attributes' => $attributes ?? $product->attributes,
            'gateway_id' => $gateway->id,
            'amount' => $amount,
            'status' => Order::STATUS_WAITING_PAYMENT,
            'code' => $code,
            'expires_at' => $pix['expirationDate'] ?? $data['expiration_date'] ?? $data['expires_at'] ?? null,
            'created_at' => now()->toISOString(),
            'applied' => false,
        ];

        DB::transaction(function () use ($order, $type, $transactionId, $payment) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->post_purchase_pix_transaction_id) {
                throw new RuntimeException('Este pedido já possui um Pix adicional.');
            }
            $locked->update([
                "{$type}_status" => 'accepted',
                'post_purchase_pix_transaction_id' => (string) $transactionId,
                'post_purchase_pix' => $payment,
            ]);
        });

        // Some gateways can return an already-approved transaction.
        $this->handleWebhook((string) $transactionId, $data['status'] ?? null);
        $order->refresh();

        return [
            'success' => true,
            'pix_qrcode' => null,
            'pix_copia_cola' => $code,
            'gateway_expires_at' => $payment['expires_at'],
        ];
    }

    /** Called only after the payment controller has authenticated the webhook. */
    public function handleWebhook(string $transactionId, ?string $gatewayStatus): bool
    {
        $item = null;
        $order = DB::transaction(function () use ($transactionId, $gatewayStatus, &$item) {
            $order = Order::query()->where('post_purchase_pix_transaction_id', $transactionId)
                ->lockForUpdate()->first();
            if (!$order) {
                return null;
            }
            $payment = $order->post_purchase_pix;
            $status = Order::mapFastSoftStatus($gatewayStatus);
            if (!$payment || !$status || ($payment['applied'] ?? false)) {
                return $order;
            }

            $payment['status'] = $status;
            if (in_array($status, [Order::STATUS_PAID, Order::STATUS_AUTHORIZED], true)) {
                $type = $payment['offer_type'];
                $productId = Product::where('store_id', $order->store_id)->whereKey($payment['product_id'])->value('id');
                $offerId = Upsell::where('store_id', $order->store_id)->whereKey($payment['offer_id'])->value('id');
                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'name' => $payment['product_name'],
                    'qty' => 1,
                    'unit_price' => $payment['amount'],
                    'attributes' => $payment['attributes'],
                ]);
                $payment['applied'] = true;
                $order->fill([
                    'amount' => round((float) $order->amount + (float) $payment['amount'], 2),
                    "{$type}_id" => $offerId,
                    "{$type}_product_id" => $productId,
                    "{$type}_amount" => $payment['amount'],
                ]);
            }
            $order->fill(['post_purchase_pix' => $payment])->save();

            return $order;
        });

        if ($item && $order) {
            app(PaymentIdempotencyService::class)->resolveFromOrder($order);
            try {
                if ($order->store?->isShopifyConnected()) {
                    app(ShopifyOrderSync::class)->syncExtraItem($order->store, $order, $item);
                }
            } catch (\Throwable $exception) {
                Log::warning('Shopify sync do Pix adicional falhou', [
                    'order_id' => $order->id, 'message' => $exception->getMessage(),
                ]);
            }
        }

        return $order !== null;
    }
}
