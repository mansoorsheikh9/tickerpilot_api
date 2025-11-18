<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\User;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Google_Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'emails' => 'required|string|emails|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $request->name,
                'emails' => $request->email,
                'password' => Hash::make($request->password),
                'provider' => 'emails',
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
                'emails' => $request->email,
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
            'emails' => 'required|emails',
            'password' => 'required',
        ]);

        $user = User::where('emails', $request->email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'emails' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->provider === 'google') {
            throw ValidationException::withMessages([
                'emails' => ['This account uses Google Sign-In. Please use the Google button above.'],
            ]);
        }

        if ($user->provider === 'emails' && (!$user->password || !Hash::check($request->password, $user->password))) {
            throw ValidationException::withMessages([
                'emails' => ['The provided credentials are incorrect.'],
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
            $email = $payload['emails'];
            $name = $payload['name'];
            $avatar = $payload['picture'] ?? null;

            $user = User::where('google_id', $googleId)->first();

            if (!$user) {
                $existingUser = User::where('emails', $email)->first();

                if ($existingUser) {
                    if ($existingUser->provider === 'emails') {
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
                            'message' => 'An account with this emails already exists with a different Google account.'
                        ], 409);
                    }
                } else {
                    $user = User::create([
                        'name' => $name,
                        'emails' => $email,
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
                    'emails' => $email,
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
                'emails' => $email ?? 'unknown',
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
     * Public endpoint to get user by ID or emails
     */
    public function getUserByIdOrEmail(string $idOrEmail): JsonResponse
    {
        try {
            $user = User::where('id', $idOrEmail)->orWhere('emails', $idOrEmail)->first();
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
            Log::error('Get user by ID or emails failed', [
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
                'message' => 'Only Google users can convert to emails authentication',
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'provider' => 'emails',
            'google_id' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Successfully converted to emails authentication',
            'data' => [
                'user' => $user->load('activeSubscription.package'),
            ]
        ]);
    }

    /**
     * Send password reset link to user's emails
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'emails' => 'required|emails',
        ]);

        try {
            $user = User::where('emails', $request->email)->first();

            if (!$user) {
                // Return success even if user doesn't exist (security best practice)
                return response()->json([
                    'success' => true,
                    'message' => 'If an account exists with this emails, you will receive a password reset link shortly.'
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

            // Generate password reset token
            $token = Str::random(64);

            // Delete any existing password reset tokens for this emails
            DB::table('password_reset_tokens')
                ->where('emails', $user->email)
                ->delete();

            // Create new password reset token
            DB::table('password_reset_tokens')->insert([
                'emails' => $user->email,
                'token' => Hash::make($token),
                'created_at' => Carbon::now()
            ]);

            // Send emails with reset link
            // You can customize the reset URL to point to your frontend
            $resetUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/reset-password?token=' . $token . '&emails=' . urlencode($user->email);

            // Send emails (you'll need to create a mailable or use notification)
            Mail::to($user->email)->send(new \App\Mail\PasswordResetMail($user, $resetUrl, $token));

            return response()->json([
                'success' => true,
                'message' => 'Password reset link has been sent to your emails.'
            ]);

        } catch (\Throwable $e) {
            Log::error('Forgot password failed', [
                'emails' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            'emails' => 'required|emails',
            'password' => 'required|string|min:8|confirmed',
            'token' => 'required|string',
        ]);

        try {
            $user = User::where('emails', $request->email)->first();

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

            // Get the password reset record
            $passwordReset = DB::table('password_reset_tokens')
                ->where('emails', $request->email)
                ->first();

            if (!$passwordReset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired password reset token.'
                ], 400);
            }

            // Check if token matches
            if (!Hash::check($request->token, $passwordReset->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid password reset token.'
                ], 400);
            }

            // Check if token is expired (60 minutes)
            if (Carbon::parse($passwordReset->created_at)->addMinutes(60)->isPast()) {
                DB::table('password_reset_tokens')
                    ->where('emails', $request->email)
                    ->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Password reset token has expired. Please request a new one.'
                ], 400);
            }

            // Update password
            $user->update([
                'password' => Hash::make($request->password)
            ]);

            // Delete the password reset token
            DB::table('password_reset_tokens')
                ->where('emails', $request->email)
                ->delete();

            // Revoke all existing tokens
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Password has been reset successfully. You can now sign in with your new password.'
            ]);

        } catch (\Throwable $e) {
            Log::error('Password reset failed', [
                'emails' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password. Please try again.'
            ], 500);
        }
    }
}
