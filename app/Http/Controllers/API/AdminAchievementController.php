<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\AdminAuditLog;
use App\Models\Store;
use App\Services\AchievementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminAchievementController extends Controller
{
    public function index(AchievementService $service)
    {
        return response()->json(Achievement::query()->orderBy('type')->orderBy('sort_order')->get()
            ->map(fn (Achievement $item) => $service->payload($item, 0))->values());
    }

    public function store(Request $request, AchievementService $service)
    {
        $achievement = Achievement::create($this->validatedData($request));
        $this->audit($request, 'created', $achievement, null, $achievement->toArray());

        return response()->json($service->payload($achievement, 0), 201);
    }

    public function update(Request $request, Achievement $achievement, AchievementService $service)
    {
        $before = $achievement->toArray();
        $achievement->update($this->validatedData($request, true));
        $this->audit($request, 'updated', $achievement, $before, $achievement->fresh()->toArray());

        return response()->json($service->payload($achievement->fresh(), 0));
    }

    public function destroy(Request $request, Achievement $achievement)
    {
        $before = $achievement->toArray();
        $achievement->update(['active' => false]);
        $this->audit($request, 'deactivated', $achievement, $before, $achievement->fresh()->toArray());

        return response()->json(['message' => 'Conquista desativada. O histórico foi preservado.']);
    }

    public function upload(Request $request, Achievement $achievement, AchievementService $service)
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=128,min_height=128,max_width=4096,max_height=4096'],
        ]);

        $file = $request->file('image');
        $extension = strtolower($file->extension());
        $path = $file->storeAs('achievements', Str::uuid().'.'.$extension, 'public');
        $before = $achievement->toArray();

        if ($achievement->image_path) {
            Storage::disk('public')->delete($achievement->image_path);
        }
        $achievement->update(['image_path' => $path]);
        $this->audit($request, 'image_uploaded', $achievement, $before, $achievement->fresh()->toArray());

        return response()->json($service->payload($achievement->fresh(), 0));
    }

    public function recalculate(Request $request, Store $store, AchievementService $service)
    {
        $values = $service->synchronize($store);
        $this->audit($request, 'recalculated', $store, null, $values);

        return response()->json(['message' => 'Conquistas recalculadas.', 'metrics' => $values]);
    }

    public function auditLogs()
    {
        return response()->json(
            AdminAuditLog::query()
                ->latest()
                ->limit(100)
                ->get(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'ip_address', 'created_at'])
        );
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'type' => [$required, 'in:plate,badge'],
            'metric' => [$required, 'in:revenue_total,orders_paid,revenue_24h,orders_paid_24h'],
            'target' => [$required, 'numeric', 'min:1', 'max:9999999999'],
            'title' => [$required, 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        if (array_key_exists('target', $data)) {
            $metric = $data['metric'] ?? $request->route('achievement')?->metric;
            $data['target_value'] = str_starts_with($metric, 'revenue_')
                ? (int) round(((float) $data['target']) * 100)
                : (int) $data['target'];
            unset($data['target']);
        }

        return $data;
    }

    private function audit(Request $request, string $action, object $model, ?array $before, ?array $after): void
    {
        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => $model->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
