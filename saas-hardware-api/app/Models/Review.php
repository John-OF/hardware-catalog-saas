<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class Review extends Model
{
    use HasUuids, BelongsToTenant;

    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'tenant_id',
        'product_id',
        'user_id',
        'visitor_id',
        'customer_name',
        'customer_email',
        'rating',
        'comment',
        'verified_purchase',
        'is_approved',
    ];

    /**
     * ACC-1: lo que identifica a quien reseñó NO sale en ninguna respuesta por
     * defecto. La ficha pública lista las reseñas aprobadas sin token, así que
     * un campo que se serializara solo acabaría publicado. Falla en cerrado, como
     * el costo (MOD-6): una columna nueva de datos personales tampoco se publica
     * sola. El panel destapa a mano `VISIBLES_EN_EL_PANEL` (`ReviewController`);
     * el `visitor_id` no lo necesita nadie fuera del servidor.
     */
    protected $hidden = ['customer_email', 'visitor_id', 'user_id'];

    /**
     * Lo que el panel sí ve: el correo, y el `user_id` para distinguir al moderar
     * una compra verificada con cuenta de una que sólo coincidió por teléfono
     * (ACC-2).
     */
    public const VISIBLES_EN_EL_PANEL = ['customer_email', 'user_id'];

    protected $casts = [
        'rating'            => 'integer',
        'verified_purchase' => 'boolean',
        'is_approved'       => 'boolean',
    ];

    protected static function booted()
    {
        // Incrementar versión de caché al guardar/eliminar reseñas
        static::saved(function ($review) {
            $tenant = $review->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });

        static::deleted(function ($review) {
            $tenant = $review->tenant;
            if ($tenant) {
                \Illuminate\Support\Facades\Cache::increment("tenant:{$tenant->slug}:cache_version");
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
