<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\Webhook;
use App\Services\AchievementService;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;

class OrderObserver
{
    public function created(Order $order): void
    {
        $this->afterCommit($order->id, Webhook::EVENT_ORDER_CREATED);
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('status')) {
            if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_AUTHORIZED], true)) {
                $this->afterCommit($order->id, Webhook::EVENT_ORDER_PAID);
            }

            if ($order->payment_method === 'credit_card'
                && in_array($order->status, [Order::STATUS_REFUSED, Order::STATUS_FAILED], true)) {
                $this->afterCommit($order->id, Webhook::EVENT_ORDER_REFUSED);
            }
        }

        if ($order->payment_method === 'pix'
            && $order->pix_copia_cola
            && ($order->wasChanged('pix_copia_cola') || $order->wasChanged('status'))) {
            $this->afterCommit($order->id, Webhook::EVENT_PIX_CREATED);
        }
    }

    public function saved(Order $order): void
    {
        if (! $order->wasRecentlyCreated && ! $order->wasChanged('status') && ! $order->wasChanged('amount')) {
            return;
        }

        app(AchievementService::class)->synchronize($order->store()->first());
    }

    private function afterCommit(int $orderId, string $eventType): void
    {
        DB::afterCommit(function () use ($orderId, $eventType) {
            app(WebhookService::class)->dispatchOrderEvent($orderId, $eventType);
        });
    }
}
