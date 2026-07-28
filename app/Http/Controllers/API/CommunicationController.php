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

        // Garante que o pedido pertence a uma loja do usuário autenticado.
        $userStoreIds = $request->user()->stores()->pluck('stores.id')->toArray();
        if (! in_array($order->store_id, $userStoreIds, true)) {
            return response()->json(['error' => 'Pedido não encontrado.'], 404);
        }

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
