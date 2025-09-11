<?php

namespace App\Http\Controllers;

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

    protected function findUserByCustomerId($customerId)
    {
        // Get customer data from Paddle API
        $customerData = $this->paddleService->getCustomer($customerId);

        if (!$customerData || !isset($customerData['email'])) {
            Log::error('Could not get customer email from Paddle', [
                'customer_id' => $customerId,
                'customer_data' => $customerData
            ]);
            return null;
        }

        // Find user by email (solid approach since Paddle customer is created with user's email)
        $user = \App\Models\User::where('email', $customerData['email'])->first();

        if (!$user) {
            Log::error('No user found with email from Paddle customer', [
                'customer_id' => $customerId,
                'email' => $customerData['email']
            ]);
            return null;
        }

        Log::info('Found user by email from Paddle customer', [
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'email' => $customerData['email']
        ]);

        return $user;
    }

    protected function getBasicPackage()
    {
        $basicPackage = Package::where('price', 0)->first();

        if (!$basicPackage) {
            Log::error('Basic package not found - this is a critical configuration issue');
            throw new \Exception('Basic package with price 0 not found');
        }

        return $basicPackage;
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

            // Find user by checking existing subscription with this paddle customer ID
            $user = $this->findUserByCustomerId($customerId);

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

            // Update existing subscription (user already has free subscription)
            $subscription = UserSubscription::where('user_id', $user->id)->first();

            if (!$subscription) {
                Log::error('No existing subscription found for user', [
                    'user_id' => $user->id,
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            // Upgrade from free to paid subscription
            $subscription->update([
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
            ]);

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

            // Find user by customer ID (works for both new and existing users)
            $user = $this->findUserByCustomerId($customerId);

            if (!$user) {
                Log::warning('User not found for failed payment', [
                    'customer_id' => $customerId,
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            // Get user's subscription
            $existingSubscription = UserSubscription::where('user_id', $user->id)->first();

            if (!$existingSubscription) {
                Log::warning('No subscription found for user with failed payment', [
                    'user_id' => $user->id,
                    'transaction_id' => $transaction['id']
                ]);
                return;
            }

            Log::info('Payment failed for user - downgrading to basic', [
                'user_id' => $user->id,
                'transaction_id' => $transaction['id']
            ]);

            // Get basic package
            $basicPackage = $this->getBasicPackage();

            // Downgrade to basic package
            $existingSubscription->update([
                'package_id' => $basicPackage->id,
                'status' => 'active', // Keep active but on basic plan
                'paddle_subscription_id' => null, // Remove Paddle details
                'paddle_user_id' => null,
                'paddle_plan_id' => null,
                'expires_at' => null, // Basic plan doesn't expire
                'current_period_start' => null,
                'current_period_end' => null,
                'cancelled_at' => now(),
                'paddle_data' => json_encode([
                    'reason' => 'payment_failed',
                    'failed_transaction' => $transaction,
                    'downgraded_at' => now()
                ])
            ]);

            Log::info('User downgraded to basic plan due to payment failure', [
                'user_id' => $user->id,
                'subscription_id' => $existingSubscription->id
            ]);

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

            // Find user by checking existing subscription with this paddle customer ID
            $user = $this->findUserByCustomerId($customerId);

            if (!$user) {
                Log::error('User not found for customer', [
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

            // Update existing subscription (user already has free subscription)
            $userSubscription = UserSubscription::where('user_id', $user->id)->first();

            if (!$userSubscription) {
                Log::error('No existing subscription found for user', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription['id']
                ]);
                return;
            }

            // Upgrade from free to paid subscription
            $userSubscription->update([
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
            ]);

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

            // Get basic package
            $basicPackage = $this->getBasicPackage();

            // Downgrade to basic package
            $userSubscription->update([
                'package_id' => $basicPackage->id,
                'status' => 'active', // Keep active but on basic plan
                'paddle_subscription_id' => null, // Remove Paddle details
                'paddle_user_id' => null,
                'paddle_plan_id' => null,
                'expires_at' => null, // Basic plan doesn't expire
                'current_period_start' => null,
                'current_period_end' => null,
                'cancelled_at' => now(),
                'paddle_data' => json_encode([
                    'reason' => 'subscription_canceled',
                    'canceled_subscription' => $subscription,
                    'downgraded_at' => now()
                ])
            ]);

            Log::info('User downgraded to basic plan due to subscription cancellation', [
                'user_id' => $user->id,
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
