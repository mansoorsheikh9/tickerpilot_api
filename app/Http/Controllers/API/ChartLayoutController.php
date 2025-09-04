<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ChartLayout;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChartLayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxChartLayouts = $currentPackage?->max_chart_layouts ?? 5;

        // Get only the allowed number of chart layouts based on current package
        $layouts = $user->chartLayouts()
            ->orderBy('name')
            ->limit($maxChartLayouts)
            ->get();

        // Check if user has more layouts than allowed (for info purposes)
        $totalLayouts = $user->chartLayouts()->count();
        $hasExceededLimit = $totalLayouts > $maxChartLayouts;

        return response()->json([
            'success' => true,
            'data' => $layouts,
            'limits' => [
                'current_layouts' => $layouts->count(),
                'total_layouts' => $totalLayouts,
                'max_chart_layouts' => $maxChartLayouts,
                'can_create_more' => $layouts->count() < $maxChartLayouts,
                'has_exceeded_limit' => $hasExceededLimit,
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxChartLayouts = $currentPackage?->max_chart_layouts ?? 5;

        // Check if user can create more chart layouts
        $currentLayoutsCount = $user->chartLayouts()->count();
        if ($currentLayoutsCount >= $maxChartLayouts) {
            return response()->json([
                'success' => false,
                'message' => 'You have reached your chart layout limit. Please upgrade your package or delete existing layouts.',
                'error_code' => 'CHART_LAYOUT_LIMIT_EXCEEDED',
                'limits' => [
                    'current_layouts' => $currentLayoutsCount,
                    'max_chart_layouts' => $maxChartLayouts,
                ]
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'symbol' => 'nullable|string|max:20',
            'timeframe' => 'nullable|string|max:10',
        ]);

        $layout = $user->chartLayouts()->create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Chart layout saved successfully',
            'data' => $layout
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxChartLayouts = $currentPackage?->max_chart_layouts ?? 5;

        // Check if this layout is within the allowed limit - just return within limits, no error
        $userLayoutIds = $user->chartLayouts()
            ->orderBy('name')
            ->limit($maxChartLayouts)
            ->pluck('id')
            ->toArray();

        $layout = $user->chartLayouts()->findOrFail($id);

        // If this layout is beyond the user's current package limit, return 404 (not found)
        if (!in_array($layout->id, $userLayoutIds)) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => $layout
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxChartLayouts = $currentPackage?->max_chart_layouts ?? 5;

        // Check if this layout is within the allowed limit for updates
        $userLayoutIds = $user->chartLayouts()
            ->orderBy('name')
            ->limit($maxChartLayouts)
            ->pluck('id')
            ->toArray();

        if (!in_array($id, $userLayoutIds)) {
            abort(404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'symbol' => 'nullable|string|max:20',
            'timeframe' => 'nullable|string|max:10',
        ]);

        $layout = $user->chartLayouts()->findOrFail($id);
        $layout->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Chart layout updated successfully',
            'data' => $layout
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        // Allow deletion even if layout is beyond limit (helps users get back within limits)
        $layout = $user->chartLayouts()->findOrFail($id);
        $layout->delete();

        return response()->json([
            'success' => true,
            'message' => 'Chart layout deleted successfully'
        ]);
    }
}
