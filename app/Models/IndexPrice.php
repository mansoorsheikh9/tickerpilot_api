<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndexPrice extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'index_prices';

    protected $fillable = [
        'index_id',
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

    public function index(): BelongsTo
    {
        return $this->belongsTo(Index::class);
    }
}
