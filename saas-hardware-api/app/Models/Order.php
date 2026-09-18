<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Order extends Model
{
    /**
     * La papelera de MOD-8. Un pedido borrado deja de contar como venta en el
     * resumen, en los reportes y en el CSV sin tocar ninguna de esas consultas,
     * porque todas van por Eloquent — **salvo una**: `Reportes::lineasDelRango()`
     * hace un join con `DB::table('orders')` para sumar las lineas, y ahi el
     * filtro va a mano. Es el tipo de agujero que no da error: sumaria ventas
     * borradas y cuadraria consigo mismo.
     */
    use BelongsToTenant, HasUuids, SoftDeletes;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    // `number` NO va aquí a propósito (FUN-3): lo pone el correlativo de la
    // tienda, no quien crea el pedido. Fuera de `$fillable`, ni un `create()`
    // descuidado ni un campo de más en la petición pueden fijarlo.
    protected $fillable = [
        'tenant_id', 'user_id', 'customer_name', 'customer_phone',
        'customer_email', 'customer_note', 'status', 'total',
        // MOD-1: 'pickup'/'delivery'/null (null = venta de mostrador, o un
        // pedido de antes de este cambio). `delivery_cost` es el snapshot de
        // lo que costaba el envío ESE día; ver la migración.
        'delivery_method', 'delivery_cost',
        // MOD-2: la foto del impuesto del día de la venta. `null` en `tax_rate`
        // es "se vendió sin impuesto", que no es lo mismo que "con el 0%".
        'tax_name', 'tax_rate', 'tax_included', 'tax_amount',
        // MOD-4: el cupón usado y lo que descontó, también como foto del día de
        // la venta. `coupon_code` sobrevive a que el cupón se borre.
        'items_subtotal', 'coupon_id', 'coupon_code', 'discount_amount',
    ];

    /**
     * MOD-2: el desglose se calcula, no se guarda dos veces. Va siempre, también
     * en los pedidos sin impuesto, donde es igual al total.
     */
    protected $appends = ['base_imponible'];

    protected $casts = [
        'total' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_included' => 'boolean',
        'tax_amount' => 'decimal:2',
        'items_subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'number' => 'integer',
    ];

    protected static function booted(): void
    {
        // El número se asigna aquí y no en los controladores porque hoy hay dos
        // caminos que crean pedidos —el checkout público y la venta de mostrador—
        // y mañana puede haber un tercero. Es la lección que dejó escrita 7.7a:
        // el tope del plan se colaba justo por `duplicate()` e `import()`, que
        // creaban productos sin pasar por `store()`.
        //
        // Va después del hook de `BelongsToTenant` (los traits arrancan antes que
        // `booted()`), así que `tenant_id` ya está puesto cuando llega aquí.
        static::creating(function (Order $order) {
            if ($order->number === null) {
                $order->number = static::siguienteNumeroDe($order->tenant_id);
            }
        });
    }

    /**
     * Reserva el siguiente correlativo de la tienda (FUN-3).
     *
     * El bloqueo de la fila del tenant es lo que hace que dos pedidos simultáneos
     * no se lleven el mismo número. Se hace dentro de una transacción propia: si
     * ya hay una abierta —que es el caso de los dos controladores— queda como
     * savepoint y el bloqueo se mantiene hasta el commit de fuera, que es
     * justamente lo que se quiere; y si no la hay, la crea para no depender de
     * quién llame.
     *
     * Se usa el query builder y no Eloquent para no tocar el `updated_at` de la
     * tienda: recibir un pedido no es editar la tienda.
     */
    private static function siguienteNumeroDe(?string $tenantId): int
    {
        if (empty($tenantId)) {
            throw new \LogicException('Un pedido sin tienda no puede numerarse.');
        }

        return DB::transaction(function () use ($tenantId) {
            $siguiente = (int) DB::table('tenants')
                ->where('id', $tenantId)
                ->lockForUpdate()
                ->value('next_order_number');

            // 0 significa que la tienda no existe (o que la columna se quedó a
            // nulos por una migración a medias). Numerar como #0 sería peor que
            // fallar: el pedido entraría y nadie lo notaría hasta el segundo,
            // que chocaría contra el índice único.
            if ($siguiente < 1) {
                throw new \LogicException("La tienda {$tenantId} no tiene contador de pedidos.");
            }

            DB::table('tenants')
                ->where('id', $tenantId)
                ->update(['next_order_number' => $siguiente + 1]);

            return $siguiente;
        });
    }

    /**
     * Descuenta o devuelve el stock de las líneas de este pedido.
     *
     * Vive en el modelo y no en `OrderController` porque desde `MOD-8` lo llaman
     * dos sitios: el controlador —al crear, al cambiar de estado y al borrar— y
     * la papelera, al restaurar un pedido atendido que ya había devuelto su
     * stock. Si cada uno se escribiera el suyo, restaurar movería el stock con
     * reglas distintas de las que lo movieron al vender.
     *
     * Las líneas guardan un snapshot del producto, así que `product_id` puede
     * ser null si el artículo se borró del catálogo después de venderse: en ese
     * caso no hay stock que mover y la línea se salta.
     *
     * MOD-5: una línea de una variante mueve el stock de ESA variante y después
     * recalcula el resumen del producto. Se salta —sin mover nada— en dos casos
     * en los que no hay dónde devolverlo con sentido: la variante se borró
     * (queda su nombre pero no su id), o la línea es de antes de que el producto
     * tuviera variantes (el stock del producto ya es solo un resumen, y el
     * próximo cálculo pisaría lo que se le sumara a mano).
     *
     * MOD-8: el producto se busca **con la papelera incluida**. Un producto
     * borrado sigue teniendo su número de stock, y saltárselo dejaría que borrar
     * un producto y luego cancelar una venta suya perdiera unidades en silencio
     * — y al restaurarlo, el número ya estaría mal.
     *
     * Con el modelo (`increment()` de una instancia) y no con una consulta suelta,
     * para que salten los eventos: el aviso de "ya llegó" al devolver stock de un
     * pedido cancelado.
     */
    public function moverStock(bool $decrement): void
    {
        foreach ($this->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            if ($item->variant_id) {
                $variante = ProductVariant::find($item->variant_id);

                if (! $variante) {
                    continue;
                }

                $decrement
                    ? $variante->decrement('stock', $item->quantity)
                    : $variante->increment('stock', $item->quantity);

                $variante->product()->withTrashed()->first()?->sincronizarResumenDeVariantes();

                continue;
            }

            if ($item->variant_name !== null) {
                continue;
            }

            $producto = Product::withTrashed()->withCount('variants')->find($item->product_id);

            if (! $producto || $producto->variants_count > 0) {
                continue;
            }

            $decrement
                ? $producto->decrement('stock', $item->quantity)
                : $producto->increment('stock', $item->quantity);
        }
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Lo que costo comprar lo que se vendio en este pedido (MOD-6).
     *
     * Sale de `unit_cost`, el costo copiado linea a linea el dia de la venta, y
     * no del costo que tenga hoy el producto: asi la utilidad de un pedido de
     * marzo no cambia porque en abril suba el proveedor.
     *
     * `null` cuando NINGUNA linea tiene costo -pedidos anteriores a este cambio,
     * o productos a los que nadie se lo ha puesto-. No es cero: cero seria decir
     * que la tienda gano el precio entero.
     */
    public function getCostoTotalAttribute(): ?float
    {
        $conCosto = $this->items->whereNotNull('unit_cost');

        if ($conCosto->isEmpty()) {
            return null;
        }

        return round($conCosto->sum(fn (OrderItem $i) => (float) $i->unit_cost * $i->quantity), 2);
    }

    /**
     * Precio de venta menos costo, solo de las lineas que tienen costo.
     *
     * **El envio no cuenta.** `delivery_cost` es lo que se le cobra al cliente
     * por llevarselo (MOD-1), no lo que la tienda gana con el producto, y lo que
     * le cuesta a ella el reparto no lo sabe el sistema. Meterlo aqui inflaria
     * la utilidad con dinero que se va en gasolina.
     *
     * **El impuesto tampoco cuenta** (MOD-2). Si los precios de la tienda lo
     * llevan dentro, parte de lo cobrado por cada línea es del fisco y no de la
     * tienda: medir el margen contra el precio con impuesto lo infla en el
     * porcentaje entero, y ese número es el que el dueño usa para decidir
     * precios. Cuando el impuesto se suma al final, las líneas ya son netas y
     * aquí no hay nada que descontar.
     *
     * Si solo algunas lineas tienen costo, esto es una utilidad PARCIAL, y
     * `lineas_sin_costo` es lo que avisa de ello.
     */
    public function getUtilidadAttribute(): ?float
    {
        $conCosto = $this->items->whereNotNull('unit_cost');

        if ($conCosto->isEmpty()) {
            return null;
        }

        $venta = $conCosto->sum(fn (OrderItem $i) => (float) $i->subtotal);

        // MOD-4: la parte del cupón que le tocó a estas líneas, en proporción.
        // El descuento vive en el pedido y no repartido por línea —`subtotal` es
        // el snapshot de precio × cantidad—, así que se reparte aquí. Sin esto,
        // un cupón del 25% no se notaría en la utilidad y el dueño creería estar
        // ganando lo que no cobró.
        if ((float) $this->discount_amount > 0 && (float) $this->items_subtotal > 0) {
            $venta *= 1 - ((float) $this->discount_amount / (float) $this->items_subtotal);
        }

        // MOD-2: y de lo que queda, lo que es del fisco. En este orden: el
        // descuento se aplicó sobre precios que ya llevaban el impuesto dentro.
        $venta -= \App\Support\Impuesto::dentroDe($this, $venta);

        return round($venta - (float) $this->costo_total, 2);
    }

    /**
     * Lo cobrado sin el impuesto: lo que la cotización llama "operación gravada".
     *
     * Sale de `total` y no de la suma de las líneas a propósito: `total` es lo
     * que paga el cliente en los dos modos de cobro, así que esta resta vale
     * igual para los dos y para los pedidos de antes de MOD-2, donde
     * `tax_amount` es null y esto devuelve el total entero.
     */
    public function getBaseImponibleAttribute(): float
    {
        return round((float) $this->total - (float) ($this->tax_amount ?? 0), 2);
    }

    /**
     * Cuantas lineas del pedido se vendieron sin saber cuanto costaban.
     */
    public function getLineasSinCostoAttribute(): int
    {
        return $this->items->whereNull('unit_cost')->count();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
