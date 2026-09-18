<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Un código de descuento de la tienda (`MOD-4`).
 *
 * Descuenta sobre el subtotal de los **productos**, nunca sobre el envío: lo que
 * la tienda le paga al repartidor no baja porque el comprador tenga un cupón, y
 * un 25% que también se llevara un cuarto del reparto sería un descuento mayor
 * del que el dueño cree estar dando. El impuesto se calcula después, sobre lo que
 * queda (`App\Support\Impuesto`), porque descontar sobre el bruto regalaría un
 * impuesto que la tienda paga igual.
 */
class Coupon extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * Cuántos cupones puede tener una tienda.
     *
     * No es un tope de plan —el cupón no es una función que se venda, es una
     * herramienta de venta— sino un techo técnico, como
     * `ProductVariant::MAXIMO_POR_PRODUCTO`: cien códigos vivos es más campaña de
     * la que nadie lleva a la vez, y sin ningún tope la tabla queda abierta a que
     * un script la llene.
     */
    public const MAXIMO_POR_TIENDA = 100;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected $fillable = [
        'code', 'type', 'value', 'min_purchase', 'max_uses',
        'starts_at', 'ends_at', 'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * El código se guarda y se busca SIEMPRE en mayúsculas y sin espacios.
     *
     * El comprador lo copia de una historia de Instagram y lo pega con un espacio
     * detrás, o el teclado del móvil le pone la primera letra en mayúscula. Nada
     * de eso es un código distinto, y rechazarlo es perder una venta por un
     * detalle que él no puede ver.
     */
    public function setCodeAttribute(?string $valor): void
    {
        $this->attributes['code'] = mb_strtoupper(trim((string) $valor));
    }

    /** Lo mismo, del lado de la consulta: `Coupon::porCodigo('verano25')`. */
    public function scopePorCodigo($query, ?string $codigo)
    {
        return $query->where('code', mb_strtoupper(trim((string) $codigo)));
    }

    /**
     * Por qué este cupón no se puede usar ahora mismo, o `null` si sí se puede.
     *
     * Devuelve el motivo y no un booleano porque el comprador tiene que poder
     * leer qué le pasa: "este código venció" y "te faltan S/ 50 para usarlo" le
     * dicen qué hacer, y un "código inválido" genérico le deja pensando que lo
     * escribió mal.
     *
     * **No distingue "no existe" de "no es tuyo"**: eso lo resuelve el scope de
     * tienda, y el mensaje de "no existe" lo pone quien llama.
     */
    public function motivoParaRechazar(float $subtotal, ?Carbon $ahora = null): ?string
    {
        $ahora ??= now();

        if (! $this->is_active) {
            return 'Ese código ya no está disponible.';
        }

        if ($this->starts_at && $ahora->lt($this->starts_at)) {
            return 'Ese código todavía no está activo.';
        }

        if ($this->ends_at && $ahora->gt($this->ends_at)) {
            return 'Ese código ya venció.';
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'Ese código ya se agotó.';
        }

        if ($this->min_purchase !== null && $subtotal < (float) $this->min_purchase) {
            return 'Ese código pide una compra mínima mayor.';
        }

        return null;
    }

    /**
     * Cuánto descuenta sobre un subtotal de productos.
     *
     * **Nunca más que el subtotal.** Un cupón de monto fijo de 100 sobre una
     * compra de 60 descuenta 60, no 100: si no, el total se iría a negativo y la
     * tienda acabaría debiéndole dinero al comprador.
     */
    public function descuentoSobre(float $subtotal): float
    {
        $bruto = $this->type === 'percent'
            ? $subtotal * (float) $this->value / 100
            : (float) $this->value;

        return round(min($bruto, $subtotal), 2);
    }

    /** Cómo se lee el cupón en el panel y en el carrito: "25%" o "S/ 50". */
    public function getEtiquetaAttribute(): string
    {
        return $this->type === 'percent'
            ? rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.').'%'
            : \App\Support\Money::format($this->value, $this->tenant?->currency);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
