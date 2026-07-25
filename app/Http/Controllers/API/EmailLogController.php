<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    /**
     * Lista os logs (entregas) de e-mail da loja.
     * Filtros: status (sent|failed|all), event.
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $query = $store->emailLogs()->with([
            'template:id,name,event',
            'smtpSetting:id,name,host',
        ]);

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
        $log = $store->emailLogs()->findOrFail($logId);
        $log->delete();

        return response()->json(null, 204);
    }
}
