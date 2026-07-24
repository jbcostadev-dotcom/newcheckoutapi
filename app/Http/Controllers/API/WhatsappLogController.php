<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsappLog;
use Illuminate\Http\Request;

class WhatsappLogController extends Controller
{
    /**
     * Lista os logs (entregas) de WhatsApp da loja.
     * Filtros: status (sent|failed|all), event.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $query = $store->whatsappLogs()->with(['template:id,name,event', 'instance:id,instance_name']);

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        $status = $request->get('status', 'failed');
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $logs = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($logs);
    }

    /**
     * Remove um log.
     */
    public function destroy(Request $request, string $storeId, string $logId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $log = $store->whatsappLogs()->findOrFail($logId);
        $log->delete();

        return response()->json(null, 204);
    }
}