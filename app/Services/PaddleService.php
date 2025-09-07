<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaddleService
{
    protected $apiKey;
    protected $environment;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('paddle.api_key');
        $this->environment = config('paddle.environment');
        $this->baseUrl = $this->environment === 'sandbox'
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    /**
     * Create a checkout session using Billing API
     */
    public function createCheckoutSession($priceId, $customerEmail, $passthrough = null, $successUrl = null, $cancelUrl = null)
    {
        try {
            $data = [
                'items' => [
                    [
                        'price_id' => $priceId,
                        'quantity' => 1
                    ]
                ],
                'customer_email' => $customerEmail,
                'success_url' => $successUrl ?: config('paddle.success_url'),
                'cancel_url' => $cancelUrl ?: config('paddle.cancel_url'),
            ];

            // Add custom data if provided
            if ($passthrough) {
                $data['custom_data'] = json_decode($passthrough, true);
            }

            Log::info('Creating Paddle checkout session (Billing API)', [
                'price_id' => $priceId,
                'customer_email' => $customerEmail,
                'environment' => $this->environment
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/checkout/sessions', $data);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['data']['url'])) {
                    Log::info('Paddle checkout session created successfully', [
                        'price_id' => $priceId,
                        'checkout_url' => $result['data']['url'],
                        'session_id' => $result['data']['id']
                    ]);

                    return $result['data']['url'];
                } else {
                    Log::error('Paddle API returned unexpected response format', [
                        'response' => $result,
                        'price_id' => $priceId
                    ]);
                }
            } else {
                Log::error('Paddle API request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'price_id' => $priceId
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Exception creating Paddle checkout', [
                'error' => $e->getMessage(),
                'price_id' => $priceId,
                'customer_email' => $customerEmail
            ]);
            return null;
        }
    }

    /**
     * Get subscription details using Billing API
     */
    public function getSubscription($subscriptionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->get($this->baseUrl . '/subscriptions/' . $subscriptionId);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['data'])) {
                    return $result;
                }
            }

            Log::error('Paddle API error getting subscription', [
                'subscription_id' => $subscriptionId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exception getting Paddle subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Cancel a subscription using Billing API
     */
    public function cancelSubscription($subscriptionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/subscriptions/' . $subscriptionId . '/cancel', [
                'effective_from' => 'immediately'
            ]);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['data'])) {
                    Log::info('Subscription cancelled successfully via Paddle', [
                        'subscription_id' => $subscriptionId
                    ]);
                    return ['success' => true, 'data' => $result['data']];
                }
            }

            Log::error('Paddle API error cancelling subscription', [
                'subscription_id' => $subscriptionId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Exception cancelling Paddle subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Pause a subscription using Billing API
     */
    public function pauseSubscription($subscriptionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/subscriptions/' . $subscriptionId . '/pause');

            if ($response->successful()) {
                $result = $response->json();
                return ['success' => true, 'data' => $result['data']];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Exception pausing Paddle subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Resume a subscription using Billing API
     */
    public function resumeSubscription($subscriptionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/subscriptions/' . $subscriptionId . '/resume');

            if ($response->successful()) {
                $result = $response->json();
                return ['success' => true, 'data' => $result['data']];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Exception resuming Paddle subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Verify webhook signature for Billing API
     */
    public function verifyWebhook($rawBody, $signature, $timestamp)
    {
        try {
            $webhookSecret = config('paddle.webhook_secret');

            if (!$webhookSecret) {
                Log::error('Paddle webhook secret not configured');
                return false;
            }

            // Billing API uses timestamp + body for signature verification
            $signedPayload = $timestamp . ':' . $rawBody;
            $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

            if (hash_equals($expectedSignature, $signature)) {
                return true;
            }

            Log::warning('Paddle webhook signature verification failed', [
                'expected' => $expectedSignature,
                'received' => $signature
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Exception verifying Paddle webhook', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get customer details
     */
    public function getCustomer($customerId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->get($this->baseUrl . '/customers/' . $customerId);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Exception getting Paddle customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * List transactions for a subscription
     */
    public function getSubscriptionTransactions($subscriptionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->get($this->baseUrl . '/transactions', [
                'subscription_id' => $subscriptionId
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Exception getting subscription transactions', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Test the API connection
     */
    public function testConnection()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(10)->get($this->baseUrl . '/products', [
                'per_page' => 1
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Exception testing Paddle connection', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
