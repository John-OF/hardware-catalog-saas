<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToTenant;
use App\Notifications\BackInStockNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class Product extends Model
{
    /**
     * `SoftDeletes` es la papelera de MOD-8, y lo que importa de ponerlo aqui es
     * lo que arregla **sin tocar ninguna consulta**: el catalogo publico, el
     * buscador, el armador, los reportes, el resumen y los topes del plan pasan
     * todos por Eloquent, asi que un producto en la papelera desaparece de los
     * seis a la vez. La unica consulta de productos que NO va por aqui es
     * `ImageService::borrarSiNadieLasUsa()`, que usa `DB::table` a proposito:
     * para ella un producto en la papelera **si** cuenta como que usa su foto,
     * que es justo lo que impide que borrar otro producto le deje sin imagenes.
     */
    use HasUuids, BelongsToTenant, SoftDeletes;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'category_id', 'sku', 'name', 'brand', 'price', 'sale_price', 'cost',
        // MOD-15: el precio por mayor, `[{"min": 10, "price": 90}]`. Lo normaliza
        // siempre `App\Support\PreciosPorCantidad`, nunca se escribe a mano.
        'price_tiers',
        'stock', 'low_stock_threshold', 'description', 'specs',
        'image_url', 'thumbnail_url', 'is_active', 'sort_order', 'status',
    ];

    /**
     * El costo de compra NO sale en ninguna respuesta salvo que alguien lo pida
     * (MOD-6).
     *
     * El catalogo publico devuelve el modelo entero en media docena de sitios
     * -listado, ficha, relacionados, buscador, armador- y varios van por cache.
     * Ocultarlo aqui y ensenarlo a mano (`App\Support\Costos`) falla en cerrado:
     * una consulta publica nueva no filtra el costo por descuido, sino que no lo
     * lleva de entrada. Al reves, cualquier ruta nueva que se olvidara de
     * quitarlo se lo estaria ensenando a la competencia.
     */
    protected $hidden = ['cost'];

    protected $casts = [
        'description'          => \App\Casts\SanitizedHtml::class,
        'specs'                => 'array',
        'price'                => 'decimal:2',
        'sale_price'           => 'decimal:2',
        'price_tiers'          => 'array',
        'cost'                 => 'decimal:2',
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
            // MOD-5: quien espera una variante concreta lo avisa la variante
            // (`ProductVariant::notificarListaDeEspera()`). Aqui solo quien se
            // apunto al producto entero —un producto sin variantes, o uno que las
            // tuvo despues de que se apuntara—, que se da por servido en cuanto
            // vuelve a haber algo.
            ->whereNull('variant_id')
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

    public function variants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    /**
     * Pone en la ficha el resumen de sus variantes (MOD-5).
     *
     * Con variantes, el precio y el stock de verdad viven en cada una; pero el
     * catalogo ordena por precio, filtra "solo en stock", pinta la tarjeta y
     * sugiere relacionados leyendo `products`. En vez de reescribir todo eso,
     * `products` guarda un resumen: el precio (y la oferta) de la variante mas
     * barata —lo que el comprador ve como "desde"— y la suma del stock.
     *
     * El stock negativo de una variante (una venta de mostrador por encima de lo
     * registrado) cuenta como cero: no puede restarle disponibilidad a otra.
     *
     * Sin variantes no toca nada: la ficha conserva los valores que tuviera. Por
     * eso quitar la ultima variante deja el ultimo resumen como precio y stock
     * del producto, y hay que revisarlos.
     *
     * Hay que llamarlo despues de CUALQUIER cambio de precio o stock de una
     * variante que no pase por el formulario: una venta, una devolucion, un
     * ajuste de precios en lote.
     */
    public function sincronizarResumenDeVariantes(): void
    {
        // `withoutTenant()` con el filtro a mano, por el mismo motivo que la lista
        // de espera: este calculo no puede dar vacio en silencio si algun dia se
        // llama sin tienda resuelta.
        $variantes = ProductVariant::withoutTenant()
            ->where('product_id', $this->id)
            ->get();

        if ($variantes->isEmpty()) {
            return;
        }

        $this->forceFill(self::resumenDeVariantes(
            $variantes->map(fn (ProductVariant $v) => $v->only(['price', 'sale_price', 'cost', 'price_tiers', 'stock']))->all()
        ));

        $this->save();
    }

    /**
     * El resumen que va en `products` para una lista de variantes.
     *
     * Vive aparte de `sincronizarResumenDeVariantes()` porque el import CSV
     * (MOD-12) crea la ficha y sus variantes en el mismo INSERT en lote y ya
     * tiene los numeros en memoria: releerlos de la base para calcular lo mismo
     * serian dos consultas por producto importado. Calcularlo en el importador
     * por su cuenta era la otra opcion, y es justo la que deja que los dos
     * resumenes se separen el dia que cambie la regla.
     *
     * @param  array<int, array<string, mixed>>  $variantes  filas con price, sale_price, cost, price_tiers y stock
     * @return array{price: mixed, sale_price: mixed, cost: mixed, price_tiers: mixed, stock: int}
     */
    public static function resumenDeVariantes(array $variantes): array
    {
        $masBarata = collect($variantes)
            ->sortBy(fn (array $v) => (float) ($v['sale_price'] ?? $v['price']))
            ->first();

        return [
            'price'      => $masBarata['price'],
            'sale_price' => $masBarata['sale_price'] ?? null,
            // MOD-6: el costo sigue al precio para que la ficha no mezcle el precio
            // de una variante con el costo de otra. Emparejados describen siempre a
            // la misma —la mas barata—, que es lo que ya significaba este resumen.
            'cost'       => $masBarata['cost'] ?? null,
            // MOD-15: y los tramos de precio por mayor, por lo mismo. La tarjeta
            // del catalogo lee la ficha, asi que con esto puede decir "hay precio
            // por mayor" sin cargar las variantes; lo que de verdad se cobra sigue
            // saliendo de la variante elegida, que es la regla entera de MOD-5.
            'price_tiers' => $masBarata['price_tiers'] ?? null,
            'stock'      => collect($variantes)->sum(fn (array $v) => max(0, (int) $v['stock'])),
        ];
    }

    /**
     * Los tramos de precio por mayor, siempre en su forma canónica (MOD-15).
     *
     * Se normaliza **al leer** y no solo al guardar por dos motivos. Uno: una
     * fila escrita a mano en la base, o por una versión anterior de las reglas,
     * no puede hacer que el catálogo cobre cualquier cosa. Y dos, el que no se
     * ve: `json_encode(90.0)` escribe `90`, así que al releer la columna un
     * precio redondo vuelve como entero y uno con céntimos como decimal. Todo lo
     * que cobra castea, así que no cambia ningún total — pero deja dos tipos
     * distintos en la misma columna según el número, y eso acaba rompiendo la
     * primera comparación estricta que alguien escriba.
     */
    protected function priceTiers(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($valor) => \App\Support\PreciosPorCantidad::paraGuardar(
                is_string($valor) ? json_decode($valor, true) : $valor,
            ),
        );
    }

    /** El precio que se cobra por una unidad: el de oferta cuando existe. */
    public function precioVisible(): float
    {
        return (float) ($this->sale_price ?? $this->price);
    }

    /**
     * Lo que cuesta cada unidad al llevarse `$cantidad` (MOD-15).
     *
     * Con variantes esto **no se cobra**: el precio de la ficha es el resumen de
     * la más barata (MOD-5) y quien cobra es `ProductVariant::precioPara()`.
     */
    public function precioPara(int $cantidad): float
    {
        return \App\Support\PreciosPorCantidad::precioPara($this->precioVisible(), $this->price_tiers, $cantidad);
    }

    // Accessor útil para el frontend
    public function getIsAvailableAttribute(): bool
    {
        return $this->stock > 0;
    }
}
