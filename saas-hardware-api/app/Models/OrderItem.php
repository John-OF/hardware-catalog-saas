<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    use HasUuids;

    // Los items son un snapshot inmutable del pedido; no necesitan timestamps.
    public $timestamps = false;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected $fillable = [
        'order_id', 'product_id', 'variant_id', 'product_name', 'variant_name',
        'unit_price', 'unit_cost', 'quantity', 'subtotal',
    ];

    // MOD-6: el costo de la linea solo lo ve un admin del panel, y lo ensena
    // `App\Support\Costos`. Un pedido tambien se devuelve al cliente que lo
    // hizo (`PublicOrdersController`): ahi el costo no pinta nada.
    protected $hidden = ['unit_cost'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'unit_cost'  => 'decimal:2',
        'subtotal'   => 'decimal:2',
        'quantity'   => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * "Memoria Kingston Fury (16 GB)": el nombre de la linea tal como se vendio,
     * para los correos. Sale de los dos snapshots, no del producto actual.
     */
    public function descripcion(): string
    {
        return $this->variant_name
            ? "{$this->product_name} ({$this->variant_name})"
            : $this->product_name;
    }
}
