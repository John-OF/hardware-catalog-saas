<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ata un modelo a su tienda y filtra por ella en TODA consulta.
 *
 * AUD-4: antes, el global scope solo filtraba `if (app()->bound('currentTenant'))`.
 * Sin tenant resuelto no filtraba nada, o sea que la consulta veia todas las
 * tiendas. Eso es fallar EN ABIERTO: el dia que alguien anadiera un endpoint
 * publico y olvidara un `where('tenant_id')`, el resultado no habria sido un
 * error ruidoso sino una fuga silenciosa de datos de todas las tiendas.
 *
 * Ahora falla en cerrado: sin tenant resuelto la consulta no devuelve nada. Un
 * olvido se nota enseguida —una pantalla vacia— en vez de pasar desapercibido
 * durante meses. Quien de verdad necesita mirar por encima de las tiendas lo
 * pide explicitamente con `withoutTenant()`, y entonces se ve en el codigo.
 *
 * Para que esto no rompiera el catalogo publico hizo falta la otra mitad del
 * cambio: las rutas publicas ahora resuelven su tienda desde el slug de la URL
 * con `InitializeTenantBySlug`. Antes no la resolvian y filtraban a mano en cada
 * consulta —bien, se reviso una a una—, pero sin red debajo. Con el tenant
 * resuelto el filtro a mano pasa a ser redundante, que es justo lo que se busca:
 * que sobre, no que haga falta.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant()
    {
        static::creating(function ($model) {
            if (empty($model->tenant_id) && app()->bound('currentTenant')) {
                $model->tenant_id = app('currentTenant')->id;
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app()->bound('currentTenant')) {
                $builder->where('tenant_id', app('currentTenant')->id);

                return;
            }

            if (static::veTodoSinTenant()) {
                return;
            }

            // Sin tienda resuelta y sin `withoutTenant()`: no se devuelve nada.
            $builder->whereRaw('1 = 0');
        });
    }

    /**
     * Consulta que mira por encima de las tiendas, a sabiendas.
     *
     * Para el panel de plataforma, los comandos de consola y los tests que
     * comprueban lo que se guardo de verdad. El nombre es aposta explicito: si
     * aparece en un controlador de tienda, es que algo esta mal.
     */
    public function scopeWithoutTenant(Builder $query): Builder
    {
        return $query->withoutGlobalScope('tenant');
    }

    /**
     * Si este modelo puede consultarse sin tienda resuelta.
     *
     * Falso para todos menos `User`, que lo sobrescribe con su porque.
     */
    protected static function veTodoSinTenant(): bool
    {
        return false;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
