<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un cobro del plan de una tienda, apuntado por el operador (INF-23).
 *
 * Lo escribe solo `App\Support\Membresia::registrarPago()`, que es también quien
 * mueve la fecha de fin del plan: las dos cosas van juntas o no van.
 *
 * **No usa `BelongsToTenant`**, por el mismo motivo que `ActivityLog`: se lee
 * desde el panel de plataforma, donde no hay tienda resuelta, y con el trait cada
 * lectura tendría que acordarse de `withoutTenant()` o saldría vacía en silencio.
 * Filtrar por tienda es `where('tenant_id')`, a mano.
 *
 * **No se edita ni se borra**: es el registro de un ingreso. Por eso no tiene
 * ruta de edición ni de borrado, y la clave hacia `tenants` es `restrictOnDelete`.
 *
 * Cuando llegue Paddle (7.7b), el webhook apuntará aquí el mismo pago, con
 * `method = 'paddle'`: cambia quién lo registra, no qué se guarda.
 */
class TenantPayment extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Por dónde llegó el dinero, con su nombre en pantalla. «Cortesía» es dar
     * tiempo sin cobrar (monto 0): queda en el historial igual que un pago, en
     * vez de un plan sin fecha que nadie recuerda haber regalado.
     */
    public const MEDIOS = [
        'transferencia' => 'Transferencia',
        'deposito'      => 'Depósito',
        'efectivo'      => 'Efectivo',
        'tarjeta'       => 'Tarjeta',
        'paypal'        => 'PayPal',
        'cortesia'      => 'Cortesía (sin cobro)',
        'otro'          => 'Otro',
    ];

    /** Tope de meses de un solo pago: dos años. Más es casi seguro un error al escribir. */
    public const MESES_MAXIMOS = 24;

    protected $fillable = [
        'tenant_id', 'plan', 'months', 'amount_usd', 'method', 'reference', 'notes',
        'paid_on', 'starts_at', 'ends_at', 'registered_by', 'registered_by_email',
    ];

    protected $casts = [
        'months'     => 'integer',
        'amount_usd' => 'decimal:2',
        'paid_on'    => 'date:Y-m-d',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
