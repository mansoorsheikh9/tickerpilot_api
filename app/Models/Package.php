<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasUuid;

class Package extends Model
{
    use HasUuid;

    protected $fillable = [
        'name', 'description', 'price', 'max_watchlists',
        'max_stocks_per_watchlist', 'max_chart_layouts',
        'is_premium', 'is_active'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'max_watchlists' => 'integer',
        'max_stocks_per_watchlist' => 'integer',
        'max_chart_layouts' => 'integer',
        'is_premium' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function activeSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class)->where('status', 'active');
    }
}
