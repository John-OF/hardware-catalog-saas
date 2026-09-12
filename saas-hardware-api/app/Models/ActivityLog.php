<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Lo que hizo el operador del SaaS, y quién lo hizo (INF-2).
 *
 * **No usa `BelongsToTenant` a propósito**, y es la segunda excepción del
 * proyecto después de `User`. El motivo es el mismo por el que el trait existe:
 * `AUD-4` hace que sin tienda resuelta una consulta no devuelva nada, y esta
 * tabla se lee justo desde donde no hay tienda resuelta —el panel de plataforma,
 * que mira por encima de todas—. Con el trait, todas y cada una de las lecturas
 * de aquí tendrían que acordarse de `withoutTenant()`, y la que se olvidara
 * saldría vacía en silencio: una bitácora que no enseña nada es peor que no
 * tenerla, porque parece decir que no pasó nada.
 *
 * La contrapartida está anotada: el día que `INF-3` pinte la bitácora **dentro**
 * del panel de una tienda, ese listado tiene que filtrar por `tenant_id` a mano
 * — para eso está `scopeDeTienda()`, que es el único sitio donde debe escribirse
 * ese `where`.
 *
 * Solo se escribe y se lee; no hay edición ni borrado. Registrar algo nunca debe
 * poder tumbar la acción que se estaba registrando (ver `registrar()`).
 */
class ActivityLog extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    /** Acciones del operador de plataforma. */
    public const TIENDA_SUSPENDIDA = 'tenant.suspendida';
    public const TIENDA_REACTIVADA = 'tenant.reactivada';
    public const TIENDA_PLAN = 'tenant.plan';
    public const TIENDA_RESET = 'tenant.reset_password';
    public const TIENDA_SOPORTE = 'tenant.soporte';
    public const TIENDA_RESCATE_ADMIN = 'tenant.rescate_admin';

    protected $fillable = [
        'tenant_id', 'actor_id', 'actor_email', 'actor_role',
        'action', 'description', 'context', 'ip',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    /**
     * Anotar una acción.
     *
     * El actor se copia en columnas propias además de la clave foránea: si esa
     * cuenta se borra, el rastro tiene que seguir diciendo quién fue.
     *
     * `context` es para el detalle que da sentido a la línea (el plan de antes y
     * el de después, por ejemplo) y para el snapshot de la tienda, que sobrevive
     * a que la tienda desaparezca.
     */
    public static function registrar(
        string $action,
        string $description,
        ?Tenant $tenant = null,
        ?User $actor = null,
        array $context = [],
        ?Request $request = null,
    ): self {
        if ($tenant) {
            // Snapshot: `tenant_id` se pone a null si la tienda se borra, así
            // que sin esto la línea quedaría hablando de nadie.
            $context += ['tienda' => $tenant->name, 'slug' => $tenant->slug];
        }

        return static::create([
            'tenant_id'   => $tenant?->id,
            'actor_id'    => $actor?->id,
            'actor_email' => $actor?->email,
            'actor_role'  => $actor?->role,
            'action'      => $action,
            'description' => $description,
            'context'     => $context ?: null,
            'ip'          => $request?->ip(),
        ]);
    }

    /**
     * El único sitio donde se filtra la bitácora por tienda.
     *
     * Existe porque este modelo no lleva el global scope: ver la cabecera.
     */
    public function scopeDeTienda(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
