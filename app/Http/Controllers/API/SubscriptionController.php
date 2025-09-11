<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\PaddleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    protected $paddleService;

    public function __construct(PaddleService $paddleService)
    {
        $this->paddleService = $paddleService;
    }

    public function status()
    {
        try {
            $user = Auth::user();
            $user->ensureActiveSubscription();
            $user->load('activeSubscription.package');

            $subscription = $user->activeSubscription;
            $package = $user->getCurrentPackage();

            return response()->json([
                'success' => true,
                'data' => [
                    'is_premium' => $user->isPremium(),
                    'status' => $subscription?->status ?? 'inactive',
                    'expires_at' => $subscription?->expires_at,
                    'current_period_end' => $subscription?->current_period_end,
                    'package' => $package,
                    'limits' => $user->getUserLimits(),
                    'starts_at' => $subscription?->starts_at,
                    'cancelled_at' => $subscription?->cancelled_at,
                    'subscription_id' => $subscription?->id
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching subscription status', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription status'
            ], 500);
        }
    }

    public function cancel()
    {
        try {
            $user = Auth::user();
            $subscription = $user->activeSubscription;

            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active subscription found'
                ], 404);
            }

            if (!$subscription->package || !$subscription->package->is_premium) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel basic subscription'
                ], 400);
            }

            if ($subscription->paddle_subscription_id) {
                $result = $this->paddleService->cancelSubscription($subscription->paddle_subscription_id);

                if (!$result || !$result['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to cancel subscription with payment provider'
                    ], 500);
                }
            }

            $basicSubscription = $user->downgradeToBasic('user_cancelled');

            Log::info('User cancelled subscription', [
                'user_id' => $user->id,
                'cancelled_subscription_id' => $subscription->id,
                'new_basic_subscription_id' => $basicSubscription->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription cancelled successfully. You have been moved to the Basic plan.',
                'data' => [
                    'new_subscription' => $basicSubscription->load('package'),
                    'package' => $basicSubscription->package
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error cancelling subscription', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error cancelling subscription'
            ], 500);
        }
    }

    public function plans()
    {
        try {
            $packages = Package::where('is_active', true)
                ->where('is_premium', true)
                ->whereNotNull('paddle_product_id')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $packages
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching packages', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching packages'
            ], 500);
        }
    }
}
