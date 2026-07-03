<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class Product extends Model
{
    use HasUuids, BelongsToTenant;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'category_id', 'name', 'brand', 'price', 'sale_price',
        'stock', 'description', 'specs',
        'image_url', 'thumbnail_url', 'is_active',
    ];

    protected $casts = [
        'specs'      => 'array',
        'price'      => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    protected static function booted()
    {
        // Incrementar versión de caché al modificar productos para invalidar la caché pública
        static::saved(function ($product) {
            $tenant = $product->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });

        static::deleted(function ($product) {
            $tenant = $product->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // Accessor útil para el frontend
    public function getIsAvailableAttribute(): bool
    {
        return $this->stock > 0;
    }
}
