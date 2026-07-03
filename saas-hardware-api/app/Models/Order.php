<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasUuids, BelongsToTenant;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected $fillable = [
        'tenant_id', 'customer_name', 'customer_phone',
        'customer_note', 'status', 'total',
    ];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
