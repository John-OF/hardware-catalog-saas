<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Notifications\BackInStockNotification;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Una variante de un producto: "16 GB", "Negro / 1 TB" (MOD-5).
 *
 * Lleva lo que cambia de una a otra —opciones, precio, oferta, stock, SKU, umbral
 * de stock bajo y una foto propia—. Lo que es de la ficha —nombre, marca,
 * categoria, descripcion, specs, galeria, reseñas— se queda en `Product`.
 */
class ProductVariant extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * Cuantas variantes puede tener un producto. No es un limite del plan: para
     * el plan cuenta la ficha. Es un techo fijo para que nadie meta un catalogo
     * entero dentro de un solo producto.
     */
    public const MAXIMO_POR_PRODUCTO = 30;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected $fillable = [
        'product_id', 'options', 'sku', 'price', 'sale_price', 'stock',
        'low_stock_threshold', 'image_url', 'thumbnail_url', 'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock' => 'integer',
        'low_stock_threshold' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $appends = ['nombre'];

    protected static function booted(): void
    {
        // Mismo disparador que el de `Product`, un nivel mas abajo: quien se
        // apunto a ESTA variante agotada se entera cuando vuelve ESTA, no cuando
        // repone otra del mismo producto.
        static::updated(function (ProductVariant $variante) {
            if ($variante->wasChanged('stock')
                && (int) $variante->getOriginal('stock') <= 0
                && (int) $variante->stock > 0) {
                $variante->notificarListaDeEspera();
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * "Capacidad: 16 GB / Color: Negro" contado corto: "16 GB / Negro". Es lo que
     * se enseña en la ficha, el carrito y el pedido, y lo que se guarda como
     * snapshot en la linea (`order_items.variant_name`).
     */
    public function getNombreAttribute(): string
    {
        return collect($this->options ?? [])
            ->pluck('value')
            ->map(fn ($valor) => trim((string) $valor))
            ->filter()
            ->implode(' / ');
    }

    /** El precio que se cobra: el de oferta cuando existe (mismo criterio que la ficha). */
    public function precioVisible(): float
    {
        return (float) ($this->sale_price ?? $this->price);
    }

    /**
     * Avisa a quien espera esta variante. Mismas reglas que
     * `Product::notifyStockSubscribers()` (FUN-1b): solo a quien dejo correo, y
     * `notified_at` se marca fila a fila despues de encolar.
     */
    public function notificarListaDeEspera(): int
    {
        $pendientes = StockNotification::withoutTenant()
            ->where('tenant_id', $this->tenant_id)
            ->where('variant_id', $this->id)
            ->whereNull('notified_at')
            ->get();

        $producto = Product::withoutTenant()->find($this->product_id);
        $enviados = 0;

        if (! $producto) {
            return 0;
        }

        foreach ($pendientes as $suscripcion) {
            $contacto = trim((string) $suscripcion->customer_contact);

            if (! filter_var($contacto, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                Notification::route('mail', $contacto)
                    ->notify(new BackInStockNotification($producto, $suscripcion->customer_name, $this));

                $suscripcion->update(['notified_at' => now()]);
                $enviados++;
            } catch (\Throwable $e) {
                Log::error('No se pudo avisar de la reposición de una variante', [
                    'tenant_id' => $this->tenant_id,
                    'product_id' => $this->product_id,
                    'variant_id' => $this->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $enviados;
    }
}
