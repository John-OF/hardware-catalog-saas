<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = ['product_id', 'image_url', 'thumbnail_url', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
        // TEC-15: en la base, la ruta dentro del disco; al leer, la URL completa.
        'image_url' => \App\Casts\ImagenDelDisco::class,
        'thumbnail_url' => \App\Casts\ImagenDelDisco::class,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
