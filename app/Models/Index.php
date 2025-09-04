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
    protected $table = 'indices';

    protected $fillable = [
        'symbol',
        'description'
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
        return $this->description;
    }

    public function getType(): string
    {
        return 'index';
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query;
    }
}
