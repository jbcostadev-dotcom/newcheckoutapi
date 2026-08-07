<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AchievementService;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function index(Request $request, string $storeId, AchievementService $service)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $items = $service->catalogForStore($store)->values();

        return response()->json([
            'summary' => [
                'revenue_total' => $service->metricValues($store)['revenue_total'] / 100,
                'unlocked_count' => $items->where('unlocked', true)->count(),
                'total_count' => $items->count(),
            ],
            'plates' => $items->where('type', 'plate')->values(),
            'badges' => $items->where('type', 'badge')->values(),
        ]);
    }
}
