<?php

namespace App\Models;

use App\Enums\ComponentType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class Category extends Model
{
    use HasUuids, BelongsToTenant;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = ['name', 'icon', 'component_type', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'      => 'boolean',
        'component_type' => ComponentType::class,
    ];

    protected static function booted()
    {
        // FUN-8: si nadie dice qué pieza vende la categoría, se deduce del
        // nombre. Va aquí y no en el controlador porque hay más de una puerta
        // por la que nace una categoría —el panel y el import CSV, que las crea
        // sobre la marcha a partir de una columna del archivo— y la que quede
        // sin tipo se cae del armador en silencio, que es justo el fallo que
        // FUN-8 viene a cerrar. Lo deducido es un punto de partida: el dueño lo
        // corrige desde el panel.
        static::creating(function ($category) {
            if ($category->component_type === null) {
                $category->component_type = ComponentType::inferirDeNombre($category->name);
            }

            // El icono no es un dato aparte del tipo, es su dibujo. Sólo se
            // rellena cuando no se pidió uno concreto (el CSV manda 'folder').
            if ($category->icon === null || $category->icon === 'folder') {
                $category->icon = $category->component_type->icono();
            }
        });

        // Incrementar versión de caché al modificar categorías para invalidar la caché pública
        static::saved(function ($category) {
            $tenant = $category->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");

                // La lista pública de categorías tiene clave propia y sin
                // versión, así que el increment de arriba no la toca: sin esto,
                // marcar una categoría como "Procesadores" no llegaba al
                // armador hasta cinco minutos después y parecía no funcionar.
                \Illuminate\Support\Facades\Cache::forget("tenant:{$tenant->slug}:public_categories");
            }
        });

        static::deleted(function ($category) {
            $tenant = $category->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
                \Illuminate\Support\Facades\Cache::forget("tenant:{$tenant->slug}:public_categories");
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
