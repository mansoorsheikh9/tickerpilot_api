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
        // Add /v1 to the base URL
        $this->baseUrl = $this->environment === 'sandbox'
            ? 'https://sandbox-api.paddle.com/v1'
            : 'https://api.paddle.com/v1';
    }

    public function createCheckoutSession($priceId, $customerEmail, $passthrough = null, $successUrl = null, $cancelUrl = null)
    {
        try {
            $customData = [];
            if ($passthrough) {
                $customData = json_decode($passthrough, true);
            }

            $data = [
                'items' => [
                    [
                        'price_id' => $priceId,
                        'quantity' => 1
                    ]
                ],
                'customer_email' => $customerEmail,
                'success_url' => $successUrl ?: config('paddle.success_url'),
                'cancel_url' => $cancelUrl ?: config('paddle.cancel_url')
            ];

            if (!empty($customData)) {
                $data['custom_data'] = $customData;
            }

            Log::info('Creating Paddle checkout session with v1 API', [
                'price_id' => $priceId,
                'customer_email' => $customerEmail,
                'environment' => $this->environment,
                'api_url' => $this->baseUrl . '/checkout'
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/checkout', $data);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['data']['url'])) {
                    Log::info('Paddle checkout session created successfully', [
                        'price_id' => $priceId,
                        'checkout_url' => $result['data']['url'],
                        'session_id' => $result['data']['id']
                    ]);

                    return $result['data']['url'];
                }
            }

            Log::error('Paddle API request failed', [
                'status' => $response->status(),
                'response' => $response->body(),
                'price_id' => $priceId,
                'full_url' => $this->baseUrl . '/checkout/sessions'
            ]);

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
                return ['success' => true, 'data' => $result['data']];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Exception cancelling Paddle subscription', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function testConnection()
    {
        try {
            // Test with v1 API endpoint
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(10)->get($this->baseUrl . '/products', ['per_page' => 1]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Exception testing Paddle connection', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function verifyWebhook($rawBody, $signature)
    {
        try {
            $webhookSecret = config('paddle.webhook_secret');

            if (!$webhookSecret) {
                Log::error('Paddle webhook secret not configured');
                return false;
            }

            $computedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);

            return hash_equals($signature, $computedSignature);
        } catch (\Exception $e) {
            Log::error('Exception verifying Paddle webhook', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
