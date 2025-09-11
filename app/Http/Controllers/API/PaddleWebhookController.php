<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\UserSubscription;
use App\Services\PaddleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PaddleWebhookController extends Controller
{
    protected $paddleService;

    public function __construct(PaddleService $paddleService)
    {
        $this->paddleService = $paddleService;
    }

    public function webhook(Request $request)
    {
        try {
            $signature = $request->header('Paddle-Signature');
            $payload = $request->getContent(); // Get raw payload

            // Enhanced signature verification with better error handling
            if (!$this->paddleService->verifyWebhook($payload, $signature)) {
                Log::warning('Invalid webhook signature', [
                    'signature' => $signature,
                    'payload_length' => strlen($payload),
                    'headers' => $request->headers->all(),
                    'ip' => $request->ip()
                ]);

                return response()->json(['error' => 'Invalid signature'], 400);
            }

            $eventData = json_decode($payload, true);

            if (!$eventData) {
                Log::error('Invalid JSON payload', [
                    'payload' => substr($payload, 0, 500) // Log first 500 chars
                ]);
                return response()->json(['error' => 'Invalid JSON'], 400);
            }

            $eventType = $eventData['event_type'] ?? null;
            $eventId = $eventData['event_id'] ?? null;

            Log::info('Received Paddle webhook', [
                'event_type' => $eventType,
                'event_id' => $eventId
            ]);

            // Check for duplicate events
            if ($eventId) {
                $existingEvent = DB::table('paddle_webhook_events')
                    ->where('paddle_event_id', $eventId)
                    ->first();

                if ($existingEvent) {
                    Log::info('Duplicate webhook event ignored', ['event_id' => $eventId]);
                    return response()->json(['received' => true]);
                }
            }

            // Store webhook event for debugging and auditing
            $webhookEventId = Str::uuid();
            DB::table('paddle_webhook_events')->insert([
                'id' => $webhookEventId,
                'paddle_event_id' => $eventId,
                'event_type' => $eventType,
                'event_data' => json_encode($eventData),
                'signature' => $signature,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Process the webhook event
            $this->processWebhookEvent($eventType, $eventData['data'], $webhookEventId);

            return response()->json(['received' => true]);

        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload_length' => isset($payload) ? strlen($payload) : 0
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    protected function processWebhookEvent($eventType, $data, $webhookEventId)
    {
        try {
            switch ($eventType) {
                case 'transaction.completed':
                    $this->handleTransactionCompleted($data);
                    break;

                case 'transaction.payment_failed':
                    $this->handleTransactionFailed($data);
                    break;

                case 'subscription.created':
                    $this->handleSubscriptionCreated($data);
                    break;

                case 'subscription.updated':
                    $this->handleSubscriptionUpdated($data);
                    break;

                case 'subscription.canceled':
                    $this->handleSubscriptionCanceled($data);
                    break;

                default:
                    Log::info('Unhandled webhook event', ['event_type' => $eventType]);
            }

            // Mark as processed
            DB::table('paddle_webhook_events')
                ->where('id', $webhookEventId)
                ->update([
                    'processed_at' => now(),
                    'updated_at' => now()
                ]);

        } catch (\Exception $e) {
            // Mark as failed
            DB::table('paddle_webhook_events')
                ->where('id', $webhookEventId)
                ->update([
                    'processing_attempts' => DB::raw('processing_attempts + 1'),
                    'last_error' => $e->getMessage(),
                    'updated_at' => now()
                ]);

            throw $e; // Re-throw to be caught by main try-catch
        }
    }

    protected function handleTransactionCompleted($transaction)
    {
        try {
            Log::info('Processing completed transaction', [
                'transaction_id' => $transaction['id']
            ]);

            // Extract customer and subscription info
            $customerId = $transaction['customer_id'] ?? null;
            $subscriptionId = $transaction['subscription_id'] ?? null;

            if (!$customerId) {
                Log::error('No customer_id in transaction', [
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            // Find user by paddle customer ID
            $user = \App\Models\User::where('paddle_customer_id', $customerId)->first();

            if (!$user) {
                Log::error('User not found for customer', [
                    'customer_id' => $customerId,
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            // Get package info from the first line item
            $lineItem = $transaction['details']['line_items'][0] ?? null;
            if (!$lineItem) {
                Log::error('No line items in transaction', [
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            $productId = $lineItem['product']['id'];
            $package = Package::where('paddle_product_id', $productId)->first();

            if (!$package) {
                Log::error('Package not found for product', [
                    'product_id' => $productId,
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            // Calculate subscription period
            $billingPeriod = $transaction['billing_period'] ?? null;
            $startsAt = $billingPeriod ? Carbon::parse($billingPeriod['starts_at']) : now();
            $endsAt = $billingPeriod ? Carbon::parse($billingPeriod['ends_at']) : now()->addMonth();

            // Create or update subscription
            $subscription = UserSubscription::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'package_id' => $package->id,
                    'status' => 'active',
                    'starts_at' => $startsAt,
                    'expires_at' => $endsAt,
                    'paddle_subscription_id' => $subscriptionId,
                    'paddle_user_id' => $customerId,
                    'paddle_plan_id' => $productId,
                    'current_period_start' => $startsAt,
                    'current_period_end' => $endsAt,
                    'paddle_data' => json_encode($transaction)
                ]
            );

            Log::info('Subscription activated from transaction', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'transaction_id' => $transaction['id']
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling completed transaction', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction['id'] ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function handleTransactionFailed($transaction)
    {
        try {
            Log::info('Processing failed transaction', [
                'transaction_id' => $transaction['id']
            ]);

            $customerId = $transaction['customer_id'] ?? null;

            if (!$customerId) {
                Log::warning('No customer_id in failed transaction', [
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            $user = \App\Models\User::where('paddle_customer_id', $customerId)->first();

            if ($user) {
                // Handle failed payment - maybe send notification, update subscription status, etc.
                Log::info('Payment failed for user', [
                    'user_id' => $user->id,
                    'transaction_id' => $transaction['id']
                ]);

                // Optional: Update subscription status to 'past_due' or similar
                $subscription = UserSubscription::where('user_id', $user->id)
                    ->where('paddle_subscription_id', $transaction['subscription_id'] ?? null)
                    ->first();

                if ($subscription) {
                    $subscription->update([
                        'status' => 'past_due',
                        'paddle_data' => json_encode($transaction)
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Error handling failed transaction', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction['id'] ?? null
            ]);
            throw $e;
        }
    }

    protected function handleSubscriptionCreated($subscription)
    {
        try {
            Log::info('Processing created subscription', [
                'subscription_id' => $subscription['id']
            ]);

            $customerId = $subscription['customer_id'] ?? null;

            if (!$customerId) {
                Log::error('Missing customer_id in subscription', [
                    'subscription_id' => $subscription['id']
                ]);
                return;
            }

            // Find user by paddle customer ID
            $user = \App\Models\User::where('paddle_customer_id', $customerId)->first();

            if (!$user) {
                Log::error('User not found for subscription', [
                    'customer_id' => $customerId,
                    'subscription_id' => $subscription['id']
                ]);
                return;
            }

            // Get package from subscription items
            $item = $subscription['items'][0] ?? null;
            if (!$item) {
                Log::error('No items in subscription', [
                    'subscription_id' => $subscription['id']
                ]);
                return;
            }

            $productId = $item['product']['id'];
            $package = Package::where('paddle_product_id', $productId)->first();

            if (!$package) {
                Log::error('Package not found for subscription product', [
                    'product_id' => $productId,
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
                    'paddle_plan_id' => $productId,
                    'current_period_start' => Carbon::parse($subscription['current_billing_period']['starts_at']),
                    'current_period_end' => Carbon::parse($subscription['current_billing_period']['ends_at']),
                    'starts_at' => Carbon::parse($subscription['started_at'] ?? $subscription['created_at']),
                    'expires_at' => Carbon::parse($subscription['current_billing_period']['ends_at']),
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
                'subscription_id' => $subscription['id'] ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function handleSubscriptionUpdated($subscription)
    {
        try {
            Log::info('Processing updated subscription', [
                'subscription_id' => $subscription['id']
            ]);

            $userSubscription = UserSubscription::where('paddle_subscription_id', $subscription['id'])->first();

            if (!$userSubscription) {
                Log::warning('Subscription not found for update', [
                    'paddle_subscription_id' => $subscription['id']
                ]);
                return;
            }

            $userSubscription->update([
                'status' => $subscription['status'],
                'current_period_start' => Carbon::parse($subscription['current_billing_period']['starts_at']),
                'current_period_end' => Carbon::parse($subscription['current_billing_period']['ends_at']),
                'expires_at' => Carbon::parse($subscription['current_billing_period']['ends_at']),
                'paddle_data' => json_encode($subscription)
            ]);

            Log::info('Subscription updated successfully', [
                'user_id' => $userSubscription->user_id,
                'subscription_id' => $userSubscription->id,
                'new_status' => $subscription['status']
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling updated subscription', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscription['id'] ?? null
            ]);
            throw $e;
        }
    }

    protected function handleSubscriptionCanceled($subscription)
    {
        try {
            Log::info('Processing canceled subscription', [
                'subscription_id' => $subscription['id']
            ]);

            $userSubscription = UserSubscription::where('paddle_subscription_id', $subscription['id'])->first();

            if (!$userSubscription) {
                Log::warning('Subscription not found for cancellation', [
                    'paddle_subscription_id' => $subscription['id']
                ]);
                return;
            }

            $user = $userSubscription->user;

            // Downgrade user to basic plan
            if ($user && method_exists($user, 'downgradeToBasic')) {
                $user->downgradeToBasic('paddle_cancelled');
            }

            $userSubscription->update([
                'status' => 'canceled',
                'cancelled_at' => now(),
                'paddle_data' => json_encode($subscription)
            ]);

            Log::info('Subscription canceled successfully', [
                'user_id' => $userSubscription->user_id,
                'subscription_id' => $userSubscription->id
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling canceled subscription', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscription['id'] ?? null
            ]);
            throw $e;
        }
    }
}
