<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Index;
use App\Models\WatchlistStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\FacadesLog;

class WatchlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        // Get only the allowed number of watchlists based on current package
        $watchlists = $user->watchlists()
            ->with(['sections'])
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->get();

        // Get stock counts for each watchlist
        foreach ($watchlists as $watchlist) {
            $watchlist->stocks_count = $watchlist->watchlistStocks()->count();
        }

        // Check if user has more watchlists than allowed (for info purposes)
        $totalWatchlists = $user->watchlists()->count();
        $hasExceededLimit = $totalWatchlists > $maxWatchlists;

        return response()->json([
            'success' => true,
            'data' => $watchlists->map(function ($watchlist) {
                return [
                    'id' => $watchlist->id,
                    'name' => $watchlist->name,
                    'description' => $watchlist->description,
                    'settings' => $watchlist->settings,
                    'sort_order' => $watchlist->sort_order,
                    'stocks_count' => $watchlist->stocks_count,
                    'sections_count' => $watchlist->sections->count(),
                    'created_at' => $watchlist->created_at,
                ];
            }),
            'limits' => [
                'current_watchlists' => $watchlists->count(),
                'total_watchlists' => $totalWatchlists,
                'max_watchlists' => $maxWatchlists,
                'can_create_more' => $user->canCreateWatchlist(),
                'has_exceeded_limit' => $hasExceededLimit,
            ]
        ]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;
        $maxStocksPerWatchlist = $currentPackage?->max_stocks_per_watchlist ?? 10;

        // Check if this watchlist is within the allowed limit
        $userWatchlistIds = $user->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->pluck('id')
            ->toArray();

        $watchlist = $user->watchlists()
            ->with([
                'sections' => function ($query) {
                    $query->orderBy('sort_order');
                }
            ])
            ->findOrFail($id);

        // If this watchlist is beyond the user's current package limit, return 404
        if (!in_array($watchlist->id, $userWatchlistIds)) {
            abort(404);
        }

        // Get watchlist items with package limit enforcement (per watchlist limit)
        $watchlistStocks = $watchlist->watchlistStocks()
            ->with(['watchable.latestPrice'])
            ->orderBy('sort_order')
            ->limit($maxStocksPerWatchlist)
            ->get();

        // Group items by section
        $sectionedStocks = [];
        $unsectionedStocks = [];

        foreach ($watchlistStocks as $watchlistStock) {
            $formattedStock = $this->formatWatchableData($watchlistStock);

            if ($watchlistStock->section_id) {
                if (!isset($sectionedStocks[$watchlistStock->section_id])) {
                    $sectionedStocks[$watchlistStock->section_id] = [];
                }
                $sectionedStocks[$watchlistStock->section_id][] = $formattedStock;
            } else {
                $unsectionedStocks[] = $formattedStock;
            }
        }

        // Sort unsectioned items by sort_order
        usort($unsectionedStocks, function ($a, $b) {
            return $a['sort_order'] <=> $b['sort_order'];
        });

        // Format sections with their items (ordered by section sort_order)
        $sections = $watchlist->sections->map(function ($section) use ($sectionedStocks) {
            $stocks = $sectionedStocks[$section->id] ?? [];

            // Sort items within section by sort_order
            usort($stocks, function ($a, $b) {
                return $a['sort_order'] <=> $b['sort_order'];
            });

            return [
                'id' => $section->id,
                'name' => $section->name,
                'description' => $section->description,
                'color' => $section->color,
                'is_collapsed' => $section->is_collapsed,
                'sort_order' => $section->sort_order,
                'stocks' => $stocks,
                'stocks_count' => count($stocks)
            ];
        });

        // Check if there are more items than the limit allows
        $totalStocks = $watchlist->watchlistStocks()->count();
        $hasExceededStockLimit = $totalStocks > $maxStocksPerWatchlist;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $watchlist->id,
                'name' => $watchlist->name,
                'description' => $watchlist->description,
                'settings' => $watchlist->settings,
                'sections' => $sections,
                'unsectioned_stocks' => $unsectionedStocks
            ],
            'limits' => [
                'current_stocks' => $watchlistStocks->count(),
                'total_stocks' => $totalStocks,
                'max_stocks_per_watchlist' => $maxStocksPerWatchlist,
                'has_exceeded_stock_limit' => $hasExceededStockLimit,
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->canCreateWatchlist()) {
            return response()->json([
                'success' => false,
                'message' => 'You have reached your watchlist limit. Please upgrade your package.',
                'error_code' => 'WATCHLIST_LIMIT_EXCEEDED'
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
        ]);

        $maxSortOrder = $user->watchlists()->max('sort_order') ?? 0;

        $watchlist = $user->watchlists()->create([
            'name' => $request->name,
            'description' => $request->description,
            'settings' => $request->settings ?? [],
            'sort_order' => $maxSortOrder + 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Watchlist created successfully',
            'data' => $watchlist
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $currentPackage = $user->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        // Check if this watchlist is within the allowed limit for updates
        $userWatchlistIds = $user->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->pluck('id')
            ->toArray();

        if (!in_array($id, $userWatchlistIds)) {
            abort(404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
        ]);

        $watchlist = $user->watchlists()->findOrFail($id);
        $watchlist->update($request->only(['name', 'description', 'settings']));

        return response()->json([
            'success' => true,
            'message' => 'Watchlist updated successfully',
            'data' => $watchlist
        ]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        // Allow deletion even if watchlist is beyond limit (helps users get back within limits)
        $watchlist = $user->watchlists()->findOrFail($id);
        $watchlist->delete();

        return response()->json([
            'success' => true,
            'message' => 'Watchlist deleted successfully'
        ]);
    }

    public function addStock(Request $request, $watchlistId): JsonResponse
    {
        $user = $request->user();

        if (!$user->canAddStockToWatchlist($watchlistId)) {
            return response()->json([
                'success' => false,
                'message' => 'You have reached the item limit for this watchlist. Please upgrade your package.',
                'error_code' => 'STOCK_LIMIT_EXCEEDED'
            ], 403);
        }

        // Additional check: ensure the watchlist is within user's package limit
        $currentPackage = $user->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        $userWatchlistIds = $user->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->pluck('id')
            ->toArray();

        if (!in_array($watchlistId, $userWatchlistIds)) {
            abort(404);
        }

        $request->validate([
            'symbol' => 'required|string',
            'type' => 'required|string|in:stock,index',
            'section_id' => 'nullable|uuid|exists:watchlist_sections,id',
        ]);

        $watchlist = $user->watchlists()->findOrFail($watchlistId);

        // Find the item based on type
        if ($request->type === 'stock') {
            $item = Stock::on('pgsql')->where('symbol', strtoupper($request->symbol))->first();
            $watchableType = Stock::class;
        } else {
            $item = Index::on('pgsql')->where('symbol', strtoupper($request->symbol))->first();
            $watchableType = Index::class;
        }

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => ucfirst($request->type) . ' not found'
            ], 404);
        }

        // Check if item already exists in watchlist
        if ($watchlist->watchlistStocks()
            ->where('watchable_type', $watchableType)
            ->where('watchable_id', $item->id)
            ->exists()) {
            return response()->json([
                'success' => false,
                'message' => ucfirst($request->type) . ' already exists in watchlist'
            ], 409);
        }

        // Get max sort order for the target section (or unsectioned)
        $maxSortOrder = $watchlist->watchlistStocks()
            ->where('section_id', $request->section_id)
            ->max('sort_order') ?? 0;

        // Create watchlist entry
        WatchlistStock::create([
            'watchlist_id' => $watchlist->id,
            'watchable_type' => $watchableType,
            'watchable_id' => $item->id,
            'section_id' => $request->section_id,
            'sort_order' => $maxSortOrder + 1,
            'settings' => [],
        ]);

        return response()->json([
            'success' => true,
            'message' => ucfirst($request->type) . ' added to watchlist successfully',
            'data' => [
                'item' => $item,
                'type' => $request->type,
                'latest_price' => $item->latestPrice
            ]
        ]);
    }

    public function removeStock(Request $request, $watchlist, $stock): JsonResponse
    {
        $watchlist = $request->user()->watchlists()->findOrFail($watchlist);

        // The $stock parameter now represents watchable_id
        $deleted = $watchlist->watchlistStocks()
            ->where('watchable_id', $stock)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found in watchlist'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Item removed from watchlist successfully'
        ]);
    }

    public function moveStock(Request $request, $watchlistId): JsonResponse
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
            'watchable_id' => 'required|uuid',
            'section_id' => 'nullable|uuid|exists:watchlist_sections,id',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $watchlist = $user->watchlists()->findOrFail($watchlistId);

        $watchlistStock = $watchlist->watchlistStocks()
            ->where('watchable_id', $request->watchable_id)
            ->firstOrFail();

        // Get max sort order for target section
        $maxSortOrder = $watchlist->watchlistStocks()
            ->where('section_id', $request->section_id)
            ->max('sort_order') ?? 0;

        $watchlistStock->update([
            'section_id' => $request->section_id,
            'sort_order' => $request->sort_order ?? ($maxSortOrder + 1),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item moved successfully'
        ]);
    }

    public function reorderStocks(Request $request, $watchlistId): JsonResponse
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
            'stocks' => 'required|array',
            'stocks.*.watchable_id' => 'required|uuid',
            'stocks.*.sort_order' => 'required|integer|min:0',
            'section_id' => 'nullable|uuid',
        ]);

        $watchlist = $user->watchlists()->findOrFail($watchlistId);

        // Validate that section_id exists if provided
        if ($request->section_id) {
            $section = $watchlist->sections()->findOrFail($request->section_id);
        }

        try {
            DB::beginTransaction();

            foreach ($request->stocks as $stockData) {
                $updated = $watchlist->watchlistStocks()
                    ->where('watchable_id', $stockData['watchable_id'])
                    ->where('section_id', $request->section_id)
                    ->update(['sort_order' => $stockData['sort_order']]);

                if (!$updated) {
                    Log::warning("Item {$stockData['watchable_id']} not found in section {$request->section_id} for reordering");
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Item order updated successfully',
                'data' => [
                    'section_id' => $request->section_id,
                    'updated_count' => count($request->stocks)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error reordering items: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update item order'
            ], 500);
        }
    }

    public function updateStockOrder(Request $request, $watchlistId): JsonResponse
    {
        return $this->reorderStocks($request, $watchlistId);
    }

    private function formatWatchableData(WatchlistStock $watchlistStock)
    {
        $watchable = $watchlistStock->watchable;
        $latestPrice = $watchable->latestPrice;

        return [
            'id' => $watchlistStock->id,
            'watchable_id' => $watchable->id,
            'symbol' => $watchable->symbol,
            'name' => $watchlistStock->getName(),
            'description' => $watchable->description ?? $watchable->name,
            'type' => $watchlistStock->getType(),
            'price' => $latestPrice?->price ?? '--',
            'change' => $latestPrice?->change ?? '--',
            'change_percent' => $latestPrice && $latestPrice->price ?
                (($latestPrice->change / ($latestPrice->price - $latestPrice->change)) * 100) : '--',
            'volume' => $latestPrice?->volume ?? '--',
            'last_updated' => $latestPrice?->date ?? '--',
            'sort_order' => $watchlistStock->sort_order,
            'section_id' => $watchlistStock->section_id,
            'flag_color' => $watchlistStock->settings['flag_color'] ?? null,
            'settings' => $watchlistStock->settings,
        ];
    }

    public function updateFlag(Request $request): JsonResponse
    {
        $request->validate([
            'stock_id' => 'required|uuid|exists:watchlist_stocks,id',
            'flag_color' => 'nullable|string|in:red,green,blue,yellow,purple,orange'
        ]);

        try {
            $watchlistStock = WatchlistStock::with(['watchlist'])
                ->where('id', $request->stock_id)
                ->first();

            if (!$watchlistStock) {
                return response()->json([
                    'success' => false,
                    'message' => 'Watchlist item not found'
                ], 404);
            }

            // Check if the authenticated user owns this watchlist
            if ($watchlistStock->watchlist->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this watchlist'
                ], 403);
            }

            // Additional check: ensure the watchlist is within user's package limit
            $user = auth()->user();
            $currentPackage = $user->getCurrentPackage();
            $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

            $userWatchlistIds = $user->watchlists()
                ->orderBy('sort_order')
                ->limit($maxWatchlists)
                ->pluck('id')
                ->toArray();

            if (!in_array($watchlistStock->watchlist_id, $userWatchlistIds)) {
                abort(404);
            }

            // Get current settings and update flag_color
            $settings = $watchlistStock->settings ?? [];
            $flagColor = $request->flag_color ?: null;
            $settings['flag_color'] = $flagColor;

            $watchlistStock->update(['settings' => $settings]);

            return response()->json([
                'success' => true,
                'message' => 'Flag color updated successfully',
                'data' => [
                    'id' => $watchlistStock->id,
                    'flag_color' => $flagColor,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating flag color: ' . $e->getMessage()
            ], 500);
        }
    }
}
