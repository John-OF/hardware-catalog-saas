<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;
use App\Notifications\BackInStockNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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
     * **Solo se avisa por correo a quien dejó un correo (FUN-1b).** `customer_contact`
     * es un campo libre en el que el cliente escribe un teléfono o un email, como le
     * parece. Los teléfonos **se quedan pendientes a propósito**: aparecen en la lista
     * de espera del panel para que el dueño escriba por WhatsApp, que en este rubro es
     * lo que va a hacer de todas formas. La alternativa —partir la columna en dos con
     * una migración que reparta lo existente mirando si tiene arroba— es más trabajo
     * para el mismo resultado, y adivinando sobre datos que ya escribió el cliente.
     *
     * **`notified_at` se marca fila a fila y SOLO después de encolar**, que es la regla
     * que dejó escrita FUN-1a. Antes se marcaba la lista entera antes de enviar nada, y
     * cada reposición la consumía sin que a nadie le llegara un aviso.
     *
     * Devuelve cuántos avisos se encolaron; los pendientes por teléfono no cuentan.
     */
    public function notifyStockSubscribers(): int
    {
        // `withoutTenant()` con el filtro escrito a mano, y no la relación normal, por
        // el fallo en cerrado de AUD-4: quien repone stock hoy siempre tiene tienda
        // resuelta (panel y devolución de un pedido cancelado), pero el día que esto
        // se dispare desde un comando, un seeder o un job —una importación CSV
        // encolada, por ejemplo— la relación devolvería CERO filas y nadie se
        // enteraría de nada. Sería el mismo fallo silencioso de FUN-1a y FUN-10 por
        // tercera vez. El aislamiento no se pierde: el `tenant_id` del producto se
        // filtra aquí explícitamente.
        $pending = StockNotification::withoutTenant()
            ->where('tenant_id', $this->tenant_id)
            ->where('product_id', $this->id)
            ->whereNull('notified_at')
            ->get();

        $enviados = 0;

        foreach ($pending as $subscription) {
            $contacto = trim((string) $subscription->customer_contact);

            if (! filter_var($contacto, FILTER_VALIDATE_EMAIL)) {
                // Teléfono: sigue esperando, y el dueño lo ve en el panel.
                continue;
            }

            try {
                Notification::route('mail', $contacto)
                    ->notify(new BackInStockNotification($this, $subscription->customer_name));

                // Dentro del try y por fila: si el correo de uno falla, los demás
                // siguen avisados y ese sigue pendiente para el próximo intento.
                $subscription->update(['notified_at' => now()]);
                $enviados++;
            } catch (\Throwable $e) {
                // El stock ya está repuesto y guardado. Un mailer caído no puede
                // devolverle un error al dueño, que lo único que hizo fue editar un
                // producto. Mismo criterio que el aviso de pedido nuevo.
                Log::error('No se pudo avisar de la reposición de stock', [
                    'tenant_id'  => $this->tenant_id,
                    'product_id' => $this->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $enviados;
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
