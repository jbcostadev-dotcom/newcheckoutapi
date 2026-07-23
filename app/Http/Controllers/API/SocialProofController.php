<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SocialProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SocialProofController extends Controller
{
    private const ALLOWED_IMAGE_MIMES = ['image/webp', 'image/jpeg', 'image/png'];
    private const ALLOWED_IMAGE_EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png'];
    private const MAX_IMAGE_SIZE_KB = 8192; // 8 MB

    private function photoRule(): string
    {
        $mimes = implode(',', self::ALLOWED_IMAGE_MIMES);
        $extensions = implode(',', self::ALLOWED_IMAGE_EXTENSIONS);
        return "nullable|file|mimetypes:{$mimes}|mimes:{$extensions}|max:" . self::MAX_IMAGE_SIZE_KB;
    }

    public function index(string $storeId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $proofs = $store->socialProofs()
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($proofs);
    }

    public function store(string $storeId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'testimonial' => 'required|string|max:500',
            'photo' => $this->photoRule(),
            'stars' => 'required|integer|min:1|max:5',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('social-proofs', 'public');
            $photoUrl = Storage::disk('public')->url($path);
        }

        $proof = $store->socialProofs()->create([
            'name' => $validated['name'],
            'testimonial' => $validated['testimonial'],
            'photo_url' => $photoUrl,
            'stars' => $validated['stars'],
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json($proof, 201);
    }

    public function update(string $storeId, string $proofId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $proof = $store->socialProofs()->findOrFail($proofId);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'testimonial' => 'sometimes|required|string|max:500',
            'photo' => $this->photoRule(),
            'stars' => 'sometimes|required|integer|min:1|max:5',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($request->hasFile('photo')) {
            // Delete old photo if exists
            if ($proof->photo_url) {
                $oldPath = str_replace(Storage::disk('public')->url(''), '', $proof->photo_url);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('photo')->store('social-proofs', 'public');
            $validated['photo_url'] = Storage::disk('public')->url($path);
        }

        unset($validated['photo']);
        $proof->update($validated);

        return response()->json($proof);
    }

    public function destroy(string $storeId, string $proofId, Request $request)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $proof = $store->socialProofs()->findOrFail($proofId);

        // Delete photo if exists
        if ($proof->photo_url) {
            $oldPath = str_replace(Storage::disk('public')->url(''), '', $proof->photo_url);
            Storage::disk('public')->delete($oldPath);
        }

        $proof->delete();

        return response()->json(null, 204);
    }
}
