<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\UserSubscription;
use App\Services\PaddleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    protected $paddleService;

    public function __construct(PaddleService $paddleService)
    {
        $this->paddleService = $paddleService;
    }

    /**
     * Create a transaction for Paddle Billing overlay checkout
     */
    public function createTransaction(Request $request)
    {
        try {
            $user = Auth::user();

            $request->validate([
                'package_id' => 'sometimes|exists:packages,id',
                'return_url' => 'sometimes|url'
            ]);

            if ($user->isPremium()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has an active premium subscription',
                    'error_code' => 'ALREADY_SUBSCRIBED'
                ], 400);
            }

            // Get the premium package
            $packageId = $request->package_id;
            if (!$packageId) {
                $package = Package::where('is_premium', true)
                    ->where('is_active', true)
                    ->whereNotNull('paddle_product_id')
                    ->first();
                if (!$package) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No premium packages available',
                        'error_code' => 'NO_PREMIUM_PACKAGES'
                    ], 404);
                }
            } else {
                $package = Package::find($packageId);
                if (!$package || !$package->is_active || !$package->is_premium) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid or inactive premium package',
                        'error_code' => 'INVALID_PACKAGE'
                    ], 404);
                }
            }

            if (!$package->paddle_product_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Package not configured for Paddle payments',
                    'error_code' => 'PACKAGE_NOT_CONFIGURED'
                ], 400);
            }

            // Create transaction using Paddle Billing API
            $transactionData = $this->paddleService->createTransaction([
                'user' => $user,
                'package' => $package,
                'return_url' => $request->return_url ?? config('app.url') . '/dashboard'
            ]);

            if (!$transactionData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create transaction',
                    'error_code' => 'TRANSACTION_CREATION_FAILED'
                ], 500);
            }

            Log::info('Transaction created for user', [
                'user_id' => $user->id,
                'package_id' => $package->id,
                'transaction_id' => $transactionData['transaction_id']
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $transactionData['transaction_id'],
                    'customer_id' => $transactionData['customer_id'],
                    'package' => $package->only(['id', 'name', 'description', 'price', 'currency', 'billing_cycle'])
                ],
                'message' => 'Transaction created successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating transaction', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error creating transaction',
                'error_code' => 'INTERNAL_ERROR'
            ], 500);
        }
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

    /**
     * Webhook endpoint to handle Paddle events
     */
    public function webhook(Request $request)
    {
        try {
            $signature = $request->header('Paddle-Signature');
            $payload = $request->getContent();

            if (!$this->paddleService->verifyWebhook($payload, $signature)) {
                Log::warning('Invalid webhook signature', [
                    'signature' => $signature,
                    'payload_length' => strlen($payload)
                ]);

                return response()->json(['error' => 'Invalid signature'], 400);
            }

            $eventData = json_decode($payload, true);
            $eventType = $eventData['event_type'] ?? null;

            Log::info('Received Paddle webhook', [
                'event_type' => $eventType,
                'event_id' => $eventData['event_id'] ?? null
            ]);

            // Store webhook event for debugging
            \DB::table('paddle_webhook_events')->insert([
                'id' => Str::uuid(),
                'paddle_event_id' => $eventData['event_id'] ?? null,
                'event_type' => $eventType,
                'event_data' => json_encode($eventData),
                'created_at' => now()
            ]);

            switch ($eventType) {
                case 'transaction.completed':
                    $this->handleTransactionCompleted($eventData['data']);
                    break;

                case 'transaction.payment_failed':
                    $this->handleTransactionFailed($eventData['data']);
                    break;

                case 'subscription.created':
                    $this->handleSubscriptionCreated($eventData['data']);
                    break;

                case 'subscription.updated':
                    $this->handleSubscriptionUpdated($eventData['data']);
                    break;

                case 'subscription.canceled':
                    $this->handleSubscriptionCanceled($eventData['data']);
                    break;

                default:
                    Log::info('Unhandled webhook event', ['event_type' => $eventType]);
            }

            return response()->json(['received' => true]);

        } catch (\Exception $e) {
            Log::error('Webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    protected function handleTransactionCompleted($transaction)
    {
        try {
            Log::info('Processing completed transaction', [
                'transaction_id' => $transaction['id']
            ]);

            $customData = $transaction['custom_data'] ?? [];
            $userId = $customData['user_id'] ?? null;
            $packageId = $customData['package_id'] ?? null;

            if (!$userId) {
                Log::error('No user_id in transaction custom data', [
                    'transaction_id' => $transaction['id'],
                    'custom_data' => $customData
                ]);
                return;
            }

            $user = \App\Models\User::find($userId);
            if (!$user) {
                Log::error('User not found for transaction', [
                    'user_id' => $userId,
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            $package = Package::find($packageId);
            if (!$package) {
                Log::error('Package not found for transaction', [
                    'package_id' => $packageId,
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            // Create or update subscription
            $subscription = UserSubscription::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'package_id' => $package->id,
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => $package->billing_cycle === 'yearly'
                        ? now()->addYear()
                        : now()->addMonth(),
                    'paddle_subscription_id' => $transaction['subscription_id'] ?? null,
                    'paddle_user_id' => $transaction['customer_id'] ?? null,
                    'paddle_plan_id' => $package->paddle_product_id,
                    'current_period_start' => now(),
                    'current_period_end' => $package->billing_cycle === 'yearly'
                        ? now()->addYear()
                        : now()->addMonth(),
                    'paddle_data' => json_encode($transaction)
                ]
            );

            Log::info('Subscription activated', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'transaction_id' => $transaction['id']
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling completed transaction', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction['id'] ?? null
            ]);
        }
    }

    protected function handleTransactionFailed($transaction)
    {
        Log::info('Transaction failed', [
            'transaction_id' => $transaction['id'],
            'custom_data' => $transaction['custom_data'] ?? []
        ]);
    }

    protected function handleSubscriptionCreated($subscription)
    {
        try {
            Log::info('Processing created subscription', [
                'subscription_id' => $subscription['id']
            ]);

            $customData = $subscription['custom_data'] ?? [];
            $userId = $customData['user_id'] ?? null;
            $packageId = $customData['package_id'] ?? null;

            if (!$userId || !$packageId) {
                Log::error('Missing user_id or package_id in subscription custom data', [
                    'subscription_id' => $subscription['id'],
                    'custom_data' => $customData
                ]);
                return;
            }

            $user = \App\Models\User::find($userId);
            $package = Package::find($packageId);

            if (!$user || !$package) {
                Log::error('User or package not found for subscription', [
                    'user_id' => $userId,
                    'package_id' => $packageId,
                    'subscription_id' => $subscription['id']
                ]);
                return;
            }

            UserSubscription::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'package_id' => $package->id,
                    'status' => $subscription['status'],
                    'paddle_subscription_id' => $subscription['id'],
                    'paddle_user_id' => $subscription['customer_id'],
                    'paddle_plan_id' => $package->paddle_product_id,
                    'current_period_start' => \Carbon\Carbon::parse($subscription['current_billing_period']['starts_at']),
                    'current_period_end' => \Carbon\Carbon::parse($subscription['current_billing_period']['ends_at']),
                    'starts_at' => \Carbon\Carbon::parse($subscription['started_at'] ?? $subscription['created_at']),
                    'expires_at' => \Carbon\Carbon::parse($subscription['current_billing_period']['ends_at']),
                    'paddle_data' => json_encode($subscription)
                ]
            );

            Log::info('Subscription created/updated', [
                'user_id' => $user->id,
                'paddle_subscription_id' => $subscription['id']
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling created subscription', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscription['id'] ?? null
            ]);
        }
    }

    protected function handleSubscriptionUpdated($subscription)
    {
        Log::info('Subscription updated', [
            'subscription_id' => $subscription['id']
        ]);

        $userSubscription = UserSubscription::where('paddle_subscription_id', $subscription['id'])->first();

        if ($userSubscription) {
            $userSubscription->update([
                'status' => $subscription['status'],
                'current_period_start' => \Carbon\Carbon::parse($subscription['current_billing_period']['starts_at']),
                'current_period_end' => \Carbon\Carbon::parse($subscription['current_billing_period']['ends_at']),
                'expires_at' => \Carbon\Carbon::parse($subscription['current_billing_period']['ends_at']),
                'paddle_data' => json_encode($subscription)
            ]);
        }
    }

    protected function handleSubscriptionCanceled($subscription)
    {
        Log::info('Subscription canceled', [
            'subscription_id' => $subscription['id']
        ]);

        $userSubscription = UserSubscription::where('paddle_subscription_id', $subscription['id'])->first();

        if ($userSubscription) {
            $user = $userSubscription->user;
            $user->downgradeToBasic('paddle_cancelled');

            $userSubscription->update([
                'status' => 'canceled',
                'cancelled_at' => now(),
                'paddle_data' => json_encode($subscription)
            ]);
        }
    }
}
