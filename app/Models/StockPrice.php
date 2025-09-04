<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class StockPrice extends Model
{
    use HasUuid;

    // Use the stocks database connection
    protected $connection = 'pgsql';

    protected $fillable = [
        'stock_id', 'date', 'open', 'high', 'low', 'close', 'price', 'change', 'volume'
    ];

    protected $casts = [
        'date' => 'date',
        'open' => 'decimal:4',
        'high' => 'decimal:4',
        'low' => 'decimal:4',
        'close' => 'decimal:4',
        'price' => 'decimal:4',
        'change' => 'decimal:8',
        'volume' => 'integer',
    ];

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
