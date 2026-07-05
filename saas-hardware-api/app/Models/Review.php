<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class Review extends Model
{
    use HasUuids, BelongsToTenant;

    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'tenant_id',
        'product_id',
        'user_id',
        'visitor_id',
        'customer_name',
        'customer_email',
        'rating',
        'comment',
        'verified_purchase',
        'is_approved',
    ];

    protected $casts = [
        'rating'            => 'integer',
        'verified_purchase' => 'boolean',
        'is_approved'       => 'boolean',
    ];

    protected static function booted()
    {
        // Incrementar versión de caché al guardar/eliminar reseñas
        static::saved(function ($review) {
            $tenant = $review->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });

        static::deleted(function ($review) {
            $tenant = $review->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
