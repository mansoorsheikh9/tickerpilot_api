<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\User;
use App\Models\UserSubscription;
use Google_Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'provider' => 'email',
            ]);

            $this->assignBasicPackage($user);

            $token = $user->createToken('tickerpilot-extension')->accessToken;

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'user' => $user->load('activeSubscription.package'),
                    'token' => $token,
                ]
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        // Check if user exists
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // SECURITY FIX: Prevent email/password login for Google users
        if ($user->provider === 'google') {
            throw ValidationException::withMessages([
                'email' => ['This account uses Google Sign-In. Please use the Google button above.'],
            ]);
        }

        // Additional safety check for null passwords
        if ($user->provider === 'email' && (!$user->password || !Hash::check($request->password, $user->password))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if account is active
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is deactivated',
            ], 403);
        }

        $token = $user->createToken('tickerpilot-extension')->accessToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user->load('activeSubscription.package'),
                'token' => $token,
            ]
        ]);
    }

    public function googleLogin(Request $request): JsonResponse
    {
        $request->validate([
            'google_token' => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            $client = new Google_Client(['client_id' => env('GOOGLE_CLIENT_ID')]);
            $payload = $client->verifyIdToken($request->google_token);

            if (!$payload) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Google token'
                ], 401);
            }

            $googleId = $payload['sub'];
            $email = $payload['email'];
            $name = $payload['name'];
            $avatar = $payload['picture'] ?? null;

            // IMPROVED: Better user lookup logic
            // First, try to find by Google ID (most reliable)
            $user = User::where('google_id', $googleId)->first();

            if (!$user) {
                // Check if email exists with different provider
                $existingUser = User::where('email', $email)->first();

                if ($existingUser) {
                    if ($existingUser->provider === 'email') {
                        // Link existing email account to Google
                        $existingUser->update([
                            'google_id' => $googleId,
                            'avatar' => $avatar,
                            'provider' => 'google',
                            'email_verified_at' => now(),
                            'password' => null, // Remove password since using Google
                        ]);
                        $user = $existingUser;
                    } else {
                        // Edge case: email exists with different Google ID
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'An account with this email already exists with a different Google account.'
                        ], 409);
                    }
                } else {
                    // Create new Google user
                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'google_id' => $googleId,
                        'avatar' => $avatar,
                        'provider' => 'google',
                        'email_verified_at' => now(),
                        'password' => null,
                    ]);

                    $this->assignBasicPackage($user);
                }
            } else {
                // Update existing Google user's profile information
                $user->update([
                    'name' => $name,
                    'email' => $email,
                    'avatar' => $avatar,
                ]);
            }

            // Check if account is active
            if (!$user->is_active) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Account is deactivated',
                ], 403);
            }

            $token = $user->createToken('tickerpilot-extension')->accessToken;

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Google login successful',
                'data' => [
                    'user' => $user->load('activeSubscription.package'),
                    'token' => $token,
                ]
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Google authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->token()->revoke();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user()->load('activeSubscription.package');
        $package = $user->getCurrentPackage();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'package' => $package,
                'is_premium' => $user->isPremium(),
                'limits' => [
                    'watchlists' => [
                        'current' => $user->watchlists()->count(),
                        'max' => $package?->max_watchlists ?? 1,
                    ],
                    'stocks_per_watchlist' => $package?->max_stocks_per_watchlist ?? 10,
                    'chart_layouts' => [
                        'current' => $user->chartLayouts()->count(),
                        'max' => $package?->max_chart_layouts ?? 5,
                    ]
                ]
            ]
        ]);
    }

    /**
     * Convert Google user back to email/password user
     */
    public function convertToEmailAuth(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if ($user->provider !== 'google') {
            return response()->json([
                'success' => false,
                'message' => 'Only Google users can convert to email authentication',
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'provider' => 'email',
            'google_id' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Successfully converted to email authentication',
            'data' => [
                'user' => $user->load('activeSubscription.package'),
            ]
        ]);
    }

    private function assignBasicPackage(User $user): void
    {
        $basicPackage = Package::where('is_premium', false)->first();

        if ($basicPackage) {
            UserSubscription::create([
                'user_id' => $user->id,
                'package_id' => $basicPackage->id,
                'starts_at' => now(),
                'expires_at' => null,
                'status' => 'active',
            ]);
        }
    }

    public function requestUpgrade(Request $request): JsonResponse
    {
        $user = $request->user();
        $whatsappNumber = env('WHATSAPP_NUMBER', '+1234567890');
        $message = urlencode("Hi! I'm interested in upgrading to Premium for TickerPilot. My email: {$user->email}");

        return response()->json([
            'success' => true,
            'message' => 'Upgrade request received',
            'data' => [
                'whatsapp_url' => "https://wa.me/{$whatsappNumber}?text={$message}",
                'whatsapp_number' => $whatsappNumber,
            ]
        ]);
    }
}
