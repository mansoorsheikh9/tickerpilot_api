<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\WatchlistController;
use App\Http\Controllers\API\WatchlistSectionController;
use App\Http\Controllers\API\StockController;
use App\Http\Controllers\API\ChartLayoutController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('google-login', [AuthController::class, 'googleLogin']);

Route::middleware('auth:api')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user', [AuthController::class, 'user']);
    Route::post('request-upgrade', [AuthController::class, 'requestUpgrade']);

    Route::get('user/package-limits', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => $request->user()->getPackageLimits()
        ]);
    });

    Route::prefix('stocks')->group(function () {
        Route::get('search', [StockController::class, 'search']);
        Route::get('symbol/{symbol}', [StockController::class, 'getBySymbol']);
        Route::get('{stock}', [StockController::class, 'show']);
    });

    Route::prefix('watchlists')->group(function () {
        Route::get('/', [WatchlistController::class, 'index']);
        Route::post('/', [WatchlistController::class, 'store']);
        Route::get('{watchlist}', [WatchlistController::class, 'show']);
        Route::put('flag', [WatchlistController::class, 'updateFlag']);
        Route::put('{watchlist}', [WatchlistController::class, 'update']);
        Route::delete('{watchlist}', [WatchlistController::class, 'destroy']);

        Route::post('{watchlist}/stocks', [WatchlistController::class, 'addStock']);
        Route::delete('{watchlist}/stocks/{stock}', [WatchlistController::class, 'removeStock']);
        Route::put('{watchlist}/stocks/move', [WatchlistController::class, 'moveStock']);
        Route::put('{watchlist}/stocks/reorder', [WatchlistController::class, 'reorderStocks']);
        Route::put('{watchlist}/stocks/reorder-old', [WatchlistController::class, 'updateStockOrder']);

        Route::post('{watchlist}/sections', [WatchlistSectionController::class, 'store']);
        Route::put('{watchlist}/sections/{section}', [WatchlistSectionController::class, 'update']);
        Route::delete('{watchlist}/sections/{section}', [WatchlistSectionController::class, 'destroy']);
        Route::put('{watchlist}/sections-reorder', [WatchlistSectionController::class, 'reorderSections']);
    });

    Route::prefix('chart-layouts')->group(function () {
        Route::get('/', [ChartLayoutController::class, 'index']);
        Route::post('/', [ChartLayoutController::class, 'store']);
        Route::get('{layout}', [ChartLayoutController::class, 'show']);
        Route::put('{layout}', [ChartLayoutController::class, 'update'])    ;
        Route::delete('{layout}', [ChartLayoutController::class, 'destroy']);
    });
});
