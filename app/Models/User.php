<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuid, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'provider',
        'is_active',
        'preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'preferences' => 'array',
    ];

    public function watchlists()
    {
        return $this->hasMany(Watchlist::class)->orderBy('sort_order');
    }

    public function chartLayouts()
    {
        return $this->hasMany(ChartLayout::class)->orderBy('name');
    }

    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class)->orderBy('created_at', 'desc');
    }

    public function activeSubscription()
    {
        return $this->hasOne(UserSubscription::class)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())
                    ->orWhereHas('package', function ($packageQuery) {
                        $packageQuery->where('price', 0.00);
                    });
            })
            ->with('package')
            ->latest();
    }

    public function getCurrentPackage(): ?Package
    {
        $activeSubscription = $this->activeSubscription;
        if ($activeSubscription) {
            return $activeSubscription->package;
        }

        // Return default basic package if no active subscription
        return Package::where('is_active', true)->where('is_premium', false)->first();
    }

    public function isPremium(): bool
    {
        return $this->getCurrentPackage()?->is_premium ?? false;
    }

    public function canCreateWatchlist(): bool
    {
        $currentCount = $this->watchlists()->count();
        $maxAllowed = $this->getCurrentPackage()?->max_watchlists ?? 1;
        return $currentCount < $maxAllowed;
    }

    public function canAddStockToWatchlist($watchlistId): bool
    {
        $currentPackage = $this->getCurrentPackage();
        $maxStocksPerWatchlist = $currentPackage?->max_stocks_per_watchlist ?? 10;
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        // First check if the watchlist is within the user's package limits
        $userWatchlistIds = $this->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->pluck('id')
            ->toArray();

        if (!in_array($watchlistId, $userWatchlistIds)) {
            return false; // Watchlist is not accessible with current package
        }

        $watchlist = $this->watchlists()->find($watchlistId);
        if (!$watchlist) {
            return false;
        }

        $currentStocks = $watchlist->watchlistStocks()->count();
        return $currentStocks < $maxStocksPerWatchlist;
    }

    public function canCreateChartLayout(): bool
    {
        $currentPackage = $this->getCurrentPackage();
        $maxChartLayouts = $currentPackage?->max_chart_layouts ?? 5;
        $currentLayouts = $this->chartLayouts()->count();

        return $currentLayouts < $maxChartLayouts;
    }

    /**
     * Get accessible watchlists based on current package
     */
    public function getAccessibleWatchlists()
    {
        $currentPackage = $this->getCurrentPackage();
        $maxWatchlists = $currentPackage?->max_watchlists ?? 1;

        return $this->watchlists()
            ->orderBy('sort_order')
            ->limit($maxWatchlists)
            ->get();
    }

    /**
     * Get accessible chart layouts based on current package
     */
    public function getAccessibleChartLayouts()
    {
        $currentPackage = $this->getCurrentPackage();
        $maxChartLayouts = $currentPackage?->max_chart_layouts ?? 5;

        return $this->chartLayouts()
            ->orderBy('name')
            ->limit($maxChartLayouts)
            ->get();
    }

    /**
     * Get package limits summary
     */
    public function getPackageLimits(): array
    {
        $currentPackage = $this->getCurrentPackage();

        return [
            'package_name' => $currentPackage?->name ?? 'Basic',
            'is_premium' => $currentPackage?->is_premium ?? false,
            'limits' => [
                'watchlists' => [
                    'max' => $currentPackage?->max_watchlists ?? 1,
                    'current' => $this->watchlists()->count(),
                    'accessible' => $this->getAccessibleWatchlists()->count(),
                    'can_create_more' => $this->canCreateWatchlist(),
                ],
                'stocks_per_watchlist' => [
                    'max' => $currentPackage?->max_stocks_per_watchlist ?? 10,
                ],
                'chart_layouts' => [
                    'max' => $currentPackage?->max_chart_layouts ?? 5,
                    'current' => $this->chartLayouts()->count(),
                    'accessible' => $this->getAccessibleChartLayouts()->count(),
                    'can_create_more' => $this->canCreateChartLayout(),
                ],
            ],
        ];
    }

    public function ensureActiveSubscription()
    {
        $activeSubscription = $this->activeSubscription;

        if (!$activeSubscription) {
            $this->createBasicSubscription();
        }
    }

    public function createBasicSubscription()
    {
        $basicPackage = Package::where('price', 0.00)
            ->where('is_premium', true)
            ->where('is_active', true)
            ->first();

        if (!$basicPackage) {
            throw new \Exception('Basic package not found');
        }

        // Cancel any existing subscriptions first
        $this->subscriptions()->where('status', 'active')->update([
            'status' => 'cancelled',
            'cancelled_at' => now()
        ]);

        // Create new Basic subscription
        return UserSubscription::create([
            'user_id' => $this->id,
            'package_id' => $basicPackage->id,
            'starts_at' => now(),
            'expires_at' => null,
            'status' => 'active',
            'metadata' => [
                'auto_created' => true,
                'created_reason' => 'ensure_basic_subscription'
            ]
        ]);
    }
}
