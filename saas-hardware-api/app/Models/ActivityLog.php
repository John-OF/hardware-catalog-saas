<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Quién hizo qué: el operador del SaaS (INF-2) y el equipo de cada tienda
 * (INF-3), en la misma tabla y separados por `origen`.
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
 * La contrapartida: la bitácora del panel de tienda (`INF-3`) filtra por
 * `tenant_id` a mano, con `scopeDeTienda()`, que es el único sitio donde debe
 * escribirse ese `where`.
 *
 * **Dos orígenes.** `plataforma` es lo que hace el operador (y el sistema en su
 * nombre, como cerrar pruebas vencidas); `tienda`, lo que hace el equipo dentro
 * de su panel. Cada bitácora lee solo el suyo: la del operador no se inunda de
 * cambios de precio y la de la tienda no enseña las notas del operador.
 *
 * Solo se escribe y se lee; no hay edición ni borrado.
 *
 * **Qué pasa si anotar falla, según el origen.** `registrar()` deja subir el
 * error: en plataforma la línea es parte de la acción (la sesión de soporte no
 * se emite sin rastro). Desde el panel de tienda se escribe con
 * `App\Support\Bitacora`, que se traga el error y lo registra: ahí el cambio ya
 * está guardado y un 500 haría creer al dueño que no se aplicó.
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
    public const TIENDA_PRUEBA_VENCIDA = 'tenant.prueba_vencida';

    /** De dónde sale la línea (ver la cabecera). */
    public const ORIGEN_PLATAFORMA = 'plataforma';
    public const ORIGEN_TIENDA = 'tienda';

    /**
     * Acciones del panel de tienda (INF-3). Lo que va antes del punto es el
     * área, que es por lo que filtra la pantalla de Actividad.
     */
    public const PRODUCTO_CREADO = 'producto.creado';
    public const PRODUCTO_EDITADO = 'producto.editado';
    public const PRODUCTO_DUPLICADO = 'producto.duplicado';
    public const PRODUCTO_BORRADO = 'producto.borrado';
    public const PRODUCTO_IMPORTADOS = 'producto.importados';
    public const PRODUCTO_LOTE = 'producto.lote';
    public const PEDIDO_MOSTRADOR = 'pedido.mostrador';
    public const PEDIDO_ESTADO = 'pedido.estado';
    public const PEDIDO_BORRADO = 'pedido.borrado';
    public const CATEGORIA_CREADA = 'categoria.creada';
    public const CATEGORIA_EDITADA = 'categoria.editada';
    public const CATEGORIA_BORRADA = 'categoria.borrada';
    public const PAGINA_CREADA = 'pagina.creada';
    public const PAGINA_EDITADA = 'pagina.editada';
    public const PAGINA_BORRADA = 'pagina.borrada';
    public const RESENA_MODERADA = 'resena.moderada';
    public const RESENA_BORRADA = 'resena.borrada';
    public const ESPERA_MARCADA = 'espera.marcada';
    public const ESPERA_BORRADA = 'espera.borrada';
    public const EQUIPO_INVITADO = 'equipo.invitado';
    public const EQUIPO_EDITADO = 'equipo.editado';
    public const EQUIPO_BORRADO = 'equipo.borrado';
    public const EQUIPO_REINVITADO = 'equipo.reinvitado';
    public const CONFIGURACION_EDITADA = 'configuracion.editada';
    public const CONFIGURACION_DOMINIO = 'configuracion.dominio_verificado';

    /** Las áreas de la bitácora de tienda, en el orden en que las ofrece el filtro. */
    public const AREAS_DE_TIENDA = ['producto', 'pedido', 'categoria', 'pagina', 'resena', 'espera', 'equipo', 'configuracion'];

    protected $fillable = [
        'origen', 'tenant_id', 'actor_id', 'actor_email', 'actor_role',
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
        string $origen = self::ORIGEN_PLATAFORMA,
    ): self {
        if ($tenant && $origen === self::ORIGEN_PLATAFORMA) {
            // Snapshot: `tenant_id` se pone a null si la tienda se borra, así
            // que sin esto la línea quedaría hablando de nadie. Solo en las del
            // operador: las de la tienda se leen desde dentro de ella, y si la
            // tienda desaparece no queda nadie que las lea.
            $context += ['tienda' => $tenant->name, 'slug' => $tenant->slug];
        }

        return static::create([
            'origen'      => $origen,
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

    public function scopeDePlataforma(Builder $query): Builder
    {
        return $query->where('origen', self::ORIGEN_PLATAFORMA);
    }

    public function scopeDelPanelDeTienda(Builder $query): Builder
    {
        return $query->where('origen', self::ORIGEN_TIENDA);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * La cuenta que hizo la acción, si sigue existiendo. Si se borró, la línea
     * sigue diciendo quién fue por `actor_email`.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
