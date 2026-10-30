<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\TeamRole;
use App\Models\User;
use App\Support\Bitacora;
use App\Support\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Los roles del equipo de una tienda y sus permisos (MOD-21).
 *
 * Solo admin, como el equipo (`routes/api.php`): quien reparte permisos no
 * puede ser alguien que los recibe, o un rol se daria a si mismo los que le
 * faltan.
 *
 * El `{team_role}` lo resuelve el route model binding, que aqui SI filtra por
 * tienda: `TeamRole` lleva `BelongsToTenant` con el fallo en cerrado y la
 * tienda se resuelve antes del binding (SEC-2), asi que un rol de otra tienda
 * responde 404. La excepcion de `User` no aplica a este modelo.
 */
class TeamRoleController extends Controller
{
    /** Lo que se puede marcar en un rol, agrupado por área. */
    public function catalogo(): JsonResponse
    {
        return response()->json(['areas' => Permisos::porArea()]);
    }

    public function index(): JsonResponse
    {
        $roles = TeamRole::query()->orderBy('created_at')->orderBy('id')->get();
        $miembros = $this->miembrosPorRol();

        return response()->json($roles->map(fn (TeamRole $rol) => $this->paraResponder($rol, $miembros[$rol->id] ?? 0))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request, null);

        $rol = new TeamRole([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => Permisos::normalizar($data['permissions'] ?? []),
        ]);
        $rol->tenant_id = app('currentTenant')->id;
        $rol->save();

        Bitacora::anotar(
            ActivityLog::EQUIPO_ROL_CREADO,
            "Creó el rol «{$rol->name}»: ".$this->resumenDePermisos($rol->permissions).'.',
            ['rol_id' => $rol->id, 'rol' => $rol->name, 'permisos' => $rol->permissions],
        );

        return response()->json($this->paraResponder($rol, 0), 201);
    }

    /**
     * Editar un rol cambia lo que pueden todos los que lo tienen.
     *
     * Si cambian sus permisos se les cierran las sesiones, por lo mismo que al
     * cambiarle el rol a una persona (FUN-4): el servidor ya aplica lo nuevo en
     * su siguiente peticion, pero el panel que tienen abierto se pinto con lo
     * de antes -botones que ahora darian 403, o que faltan-. Cambiar solo el
     * nombre o la descripcion no echa a nadie.
     */
    public function update(Request $request, TeamRole $teamRole): JsonResponse
    {
        $data = $this->validar($request, $teamRole);

        $antes = [
            'name'        => $teamRole->name,
            'description' => (string) $teamRole->description,
            'permissions' => (array) $teamRole->permissions,
        ];

        if (array_key_exists('permissions', $data)) {
            $data['permissions'] = Permisos::normalizar($data['permissions'] ?? []);
        }

        $teamRole->update($data);

        $cambiaPermisos = $teamRole->permissions !== $antes['permissions'];

        if ($cambiaPermisos) {
            foreach ($this->miembrosDe($teamRole) as $miembro) {
                $miembro->tokens()->delete();
            }
        }

        $cambios = Bitacora::cambios(
            ['name' => $antes['name'], 'description' => $antes['description']],
            ['name' => $teamRole->name, 'description' => (string) $teamRole->description],
            ['name' => 'nombre', 'description' => 'descripción'],
        );

        $partes = $cambios !== [] ? [Bitacora::resumirCambios($cambios)] : [];

        if ($cambiaPermisos) {
            $catalogo = Permisos::catalogo();
            $etiqueta = fn (string $clave) => mb_strtolower($catalogo[$clave]['etiqueta'] ?? $clave);
            $nuevos = array_values(array_diff($teamRole->permissions, $antes['permissions']));
            $quitados = array_values(array_diff($antes['permissions'], $teamRole->permissions));

            if ($nuevos !== []) {
                $partes[] = 'ahora puede '.implode('; ', array_map($etiqueta, $nuevos));
            }

            if ($quitados !== []) {
                $partes[] = 'ya no puede '.implode('; ', array_map($etiqueta, $quitados));
            }
        }

        if ($partes !== []) {
            Bitacora::anotar(
                ActivityLog::EQUIPO_ROL_EDITADO,
                "Cambió el rol «{$teamRole->name}»: ".implode('; ', $partes).'.',
                [
                    'rol_id'   => $teamRole->id,
                    'rol'      => $teamRole->name,
                    'cambios'  => $cambios,
                    'permisos' => ['antes' => $antes['permissions'], 'despues' => $teamRole->permissions],
                ],
            );
        }

        return response()->json($this->paraResponder($teamRole, $this->miembrosDe($teamRole)->count()));
    }

