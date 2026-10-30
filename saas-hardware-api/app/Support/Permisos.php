<?php

namespace App\Support;

use App\Models\User;

/**
 * Qué puede hacer cada persona del panel de una tienda (MOD-21).
 *
 * El único sitio que lo decide. Las rutas lo preguntan con el middleware
 * `permiso:<clave>` (`EnsurePermiso`) y el código que necesita afinar dentro de
 * una ruta -enseñar el costo, borrar en lote- con `puede()`. El catálogo vive
 * en `config/permisos.php`.
 *
 * Tres reglas:
 *
 * - **Un `admin` lo puede todo** y no tiene rol. El equipo y el plan no son
 *   permisos: son suyos y no se delegan (ver la cabecera de la config).
 * - **Un `staff` puede lo que diga su rol**, leído de la base en cada petición
 *   y no del token: editar un rol surte efecto en el siguiente clic de quien lo
 *   tiene, como cambiar el rol desde FUN-4.
 * - **Falla en cerrado**, como AUD-4 y el costo de MOD-6: un staff sin rol, con
 *   un rol de otra tienda o con una clave que ya no existe no puede nada con
 *   ella; un cliente u otro usuario cualquiera, tampoco.
 */
final class Permisos
{
    public const MENSAJE_SIN_PERMISO = 'Tu rol no te permite hacer esto. Pídeselo a un administrador de la tienda.';

    /**
     * Todos los permisos, en el orden de la config, con su área y lo que requieren.
     *
     * @return array<string, array{area: string, etiqueta: string, requiere: list<string>}>
     */
    public static function catalogo(): array
    {
        $plano = [];

        foreach ((array) config('permisos.areas') as $area => $definicion) {
            foreach ((array) ($definicion['permisos'] ?? []) as $clave => $permiso) {
                $plano[$clave] = [
                    'area'     => $area,
                    'etiqueta' => (string) $permiso['etiqueta'],
                    'requiere' => array_values((array) ($permiso['requiere'] ?? [])),
                ];
            }
        }

        return $plano;
    }

    /** @return list<string> */
    public static function claves(): array
    {
        return array_keys(self::catalogo());
    }

    /**
     * El catálogo agrupado por área, que es como lo pinta el editor de roles.
     *
     * @return list<array{clave: string, etiqueta: string, permisos: list<array{clave: string, etiqueta: string, requiere: list<string>}>}>
     */
    public static function porArea(): array
    {
        $areas = [];

        foreach ((array) config('permisos.areas') as $area => $definicion) {
            $permisos = [];

            foreach ((array) ($definicion['permisos'] ?? []) as $clave => $permiso) {
                $permisos[] = [
                    'clave'    => $clave,
                    'etiqueta' => (string) $permiso['etiqueta'],
                    'requiere' => array_values((array) ($permiso['requiere'] ?? [])),
                ];
            }

            $areas[] = ['clave' => $area, 'etiqueta' => (string) $definicion['etiqueta'], 'permisos' => $permisos];
        }

        return $areas;
    }

    /** @return list<string> */
    public static function delColaborador(): array
    {
        return array_values((array) config('permisos.colaborador'));
    }

    /**
     * Lo que se guarda de un rol: las claves conocidas, más lo que requieren
     * (marcar "importar" marca "editar" y "ver"), sin repetir y en el orden de
     * la config. Las claves desconocidas las rechaza antes la validación.
     *
     * @param  array<int, mixed>  $pedidos
     * @return list<string>
     */
    public static function normalizar(array $pedidos): array
    {
        $catalogo = self::catalogo();
        $marcados = [];
        $pendientes = array_values(array_filter($pedidos, fn ($clave) => is_string($clave) && isset($catalogo[$clave])));

        while ($pendientes !== []) {
            $clave = array_pop($pendientes);

            if (isset($marcados[$clave])) {
                continue;
            }

            $marcados[$clave] = true;
            array_push($pendientes, ...$catalogo[$clave]['requiere']);
        }

        return array_values(array_filter(array_keys($catalogo), fn ($clave) => isset($marcados[$clave])));
    }

    /**
     * Lo que vale de lo guardado: sin claves que ya no existen y sin permisos
     * a los que les falte algo que requieren. Lo segundo solo pasa si la
     * config cambió después de guardar el rol, y entonces no se adivina: se
     * quita, hasta que un admin vuelva a guardar el rol.
     *
     * @param  array<int, mixed>  $guardados
     * @return list<string>
     */
    public static function efectivos(array $guardados): array
    {
        $catalogo = self::catalogo();
        $vigentes = array_fill_keys(array_filter($guardados, fn ($clave) => is_string($clave) && isset($catalogo[$clave])), true);

        do {
            $quitado = false;

            foreach (array_keys($vigentes) as $clave) {
                foreach ($catalogo[$clave]['requiere'] as $requerido) {
                    if (! isset($vigentes[$requerido])) {
                        unset($vigentes[$clave]);
                        $quitado = true;

                        continue 2;
                    }
                }
            }
        } while ($quitado);

        return array_values(array_filter(array_keys($catalogo), fn ($clave) => isset($vigentes[$clave])));
    }

    /**
     * Los permisos de una persona del panel.
     *
     * @return list<string>
     */
    public static function de(?User $usuario): array
    {
        if (! $usuario || ! $usuario->esDelPanel()) {
            return [];
        }

        if ($usuario->role === 'admin') {
            return self::claves();
        }

        $rol = $usuario->teamRole;

        // La relacion se carga sin el scope de tienda (ver `User::teamRole`),
        // asi que la tienda se comprueba aqui: un rol que no es de la tienda
        // de la persona no le da nada.
        if (! $rol || $rol->tenant_id !== $usuario->tenant_id) {
            return [];
        }

        return $rol->permisosEfectivos();
    }

    public static function puede(?User $usuario, string $permiso): bool
    {
        return in_array($permiso, self::de($usuario), true);
    }

    /** El de quien hace la petición. */
    public static function usuarioPuede(string $permiso): bool
    {
        $usuario = auth()->user();

        return self::puede($usuario instanceof User ? $usuario : null, $permiso);
    }

    /** 403 con el mismo mensaje que el middleware, para lo que se decide dentro de una ruta. */
    public static function exigir(string $permiso): void
    {
        if (! self::usuarioPuede($permiso)) {
            abort(403, self::MENSAJE_SIN_PERMISO);
        }
    }

    /**
     * Las áreas en las que un rol tiene algo, por su nombre: «Pedidos,
     * Productos y Reseñas». Para la invitación y la pantalla de equipo.
     *
     * @param  list<string>  $permisos
     * @return list<string>
     */
    public static function areasDe(array $permisos): array
    {
        $areas = [];

        foreach (self::porArea() as $area) {
            foreach ($area['permisos'] as $permiso) {
                if (in_array($permiso['clave'], $permisos, true)) {
                    $areas[] = $area['etiqueta'];

                    continue 2;
                }
            }
        }

        return $areas;
    }
}
