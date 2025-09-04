<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid;

class Watchlist extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id', 'name', 'description', 'settings', 'sort_order'
    ];

    protected $casts = [
        'settings' => 'array',
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(WatchlistSection::class)->orderBy('sort_order');
    }

    public function watchlistStocks(): HasMany
    {
        return $this->hasMany(WatchlistStock::class);
    }

    // Updated method to get all watchable items
    public function getWatchableItems()
    {
        return $this->watchlistStocks()
            ->with(['watchable.latestPrice'])
            ->orderBy('sort_order')
            ->get();
    }
}