    /**
     * Borrar un rol que alguien tiene no se deja: esa persona se quedaria sin
     * ningun permiso sin que nadie lo decidiera. Primero se le cambia el rol.
     */
    public function destroy(TeamRole $teamRole): JsonResponse
    {
        $miembros = $this->miembrosDe($teamRole)->count();

        if ($miembros > 0) {
            return response()->json([
                'message' => $miembros === 1
                    ? 'Este rol lo tiene una persona del equipo: cámbiale el rol antes de borrarlo.'
                    : "Este rol lo tienen {$miembros} personas del equipo: cámbiales el rol antes de borrarlo.",
            ], 422);
        }

        $teamRole->delete();

        Bitacora::anotar(
            ActivityLog::EQUIPO_ROL_BORRADO,
            "Borró el rol «{$teamRole->name}».",
            ['rol_id' => $teamRole->id, 'rol' => $teamRole->name, 'permisos' => $teamRole->permissions],
        );

        return response()->json(null, 204);
    }

    /**
     * @return array{name?: string, description?: string|null, permissions?: list<string>}
     */
    private function validar(Request $request, ?TeamRole $rol): array
    {
        $parcial = $rol !== null ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [
                $parcial,
                'string',
                'max:40',
                function ($atributo, $valor, $fallar) use ($rol) {
                    $clave = $this->claveDeNombre((string) $valor);

                    if ($clave === '') {
                        $fallar('Escribe el nombre del rol.');

                        return;
                    }

                    // «Administrador» no es un rol del equipo: es el acceso
                    // completo, y un rol que se llamara igual haria creer que lo es.
                    if (in_array($clave, ['administrador', 'administradora', 'admin'], true)) {
                        $fallar('«Administrador» ya es el acceso completo a la tienda: ponle otro nombre a este rol.');

                        return;
                    }

                    // En PHP y no con `unique:` en SQL: SQLite distingue
                    // mayusculas y tildes y MySQL no, asi que la misma regla daria
                    // dos respuestas (lo mismo que `claveDeNombre` del import).
                    $repetido = TeamRole::query()
                        ->when($rol, fn ($q) => $q->where('id', '!=', $rol->id))
                        ->pluck('name')
                        ->contains(fn ($nombre) => $this->claveDeNombre($nombre) === $clave);

                    if ($repetido) {
                        $fallar('Ya hay un rol con ese nombre.');
                    }
                },
            ],
            'description'   => ['sometimes', 'nullable', 'string', 'max:200'],
            // `present` y no `required` al crear: un rol sin ningun permiso es
            // valido (sirve para dejar a alguien dentro sin que toque nada), y
            // `required` rechaza la lista vacia.
            'permissions'   => [$rol !== null ? 'sometimes' : 'present', 'array'],
            'permissions.*' => ['string', Rule::in(Permisos::claves())],
        ], [
            'name.required'       => 'Escribe el nombre del rol.',
            'name.max'            => 'El nombre del rol no puede pasar de 40 caracteres.',
            'permissions.present' => 'Falta la lista de permisos del rol.',
            'permissions.*.in'    => 'Uno de los permisos marcados no existe. Recarga la página y vuelve a intentarlo.',
        ]);
    }

    /** Sin mayúsculas, tildes ni espacios de más: «Caja» y «  caja » son el mismo rol. */
    private function claveDeNombre(string $nombre): string
    {
        $limpio = mb_strtolower(preg_replace('/\s+/u', ' ', trim($nombre)));
        $sinTildes = Str::ascii($limpio);

        return $sinTildes !== '' ? $sinTildes : $limpio;
    }

    /**
     * La gente del equipo con ese rol, activa o no: quien esta desactivado
     * tambien lo tiene, y al reactivarlo volveria sin ningun permiso si el rol
     * se hubiera borrado.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function miembrosDe(TeamRole $rol): \Illuminate\Support\Collection
    {
        return User::where('tenant_id', app('currentTenant')->id)
            ->where('role', 'staff')
            ->where('team_role_id', $rol->id)
            ->get();
    }

    /** @return array<string, int> id del rol => personas */
    private function miembrosPorRol(): array
    {
        return User::where('tenant_id', app('currentTenant')->id)
            ->where('role', 'staff')
            ->whereNotNull('team_role_id')
            ->selectRaw('team_role_id, count(*) as total')
            ->groupBy('team_role_id')
            ->pluck('total', 'team_role_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** @return array<string, mixed> */
    private function paraResponder(TeamRole $rol, int $miembros): array
    {
        $permisos = $rol->permisosEfectivos();

        return [
            'id'            => $rol->id,
            'name'          => $rol->name,
            'description'   => $rol->description,
            'permissions'   => $permisos,
            'areas'         => Permisos::areasDe($permisos),
            'members_count' => $miembros,
        ];
    }

    /** @param  list<string>  $permisos */
    private function resumenDePermisos(array $permisos): string
    {
        $areas = Permisos::areasDe($permisos);

        return $areas === [] ? 'sin ningún permiso' : 'con acceso a '.mb_strtolower(implode(', ', $areas));
    }
}
