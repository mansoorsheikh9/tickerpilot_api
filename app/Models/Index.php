<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Traits\HasUuid;

class Index extends Model
{
    use HasUuid;

    protected $connection = 'pgsql';

    protected $fillable = [
        'symbol',
        'name',
        'description',
        'exchange',
        'currency',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function latestPrice(): HasOne
    {
        return $this->hasOne(IndexPrice::class)->latest('date');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(IndexPrice::class);
    }

    public function watchlistStocks(): MorphMany
    {
        return $this->morphMany(WatchlistStock::class, 'watchable');
    }

    // Helper methods for consistency
    public function getName(): string
    {
        return $this->name ?? $this->description;
    }

    public function getType(): string
    {
        return 'index';
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
