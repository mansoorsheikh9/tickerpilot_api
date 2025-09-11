<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\WatchlistController;
use App\Http\Controllers\API\WatchlistSectionController;
use App\Http\Controllers\API\StockController;
use App\Http\Controllers\API\ChartLayoutController;
use App\Http\Controllers\API\SubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('google-login', [AuthController::class, 'googleLogin']);

Route::get('user/{idOrEmail}', [AuthController::class, 'getUserByIdOrEmail']);

// Paddle webhook (must be outside auth middleware)
Route::post('subscription/webhook', [SubscriptionController::class, 'webhook'])->name('paddle.webhook');

// Update the test route to verify v1 API
Route::get('/test-paddle', function() {
    $paddleService = new \App\Services\PaddleService();

    // Test connection
    $connection = $paddleService->testConnection();

    // Test a simple API call to verify v1 endpoint
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . config('paddle.api_key'),
    ])->get('https://sandbox-api.paddle.com/v1/products', ['per_page' => 1]);

    return response()->json([
        'api_connection' => $connection,
        'v1_test_status' => $response->status(),
        'v1_test_success' => $response->successful(),
        'environment' => config('paddle.environment'),
        'base_url' => 'https://sandbox-api.paddle.com/v1',
        'checkout_url' => 'https://sandbox-api.paddle.com/v1/checkout'
    ]);
});

// Authenticated routes
Route::middleware('auth:api')->group(function () {
    // Auth routes
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user', [AuthController::class, 'user']);
    Route::post('request-upgrade', [AuthController::class, 'requestUpgrade']);
    Route::post('convert-to-email-auth', [AuthController::class, 'convertToEmailAuth']);

    // User package limits
    Route::get('user/package-limits', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => $request->user()->getPackageLimits()
        ]);
    });

    // Subscription management routes
    Route::prefix('subscription')->group(function () {
        Route::post('create-transaction', [SubscriptionController::class, 'createTransaction'])->name('subscription.create-transaction');
        // Subscription management
        Route::get('status/id', [SubscriptionController::class, 'status'])->name('subscription.status');
        Route::post('cancel', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
        Route::get('plans', [SubscriptionController::class, 'plans'])->name('subscription.plans');
    });

    // Stock routes
    Route::prefix('stocks')->group(function () {
        Route::get('search', [StockController::class, 'search']);
        Route::get('symbol/{symbol}', [StockController::class, 'getBySymbol']);
        Route::get('{stock}', [StockController::class, 'show']);
    });

    // Watchlist routes
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

    // Chart layout routes
    Route::prefix('chart-layouts')->group(function () {
        Route::get('/', [ChartLayoutController::class, 'index']);
        Route::post('/', [ChartLayoutController::class, 'store']);
        Route::get('{layout}', [ChartLayoutController::class, 'show']);
        Route::put('{layout}', [ChartLayoutController::class, 'update']);
        Route::delete('{layout}', [ChartLayoutController::class, 'destroy']);
    });
});
