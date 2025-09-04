<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Traits\HasUuid;

class Stock extends Model
{
    use HasUuid;

    protected $connection = 'pgsql';
    protected $table = 'stocks';

    protected $fillable = [
        'symbol',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function latestPrice(): HasOne
    {
        return $this->hasOne(StockPrice::class)->latest('date');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    public function watchlistStocks(): MorphMany
    {
        return $this->morphMany(WatchlistStock::class, 'watchable');
    }

    // Helper methods for consistency
    public function getName(): string
    {
        return $this->description;
    }

    public function getType(): string
    {
        return 'stock';
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
