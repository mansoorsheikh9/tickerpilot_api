<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaddleService
{
    protected $vendorId;
    protected $apiKey;
    protected $environment;
    protected $baseUrl;

    public function __construct()
    {
        $this->vendorId = config('paddle.vendor_id');
        $this->apiKey = config('paddle.api_key');
        $this->environment = config('paddle.environment');
        $this->baseUrl = $this->environment === 'sandbox'
            ? 'https://sandbox-vendors.paddle.com/api'
            : 'https://vendors.paddle.com/api';
    }

    public function createCheckoutSession($productId, $customerEmail, $passthrough = null, $successUrl = null, $cancelUrl = null)
    {
        try {
            $data = [
                'vendor_id' => $this->vendorId,
                'vendor_auth_code' => $this->apiKey,
                'product_id' => $productId,
                'customer_email' => $customerEmail,
                'return_url' => $successUrl ?: config('paddle.success_url'),
                'discountable' => 1,
                'customer_country' => 'US',
                'marketing_consent' => 0,
            ];

            if ($passthrough) {
                $data['passthrough'] = $passthrough;
            }

            if ($cancelUrl) {
                $data['cancel_url'] = $cancelUrl;
            } else {
                $data['cancel_url'] = config('paddle.cancel_url');
            }

            Log::info('Creating Paddle checkout session', [
                'product_id' => $productId,
                'customer_email' => $customerEmail,
                'environment' => $this->environment
            ]);

            $response = Http::timeout(30)->post($this->baseUrl . '/2.0/product/generate_pay_link', $data);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['success']) && $result['success']) {
                    Log::info('Paddle checkout session created successfully', [
                        'product_id' => $productId,
                        'checkout_url' => $result['response']['url']
                    ]);

                    return $result['response']['url'];
                } else {
                    Log::error('Paddle API returned success=false', [
                        'response' => $result,
                        'product_id' => $productId
                    ]);
                }
            } else {
                Log::error('Paddle API request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'product_id' => $productId
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Exception creating Paddle checkout', [
                'error' => $e->getMessage(),
                'product_id' => $productId,
                'customer_email' => $customerEmail
            ]);
            return null;
        }
    }

    public function cancelSubscription($subscriptionId)
    {
        try {
            $response = Http::timeout(30)->post($this->baseUrl . '/2.0/subscription/users_cancel', [
                'vendor_id' => $this->vendorId,
                'vendor_auth_code' => $this->apiKey,
                'subscription_id' => $subscriptionId
            ]);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['success']) && $result['success']) {
                    Log::info('Subscription cancelled successfully via Paddle', [
                        'subscription_id' => $subscriptionId
                    ]);
                    return $result;
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

    public function verifyWebhook($data, $signature)
    {
        try {
            $publicKey = config('paddle.public_key');

            if (!$publicKey) {
                Log::error('Paddle public key not configured');
                return false;
            }

            // Remove signature from data for verification
            $dataForVerification = $data;
            unset($dataForVerification['p_signature']);

            // Sort the data by key
            ksort($dataForVerification);

            // Serialize the data
            $serializedData = serialize($dataForVerification);

            // Verify signature
            $verified = openssl_verify(
                $serializedData,
                base64_decode($signature),
                $publicKey,
                OPENSSL_ALGO_SHA1
            );

            if ($verified === 1) {
                return true;
            } elseif ($verified === 0) {
                Log::warning('Paddle webhook signature verification failed', [
                    'signature_provided' => !empty($signature),
                    'public_key_configured' => !empty($publicKey)
                ]);
                return false;
            } else {
                Log::error('Error verifying Paddle webhook signature', [
                    'openssl_error' => openssl_error_string()
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception verifying Paddle webhook', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
