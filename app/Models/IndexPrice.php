<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid;

class IndexPrice extends Model
{
    use HasUuid;

    protected $connection = 'pgsql';

    protected $fillable = [
        'index_id',
        'price',
        'change',
        'change_percent',
        'volume',
        'date'
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'change' => 'decimal:4',
        'change_percent' => 'decimal:2',
        'volume' => 'integer',
        'date' => 'date',
    ];

    public function index(): BelongsTo
    {
        return $this->belongsTo(Index::class);
    }
}
