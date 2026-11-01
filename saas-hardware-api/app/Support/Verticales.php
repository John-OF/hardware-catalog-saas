<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Qué partes del sistema enseña una tienda según su rubro (MOD-19).
 *
 * La matriz vive en `config/verticales.php`. Todo lo que dependa del rubro
 * pregunta aquí por un MÓDULO (`tiene($tienda, Verticales::ARMADOR)`), nunca
 * por el nombre de la vertical: así una vertical nueva que quiera el armador no
 * obliga a buscar cada `=== 'hardware'` del código.
 *
 * Falla en cerrado por los dos lados, como `PlanGate` y `Permisos`: una
 * vertical que no está en la lista cae en la de por defecto, y un módulo sin lo
 * que `requiere` se apaga.
 */
class Verticales
{
    public const TIPO_DE_COMPONENTE = 'tipo_de_componente';

    public const FICHA_TECNICA = 'ficha_tecnica';

    public const ARMADOR = 'armador';

    /** La vertical que se aplica: la de la tienda si existe, si no la de por defecto. */
    public static function clave(Tenant|string|null $tienda): string
    {
        $clave = $tienda instanceof Tenant ? $tienda->vertical : $tienda;

        return is_string($clave) && array_key_exists($clave, self::verticales())
            ? $clave
            : (string) config('verticales.default');
    }

    /**
     * Cada módulo de la config con si esta tienda lo tiene, ya con `requiere`
     * aplicado. Es lo que viaja con la tienda hacia el frontend.
     *
     * @return array<string, bool>
     */
    public static function modulos(Tenant|string|null $tienda): array
    {
        $encendidos = self::verticales()[self::clave($tienda)]['modulos'] ?? [];

        $resultado = [];

        foreach (self::claves() as $modulo) {
            $resultado[$modulo] = self::encendido($modulo, $encendidos);
        }

        return $resultado;
    }

    public static function tiene(Tenant|string|null $tienda, string $modulo): bool
    {
        return self::modulos($tienda)[$modulo] ?? false;
    }

    /** @return list<string> Las claves de los módulos, en el orden de la config. */
    public static function claves(): array
    {
        return array_keys(config('verticales.modulos', []));
    }

    /**
     * Para el selector del operador: cada vertical con su nombre en pantalla.
     *
     * @return array<string, string>
     */
    public static function disponibles(): array
    {
        return array_map(fn (array $vertical) => (string) $vertical['label'], self::verticales());
    }

    /** @return array<string, array{label: string, modulos: list<string>}> */
    private static function verticales(): array
    {
        return config('verticales.verticales', []);
    }

    /**
     * @param  list<string>  $encendidos
     * @param  list<string>  $camino  Lo que ya se está mirando, para que una
     *                                config circular no dé la vuelta para siempre.
     */
    private static function encendido(string $modulo, array $encendidos, array $camino = []): bool
    {
        $definicion = config("verticales.modulos.{$modulo}");

        if (! is_array($definicion) || ! in_array($modulo, $encendidos, true) || in_array($modulo, $camino, true)) {
            return false;
        }

        foreach ($definicion['requiere'] ?? [] as $requerido) {
            if (! self::encendido($requerido, $encendidos, [...$camino, $modulo])) {
                return false;
            }
        }

        return true;
    }
}
