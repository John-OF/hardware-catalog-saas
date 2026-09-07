<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasUuids, BelongsToTenant;

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
    ];

    protected $casts = [
        'total'  => 'decimal:2',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
