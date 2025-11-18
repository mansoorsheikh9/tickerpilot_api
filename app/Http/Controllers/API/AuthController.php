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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
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

            // Create Basic subscription for new user
            $user->ensureActiveSubscription();

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
            Log::error('Registration failed', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

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

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->provider === 'google') {
            throw ValidationException::withMessages([
                'email' => ['This account uses Google Sign-In. Please use the Google button above.'],
            ]);
        }

        if ($user->provider === 'email' && (!$user->password || !Hash::check($request->password, $user->password))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is deactivated',
            ], 403);
        }

        $user->ensureActiveSubscription();
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

            $user = User::where('google_id', $googleId)->first();

            if (!$user) {
                $existingUser = User::where('email', $email)->first();

                if ($existingUser) {
                    if ($existingUser->provider === 'email') {
                        $existingUser->update([
                            'google_id' => $googleId,
                            'avatar' => $avatar,
                            'provider' => 'google',
                            'email_verified_at' => now(),
                            'password' => null,
                        ]);
                        $user = $existingUser;
                    } else {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'An account with this email already exists with a different Google account.'
                        ], 409);
                    }
                } else {
                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'google_id' => $googleId,
                        'avatar' => $avatar,
                        'provider' => 'google',
                        'email_verified_at' => now(),
                        'password' => null,
                    ]);
                }
            } else {
                $user->update([
                    'name' => $name,
                    'email' => $email,
                    'avatar' => $avatar,
                ]);
            }

            if (!$user->is_active) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Account is deactivated',
                ], 403);
            }

            $user->ensureActiveSubscription();
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
            Log::error('Google login failed', [
                'email' => $email ?? 'unknown',
                'error' => $e->getMessage()
            ]);

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
        $user = $request->user();
        $user->ensureActiveSubscription();
        $user->load('activeSubscription.package');
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
     * Public endpoint to get user by ID or email
     */
    public function getUserByIdOrEmail(string $idOrEmail): JsonResponse
    {
        try {
            $user = User::where('id', $idOrEmail)->orWhere('email', $idOrEmail)->first();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Check if user is active
            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'User account is inactive'
                ], 403);
            }

            $user->ensureActiveSubscription();
            $user->load('activeSubscription.package');
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
        } catch (\Throwable $e) {
            Log::error('Get user by ID or email failed', [
                'id_or_email' => $idOrEmail,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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

    /**
     * Send password reset link to user's email
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                // Return success even if user doesn't exist (security best practice)
                return response()->json([
                    'success' => true,
                    'message' => 'If an account exists with this email, you will receive a password reset link shortly.'
                ]);
            }

            // Check if user signed up with Google
            if ($user->provider === 'google') {
                return response()->json([
                    'success' => false,
                    'message' => 'This account uses Google Sign-In. Please sign in with Google instead.'
                ], 400);
            }

            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'This account is inactive. Please contact support.'
                ], 403);
            }

            // Send password reset email
            Password::sendResetLink(['email' => $request->email]);

            return response()->json([
                'success' => true,
                'message' => 'Password reset link has been sent to your email.'
            ]);

        } catch (\Throwable $e) {
            Log::error('Forgot password failed', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send reset link. Please try again.'
            ], 500);
        }
    }

    /**
     * Reset password using token
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'token' => 'required|string',
        ]);

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            // Check if user signed up with Google
            if ($user->provider === 'google') {
                return response()->json([
                    'success' => false,
                    'message' => 'This account uses Google Sign-In. Password reset is not available.'
                ], 400);
            }

            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ])->save();

                    // Revoke all existing tokens
                    $user->tokens()->delete();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json([
                    'success' => true,
                    'message' => 'Password has been reset successfully. You can now sign in with your new password.'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired password reset token.'
            ], 400);

        } catch (\Throwable $e) {
            Log::error('Password reset failed', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password. Please try again.'
            ], 500);
        }
    }
}
