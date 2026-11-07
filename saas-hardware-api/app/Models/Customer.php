<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Una persona del directorio de clientes de la tienda (MOD-24): quien le compró,
 * tenga cuenta o no, y quien abrió cuenta aunque no haya comprado.
 *
 * No es la cuenta (`User` con rol `customer`): la cuenta la abre y la cambia el
 * propio cliente, y esto es lo que la tienda sabe y anota de él. Se llena solo
 * con cada pedido; quién se junta con quién lo decide `App\Support\Directorio`.
 */
class Customer extends Model
{
    use BelongsToTenant, HasUuids;

    /** Cuántas etiquetas puede llevar una persona. */
    public const MAXIMO_DE_ETIQUETAS = 10;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected $fillable = ['user_id', 'name', 'phone', 'phone_key', 'email', 'email_key', 'notes', 'tags'];

    // Las claves son para buscar, no para enseñar.
    protected $hidden = ['phone_key', 'email_key'];

    protected $casts = [
        'tags' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
