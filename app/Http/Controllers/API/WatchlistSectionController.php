<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WatchlistSection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WatchlistSectionController extends Controller
{
    public function store(Request $request, $watchlistId): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        // Check if this watchlist is within the allowed limit
        $userWatchlistIds = $user->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->pluck('id')
            ->toArray();

        if (!in_array($watchlistId, $userWatchlistIds)) {
            abort(404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $watchlist = $user->watchlists()->findOrFail($watchlistId);

        // Check if section name already exists in this watchlist
        if ($watchlist->sections()->where('name', $request->name)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Section name already exists in this watchlist'
            ], 409);
        }

        $maxSortOrder = $watchlist->sections()->max('sort_order') ?? 0;

        $section = WatchlistSection::create([
            'watchlist_id' => $watchlistId,
            'name' => $request->name,
            'description' => $request->description,
            'color' => $request->color ?? '#007bff',
            'sort_order' => $maxSortOrder + 1,
            'is_collapsed' => false, // New sections are expanded by default
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Section created successfully',
            'data' => [
                'id' => $section->id,
                'name' => $section->name,
                'description' => $section->description,
                'color' => $section->color,
                'is_collapsed' => $section->is_collapsed,
                'sort_order' => $section->sort_order,
                'stocks' => [], // New section has no stocks
                'stocks_count' => 0
            ]
        ], 201);
    }

    public function update(Request $request, $watchlistId, $sectionId): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        // Check if this watchlist is within the allowed limit
        $userWatchlistIds = $user->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->pluck('id')
            ->toArray();

        if (!in_array($watchlistId, $userWatchlistIds)) {
            abort(404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_collapsed' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $watchlist = $user->watchlists()->findOrFail($watchlistId);
        $section = $watchlist->sections()->findOrFail($sectionId);

        // Check for duplicate names if name is being updated
        if ($request->has('name') && $request->name !== $section->name) {
            if ($watchlist->sections()->where('name', $request->name)->where('id', '!=', $sectionId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Section name already exists in this watchlist'
                ], 409);
            }
        }

        $section->update($request->only(['name', 'description', 'color', 'is_collapsed', 'sort_order']));

        return response()->json([
            'success' => true,
            'message' => 'Section updated successfully',
            'data' => [
                'id' => $section->id,
                'name' => $section->name,
                'description' => $section->description,
                'color' => $section->color,
                'is_collapsed' => $section->is_collapsed,
                'sort_order' => $section->sort_order,
            ]
        ]);
    }

    public function destroy(Request $request, $watchlistId, $sectionId): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        // Check if this watchlist is within the allowed limit
        $userWatchlistIds = $user->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->pluck('id')
            ->toArray();

        if (!in_array($watchlistId, $userWatchlistIds)) {
            abort(404);
        }

        $watchlist = $user->watchlists()->findOrFail($watchlistId);
        $section = $watchlist->sections()->findOrFail($sectionId);

        // Check if section has any stocks
        $stockCount = $section->stocks()->count();
        if ($stockCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete section with stocks. Please move or remove all stocks first.',
                'stock_count' => $stockCount
            ], 400);
        }

        $section->delete();

        return response()->json([
            'success' => true,
            'message' => 'Section deleted successfully'
        ]);
    }

    public function reorderSections(Request $request, $watchlistId): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        // Check if this watchlist is within the allowed limit
        $userWatchlistIds = $user->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->pluck('id')
            ->toArray();

        if (!in_array($watchlistId, $userWatchlistIds)) {
            abort(404);
        }

        $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => 'required|uuid',
            'sections.*.sort_order' => 'required|integer|min:0',
        ]);

        $watchlist = $user->watchlists()->findOrFail($watchlistId);

        foreach ($request->sections as $sectionData) {
            $watchlist->sections()
                ->where('id', $sectionData['id'])
                ->update(['sort_order' => $sectionData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Section order updated successfully'
        ]);
    }
}
