<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;

class Tenant extends Model implements IsTenant
{
    use HasUuids, ImplementsTenant, UsesMultitenancyConfig;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'slug', 'name', 'logo_url', 'primary_color', 'theme',
        'whatsapp_number', 'plan', 'is_active', 'is_published', 'custom_domain', 'currency',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'is_published' => 'boolean',
        'theme'        => 'array',
    ];

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
