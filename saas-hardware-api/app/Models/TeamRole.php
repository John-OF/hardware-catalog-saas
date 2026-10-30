<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un rol del equipo de una tienda: un nombre y una lista de permisos (MOD-21).
 *
 * Lo tienen los usuarios `staff`; un `admin` no tiene rol porque lo puede todo.
 * Qué significa cada permiso está en `config/permisos.php` y quién lo
 * comprueba, en `App\Support\Permisos`.
 *
 * Editar un rol cambia lo que pueden todos los que lo tienen, que es el motivo
 * de que existan los roles en vez de permisos sueltos por persona: el dueño
 * piensa en "el de caja" o "el de compras", no en quince casillas por cabeza.
 */
class TeamRole extends Model
{
    use BelongsToTenant, HasUuids;

    /** El que trae toda tienda nueva y el que cae si no se elige ninguno. */
    public const NOMBRE_DE_FABRICA = 'Colaborador';

    public const DESCRIPCION_DE_FABRICA = 'El día a día: pedidos, productos, reseñas y lista de espera.';

    protected $fillable = ['name', 'description', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function miembros(): HasMany
    {
        return $this->hasMany(User::class, 'team_role_id');
    }

    /**
     * Lo que vale de verdad de este rol: lo guardado, sin claves que ya no
     * existen y sin permisos a los que les falte lo que requieren.
     *
     * @return list<string>
     */
    public function permisosEfectivos(): array
    {
        return Permisos::efectivos((array) $this->permissions);
    }

    /**
     * El rol «Colaborador» con lo que podía el `staff` fijo de FUN-4.
     *
     * Con `tenant_id` a mano y `withoutTenant()` en lo que se consulta: se llama
     * al crear una tienda, cuando la tienda actual -si la hay- es otra o
     * ninguna.
     */
    public static function crearDeFabrica(string $tenantId): self
    {
        $rol = new self([
            'name'        => self::NOMBRE_DE_FABRICA,
            'description' => self::DESCRIPCION_DE_FABRICA,
            'permissions' => Permisos::normalizar(Permisos::delColaborador()),
        ]);
        $rol->tenant_id = $tenantId;
        $rol->save();

        return $rol;
    }

    /**
     * El rol que recibe un `staff` cuando nadie eligió ninguno: el más antiguo
     * de la tienda (el de fábrica, salvo que lo hayan borrado). Si la tienda no
     * tiene ninguno, se le vuelve a crear el de fábrica.
     *
     * No lo usa la pantalla de equipo, que siempre manda el rol elegido; es
     * para los caminos que crean staff sin preguntar (un cambio de rol por la
     * API sin `team_role_id`, los tests).
     */
    public static function porDefectoDe(string $tenantId): self
    {
        return self::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first()
            ?? self::crearDeFabrica($tenantId);
    }
}
