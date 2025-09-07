<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid;

class UserSubscription extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id', 'package_id', 'starts_at', 'expires_at', 'status', 'metadata',
        'paddle_subscription_id', 'paddle_user_id', 'paddle_plan_id',
        'current_period_start', 'current_period_end', 'cancelled_at', 'paddle_data'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
        'paddle_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Basic packages (price = 0) never expire
        if ($this->package && $this->package->isFree()) {
            return true;
        }

        // Check both expires_at (your existing logic) and current_period_end (Paddle logic)
        $expirationDate = $this->current_period_end ?: $this->expires_at;

        return $expirationDate === null || $expirationDate->isFuture();
    }

    public function isExpired(): bool
    {
        // Basic packages never expire
        if ($this->package && $this->package->isFree()) {
            return false;
        }

        $expirationDate = $this->current_period_end ?: $this->expires_at;
        return $expirationDate !== null && $expirationDate->isPast();
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, ['cancelled', 'deleted']);
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function isBasic(): bool
    {
        return $this->package && $this->package->isBasic();
    }

    public function isPremium(): bool
    {
        return $this->package && $this->package->is_premium && $this->isActive();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function($q) {
                // Always include basic packages
                $q->whereHas('package', function($packageQuery) {
                    $packageQuery->where('price', 0.00);
                })
                    // Or check expiration for paid packages
                    ->orWhere(function($expQuery) {
                        $expQuery->where(function($periodQuery) {
                            $periodQuery->whereNull('current_period_end')
                                ->orWhere('current_period_end', '>', now());
                        })
                            ->where(function($expiresQuery) {
                                $expiresQuery->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', now());
                            });
                    });
            });
    }

    public function scopePaddle($query)
    {
        return $query->whereNotNull('paddle_subscription_id');
    }

    public function scopeBasic($query)
    {
        return $query->whereHas('package', function($q) {
            $q->where('price', 0.00)->where('is_premium', false);
        });
    }

    public function scopePremium($query)
    {
        return $query->whereHas('package', function($q) {
            $q->where('is_premium', true);
        });
    }
}
