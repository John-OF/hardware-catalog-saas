<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class StockNotification extends Model
{
    use HasUuids, BelongsToTenant;

    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'tenant_id',
        'product_id',
        'variant_id',
        'customer_name',
        'customer_contact',
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** La variante concreta que espera el cliente (MOD-5); null si espera el producto entero. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
