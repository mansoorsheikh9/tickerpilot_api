<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPrice extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'stock_prices';

    protected $fillable = [
        'stock_id',
        'date',
        'open',
        'high',
        'low',
        'close',
        'price',
        'change',
        'volume'
    ];

    protected $casts = [
        'date' => 'date',
        'open' => 'decimal:4',
        'high' => 'decimal:4',
        'low' => 'decimal:4',
        'close' => 'decimal:4',
        'price' => 'decimal:4',
        'change' => 'decimal:4',
        'volume' => 'integer',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
