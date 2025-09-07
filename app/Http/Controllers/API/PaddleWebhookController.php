<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\PaddleWebhookEvent;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\PaddleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaddleWebhookController extends Controller
{
    protected $paddleService;

    public function __construct(PaddleService $paddleService)
    {
        $this->paddleService = $paddleService;
    }

    public function handle(Request $request)
    {
        try {
            // Billing API webhook signature verification
            $signature = $request->header('Paddle-Signature');
            $timestamp = $request->header('Paddle-Timestamp');
            $rawBody = $request->getContent();

            if (!$this->paddleService->verifyWebhook($rawBody, $signature, $timestamp)) {
                Log::warning('Invalid Paddle webhook signature');
                return response('Invalid signature', 401);
            }

            $data = $request->json()->all();
            $eventType = $data['event_type'] ?? 'unknown';
            $eventId = $data['event_id'] ?? null;

            Log::info('Paddle Billing API webhook received', [
                'event_type' => $eventType,
                'event_id' => $eventId
            ]);

            // Check if we've already processed this event
            if ($eventId) {
                $existingEvent = PaddleWebhookEvent::where('paddle_event_id', $eventId)->first();
                if ($existingEvent) {
                    Log::info('Webhook already processed', ['event_id' => $eventId]);
                    return response('OK');
                }
            }

            // Store the event
            $webhookEvent = null;
            if ($eventId) {
                $webhookEvent = PaddleWebhookEvent::create([
                    'paddle_event_id' => $eventId,
                    'event_type' => $eventType,
                    'event_data' => $data
                ]);
            }

            // Handle different webhook types
            switch ($eventType) {
                case 'subscription.created':
                    $this->handleSubscriptionCreated($data, $webhookEvent);
                    break;
                case 'subscription.updated':
                    $this->handleSubscriptionUpdated($data, $webhookEvent);
                    break;
                case 'subscription.canceled':
                    $this->handleSubscriptionCancelled($data, $webhookEvent);
                    break;
                case 'transaction.completed':
                    $this->handleTransactionCompleted($data, $webhookEvent);
                    break;
                case 'transaction.payment_failed':
                    $this->handlePaymentFailed($data, $webhookEvent);
                    break;
                default:
                    Log::info('Unhandled webhook type', ['event_type' => $eventType]);
            }

            // Mark event as processed
            if ($webhookEvent) {
                $webhookEvent->markAsProcessed();
            }

            return response('OK');

        } catch (\Exception $e) {
            Log::error('Error processing Paddle webhook', [
                'error' => $e->getMessage(),
                'data' => $request->all()
            ]);

            return response('Error', 500);
        }
    }

    protected function handleSubscriptionCreated($data, $webhookEvent = null)
    {
        try {
            $subscriptionData = $data['data'] ?? [];
            $customData = $subscriptionData['custom_data'] ?? [];

            $userId = $customData['user_id'] ?? null;
            $packageId = $customData['package_id'] ?? null;

            if (!$userId) {
                Log::error('No user ID in subscription created webhook', ['data' => $data]);
                return;
            }

            $user = User::find($userId);
            if (!$user) {
                Log::error('User not found for subscription', ['user_id' => $userId]);
                return;
            }

            $package = Package::find($packageId);
            if (!$package) {
                // Try to find by price_id
                $priceId = $subscriptionData['items'][0]['price']['id'] ?? null;
                $package = Package::where('paddle_product_id', $priceId)->first();

                if (!$package) {
                    Log::error('Package not found for subscription', [
                        'package_id' => $packageId,
                        'price_id' => $priceId
                    ]);
                    return;
                }
            }

            $subscription = $user->upgradeToPremium($package->id, [
                'subscription_id' => $subscriptionData['id'],
                'customer_id' => $subscriptionData['customer_id'],
                'status' => $subscriptionData['status'],
                'current_billing_period' => $subscriptionData['current_billing_period'] ?? null,
            ]);

            if ($webhookEvent) {
                $webhookEvent->update(['subscription_id' => $subscription->id]);
            }

            Log::info('Premium subscription created successfully', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'paddle_subscription_id' => $subscriptionData['id'],
                'package_name' => $package->name
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling subscription created webhook', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    protected function handleSubscriptionUpdated($data, $webhookEvent = null)
    {
        try {
            $subscription = UserSubscription::where('paddle_subscription_id', $data['subscription_id'])->first();

            if (!$subscription) {
                Log::error('Subscription not found for update', ['paddle_subscription_id' => $data['subscription_id']]);
                return;
            }

            $newPeriodEnd = isset($data['next_bill_date']) ?
                Carbon::parse($data['next_bill_date']) :
                $subscription->current_period_end;

            $subscription->update([
                'status' => $data['status'] === 'active' ? 'active' : $data['status'],
                'expires_at' => $newPeriodEnd,
                'current_period_end' => $newPeriodEnd,
                'paddle_data' => array_merge($subscription->paddle_data ?? [], $data)
            ]);

            if ($webhookEvent) {
                $webhookEvent->update(['subscription_id' => $subscription->id]);
            }

            Log::info('Subscription updated successfully', [
                'subscription_id' => $subscription->id,
                'new_status' => $data['status']
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling subscription updated webhook', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    protected function handleSubscriptionCancelled($data, $webhookEvent = null)
    {
        try {
            $subscription = UserSubscription::where('paddle_subscription_id', $data['subscription_id'])->first();

            if (!$subscription) {
                Log::error('Subscription not found for cancellation', ['paddle_subscription_id' => $data['subscription_id']]);
                return;
            }

            $user = $subscription->user;
            $basicSubscription = $user->downgradeToBasic('subscription_cancelled');

            if ($webhookEvent) {
                $webhookEvent->update(['subscription_id' => $basicSubscription->id]);
            }

            Log::info('Subscription cancelled and user downgraded to basic', [
                'original_subscription_id' => $subscription->id,
                'new_basic_subscription_id' => $basicSubscription->id,
                'user_id' => $user->id
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling subscription cancelled webhook', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    protected function handlePaymentSucceeded($data, $webhookEvent = null)
    {
        try {
            $subscription = UserSubscription::where('paddle_subscription_id', $data['subscription_id'])->first();

            if (!$subscription) {
                Log::error('Subscription not found for payment success', ['paddle_subscription_id' => $data['subscription_id']]);
                return;
            }

            $newPeriodEnd = isset($data['next_bill_date']) ?
                Carbon::parse($data['next_bill_date']) :
                now()->addMonth();

            $package = $subscription->package;
            $newPeriodStart = $package && $package->billing_cycle === 'yearly' ?
                $newPeriodEnd->copy()->subYear() :
                $newPeriodEnd->copy()->subMonth();

            $subscription->update([
                'status' => 'active',
                'expires_at' => $newPeriodEnd,
                'current_period_start' => $newPeriodStart,
                'current_period_end' => $newPeriodEnd,
                'paddle_data' => array_merge($subscription->paddle_data ?? [], $data)
            ]);

            if ($webhookEvent) {
                $webhookEvent->update(['subscription_id' => $subscription->id]);
            }

            Log::info('Payment succeeded for subscription', [
                'subscription_id' => $subscription->id,
                'amount' => $data['sale_gross'] ?? 'unknown',
                'next_bill_date' => $newPeriodEnd
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling payment succeeded webhook', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    protected function handlePaymentFailed($data, $webhookEvent = null)
    {
        try {
            $subscription = UserSubscription::where('paddle_subscription_id', $data['subscription_id'])->first();

            if (!$subscription) {
                Log::error('Subscription not found for payment failure', ['paddle_subscription_id' => $data['subscription_id']]);
                return;
            }

            $user = $subscription->user;
            $attemptCount = $data['attempt_number'] ?? 1;
            $maxAttempts = 3;

            if ($attemptCount >= $maxAttempts || isset($data['hard_failure']) && $data['hard_failure']) {
                Log::warning('Payment failed - downgrading user to basic package', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                    'attempt_number' => $attemptCount,
                    'amount' => $data['amount'] ?? 'unknown'
                ]);

                $basicSubscription = $user->downgradeToBasic('payment_failed');

                if ($webhookEvent) {
                    $webhookEvent->update(['subscription_id' => $basicSubscription->id]);
                }

                Log::info('User downgraded to basic package due to payment failure', [
                    'user_id' => $user->id,
                    'original_subscription_id' => $subscription->id,
                    'new_basic_subscription_id' => $basicSubscription->id
                ]);
            } else {
                $subscription->update([
                    'status' => 'past_due',
                    'paddle_data' => array_merge($subscription->paddle_data ?? [], $data)
                ]);

                if ($webhookEvent) {
                    $webhookEvent->update(['subscription_id' => $subscription->id]);
                }

                Log::info('Payment failed but will retry', [
                    'subscription_id' => $subscription->id,
                    'attempt_number' => $attemptCount,
                    'max_attempts' => $maxAttempts
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error handling payment failed webhook', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }
}
