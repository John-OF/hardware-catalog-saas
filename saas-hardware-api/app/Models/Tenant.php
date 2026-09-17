<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;

class Tenant extends Model implements IsTenant
{
    use HasUuids, ImplementsTenant, UsesMultitenancyConfig;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected $fillable = [
        'slug', 'name', 'logo_url', 'primary_color', 'theme',
        'whatsapp_number', 'plan', 'is_active', 'is_published', 'custom_domain', 'currency',
        'timezone', 'payment_methods', 'delivery_enabled', 'delivery_cost',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'theme' => 'array',
        'payment_methods' => 'array',
        'delivery_enabled' => 'boolean',
        'delivery_cost' => 'decimal:2',
        'custom_domain_requested_at' => 'datetime',
        'custom_domain_verified_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    /**
     * Los métodos de pago que la tienda puede enseñar al comprador (MOD-3).
     *
     * Cuatro, fijos: no es una pasarela (eso es `SAAS-3`, y cobra a la tienda,
     * no al comprador de la tienda), es solo dónde pone el dueño su Yape, su
     * cuenta o si acepta efectivo contra entrega. Los campos de cada uno están
     * en `TenantController::update()` (la validación) y en `PaymentMethodsForm`
     * del frontend.
     */
    /**
     * La zona horaria de la tienda, siempre utilizable (MOD-13).
     *
     * Nunca devuelve null ni vacio: una tienda de antes de esta columna, o una
     * fila a la que alguien le metio `''` a mano, cae en UTC, que es como se
     * comportaba el sistema entero antes de MOD-13. Lo que NO se hace aqui es
     * adivinar la zona por la moneda: una tienda peruana puede cobrar en
     * dolares, y dos tiendas con la misma moneda pueden estar en husos
     * distintos.
     *
     * Todo se sigue guardando en UTC. Esto solo decide como se LEE: en que dia
     * cae una venta al agrupar los reportes y con que hora se pinta una fecha.
     */
    public function zonaHoraria(): string
    {
        return $this->timezone ?: 'UTC';
    }

    public const METODOS_DE_PAGO = ['yape', 'plin', 'transferencia', 'efectivo'];

    /**
     * Los métodos que la tienda activó, con sus datos. Nunca los que están
     * apagados: mostrar un Yape a medio llenar es peor que no mostrar Yape.
     *
     * @return array<string, array<string, mixed>>
     */
    public function metodosDePagoActivos(): array
    {
        return collect($this->payment_methods ?? [])
            ->only(self::METODOS_DE_PAGO)
            ->filter(fn ($datos) => is_array($datos) && ($datos['enabled'] ?? false))
            ->all();
    }

    /**
     * Cuánto dura la prueba de una tienda nueva (FUN-16).
     *
     * Sólo la usa `AuthController::register()`, al crear la tienda -de ahí que
     * viva aquí y no en `config/plans.php`: no es un límite de ningún plan, es
     * cuánto tiempo se le da a alguien para decidir antes de tener que elegir
     * uno.
     */
    public const DIAS_DE_PRUEBA = 7;

    /**
     * Si esta tienda sigue dentro de su período de prueba (FUN-16).
     *
     * `trial_ends_at` nula significa que nunca tuvo prueba (una tienda vieja,
     * de antes de este cambio) o que ya se le asignó un plan de verdad -ver
     * `PlatformController::updateTenant()`, que la vacía al elegir plan-. Una
     * prueba VENCIDA (la fecha ya pasó) tampoco cuenta: para entonces el
     * trabajo programado ya debería haber suspendido la tienda, y aunque no
     * hubiera corrido todavía, tratarla como "sigue en prueba" le regalaría
     * tiempo de más sólo por un retraso del cron.
     */
    public function enPrueba(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * Las tiendas que se ven desde fuera (FUN-5).
     *
     * Este scope es la respuesta unica a "¿esta tienda es publica?", que antes
     * estaba escrita a mano como `where('is_active', true)` en seis sitios: el
     * middleware de slug, `resolveDomain` y las cuatro rutas de crawler de
     * `web.php`. Con dos condiciones en vez de una, repartirlas otra vez era
     * garantizar que alguna se quedara con la mitad de la regla; de hecho el
     * sintoma seria el peor posible, una tienda sin verificar cuya vista previa
     * de WhatsApp si funciona.
     *
     * Los `where('is_active', true)` que quedan dentro de `PublicCatalogController`
     * NO se han tocado: corren despues del middleware, asi que son la segunda
     * barrera de AUD-4 y su redundancia es el objetivo.
     */
    public function scopePublica(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_published', true);
    }

    /**
     * Dominio propio VERIFICADO (FUN-6).
     *
     * No basta con que `custom_domain` tenga un valor: hasta que se demuestre
     * con el registro TXT, ese valor es solo lo que alguien escribió, y podría
     * ser el dominio de otra persona. Centralizado por el mismo motivo que
     * `scopePublica()`: es la unica pregunta que importa antes de resolver una
     * tienda por su dominio, y repetirla a mano en cada sitio es la forma
     * segura de que un dia se olvide en uno.
     */
    public function scopeConDominioVerificado(Builder $query): Builder
    {
        return $query->whereNotNull('custom_domain_verified_at');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** La usa el panel de plataforma para contar pedidos por tienda (SAAS-4). */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
