<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Index;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1',
            'types' => 'sometimes|array',
            'types.*' => 'string|in:stock,index',
            'limit' => 'sometimes|integer|min:1|max:50'
        ]);

        $query = $request->input('q');
        $types = $request->input('types', ['stock', 'index']);
        $limit = $request->input('limit', 20);

        $results = collect();

        // Search stocks if requested
        if (in_array('stock', $types)) {
            $stocks = Stock::on('pgsql')
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('symbol', 'ILIKE', "%{$query}%")
                        ->orWhere('description', 'ILIKE', "%{$query}%");
                })
                ->orderBy('symbol')
                ->limit($limit)
                ->get()
                ->map(function ($stock) {
                    return [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->description,
                        'type' => 'stock',
                        'exchange' => $stock->exchange,
                        'currency' => $stock->currency,
                    ];
                });

            $results = $results->merge($stocks);
        }

        // Search indices if requested
        if (in_array('index', $types)) {
            $indices = Index::on('pgsql')
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('symbol', 'ILIKE', "%{$query}%")
                        ->orWhere('name', 'ILIKE', "%{$query}%")
                        ->orWhere('description', 'ILIKE', "%{$query}%");
                })
                ->orderBy('symbol')
                ->limit($limit)
                ->get()
                ->map(function ($index) {
                    return [
                        'id' => $index->id,
                        'symbol' => $index->symbol,
                        'name' => $index->name,
                        'type' => 'index',
                        'exchange' => $index->exchange,
                        'currency' => $index->currency,
                    ];
                });

            $results = $results->merge($indices);
        }

        // Sort by relevance (exact symbol match first, then alphabetically)
        $sorted = $results->sortBy(function ($item) use ($query) {
            $queryUpper = strtoupper($query);
            $symbolUpper = strtoupper($item['symbol']);

            if ($symbolUpper === $queryUpper) {
                return '1_' . $item['symbol']; // Exact match first
            } elseif (strpos($symbolUpper, $queryUpper) === 0) {
                return '2_' . $item['symbol']; // Starts with query
            } else {
                return '3_' . $item['symbol']; // Contains query
            }
        })->values();

        // Limit final results
        $finalResults = $sorted->take($limit);

        return response()->json([
            'success' => true,
            'data' => $finalResults,
            'meta' => [
                'query' => $query,
                'types_searched' => $types,
                'total_results' => $finalResults->count(),
                'limit' => $limit
            ]
        ]);
    }

    public function getBySymbol(Request $request, string $symbol): JsonResponse
    {
        $request->validate([
            'type' => 'sometimes|string|in:stock,index'
        ]);

        $type = $request->input('type');
        $symbol = strtoupper($symbol);

        // If type is specified, search only that type
        if ($type) {
            if ($type === 'stock') {
                $item = Stock::on('pgsql')
                    ->with('latestPrice')
                    ->where('symbol', $symbol)
                    ->where('is_active', true)
                    ->first();
            } else {
                $item = Index::on('pgsql')
                    ->with('latestPrice')
                    ->where('symbol', $symbol)
                    ->where('is_active', true)
                    ->first();
            }

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => ucfirst($type) . ' not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatItemData($item, $type)
            ]);
        }

        // If no type specified, search both and return first match (prioritize stocks)
        $stock = Stock::on('pgsql')
            ->with('latestPrice')
            ->where('symbol', $symbol)
            ->where('is_active', true)
            ->first();

        if ($stock) {
            return response()->json([
                'success' => true,
                'data' => $this->formatItemData($stock, 'stock')
            ]);
        }

        $index = Index::on('pgsql')
            ->with('latestPrice')
            ->where('symbol', $symbol)
            ->where('is_active', true)
            ->first();

        if ($index) {
            return response()->json([
                'success' => true,
                'data' => $this->formatItemData($index, 'index')
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Symbol not found'
        ], 404);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $request->validate([
            'type' => 'sometimes|string|in:stock,index'
        ]);

        $type = $request->input('type');

        // If type is specified, search only that type
        if ($type) {
            if ($type === 'stock') {
                $item = Stock::on('pgsql')
                    ->with('latestPrice')
                    ->where('id', $id)
                    ->where('is_active', true)
                    ->first();
            } else {
                $item = Index::on('pgsql')
                    ->with('latestPrice')
                    ->where('id', $id)
                    ->where('is_active', true)
                    ->first();
            }

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => ucfirst($type) . ' not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatItemData($item, $type)
            ]);
        }

        // If no type specified, search both (prioritize stocks)
        $stock = Stock::on('pgsql')
            ->with('latestPrice')
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if ($stock) {
            return response()->json([
                'success' => true,
                'data' => $this->formatItemData($stock, 'stock')
            ]);
        }

        $index = Index::on('pgsql')
            ->with('latestPrice')
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if ($index) {
            return response()->json([
                'success' => true,
                'data' => $this->formatItemData($index, 'index')
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Item not found'
        ], 404);
    }

    private function formatItemData($item, string $type): array
    {
        $latestPrice = $item->latestPrice;

        return [
            'id' => $item->id,
            'symbol' => $item->symbol,
            'name' => $item->description,
            'description' => $item->description,
            'type' => $type,
            'is_active' => $item->is_active ?? true, // indices don't have is_active
            'latest_price' => $latestPrice ? [
                'price' => $latestPrice->price,
                'change' => $latestPrice->change,
                'change_percent' => $latestPrice->price && $latestPrice->change ?
                    (($latestPrice->change / ($latestPrice->price - $latestPrice->change)) * 100) : null,
                'open' => $latestPrice->open,
                'high' => $latestPrice->high,
                'low' => $latestPrice->low,
                'close' => $latestPrice->close,
                'volume' => $latestPrice->volume,
                'date' => $latestPrice->date,
                'last_updated' => $latestPrice->created_at,
            ] : null,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }
}
