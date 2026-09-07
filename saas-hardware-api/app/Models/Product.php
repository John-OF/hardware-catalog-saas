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
        'category_id', 'sku', 'name', 'brand', 'price', 'sale_price',
        'stock', 'low_stock_threshold', 'description', 'specs',
        'image_url', 'thumbnail_url', 'is_active', 'sort_order', 'status',
    ];

    protected $casts = [
        'description'          => \App\Casts\SanitizedHtml::class,
        'specs'                => 'array',
        'price'                => 'decimal:2',
        'sale_price'           => 'decimal:2',
        'low_stock_threshold'  => 'integer',
        'is_active'            => 'boolean',
        'sort_order'           => 'integer',
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

        // "Avísame cuando llegue": al reponer stock (de 0 a >0) notificar a los interesados
        static::updated(function ($product) {
            if ($product->wasChanged('stock')
                && (int) $product->getOriginal('stock') <= 0
                && (int) $product->stock > 0) {
                $product->notifyStockSubscribers();
            }
        });
    }

    /**
     * Avisa a los clientes en lista de espera de que el producto volvió a estar disponible.
     *
     * **FUN-1a (2026-09-07): todavía no envía nada, y justo por eso ya no marca
     * `notified_at`.** Antes lo marcaba: escribía un log "simulado" y daba por avisada
     * a toda la lista. El aviso no es que no se enviara — es que se CONSUMÍA: cada
     * reposición dejaba las filas fuera de lo pendiente sin que a nadie le llegara
     * nada, así que al cablear el envío de verdad (FUN-1b, paso 3 de
     * `docs/pendientes.md`) esa gente ya no lo habría recibido nunca. Dejarlas
     * pendientes es lo que hace que el arreglo futuro las alcance.
     *
     * El log se conserva porque mientras tanto es lo único que dice cuánta gente
     * está esperando; el disparador (`booted`) sigue igual.
     *
     * **Al cablear el envío (FUN-1b):** encolar la notificación y marcar `notified_at`
     * SOLO después de encolar, nunca antes. Y hay que decidir el canal primero:
     * `customer_contact` es un campo libre que admite teléfono o correo indistintamente.
     */
    public function notifyStockSubscribers(): int
    {
        $pending = $this->stockNotifications()->whereNull('notified_at')->get();

        foreach ($pending as $subscription) {
            \Illuminate\Support\Facades\Log::info('Reposición de stock: aviso pendiente de enviar', [
                'tenant_id'   => $this->tenant_id,
                'product_id'  => $this->id,
                'product'     => $this->name,
                'to_name'     => $subscription->customer_name,
                'to_contact'  => $subscription->customer_contact,
            ]);
        }

        return $pending->count();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviews(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function stockNotifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockNotification::class);
    }

    // Accessor útil para el frontend
    public function getIsAvailableAttribute(): bool
    {
        return $this->stock > 0;
    }
}
