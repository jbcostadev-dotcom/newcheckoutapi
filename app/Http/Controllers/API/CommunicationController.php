<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class CommunicationController extends Controller
{
    /**
     * Dispara envio de mensagens e email para o pedido (Mock)
     */
    public function notifyOrder(Request $request, string $orderId)
    {
        $order = Order::with('store')->findOrFail($orderId);

        // Mock Email
        $emailSent = true;
        // Mock WhatsApp
        $whatsappSent = true;

        return response()->json([
            'message' => 'Notificações disparadas com sucesso',
            'order_id' => $order->id,
            'email_sent' => $emailSent,
            'whatsapp_sent' => $whatsappSent
        ]);
    }
}
