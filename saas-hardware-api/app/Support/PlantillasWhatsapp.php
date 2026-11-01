<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Los mensajes de WhatsApp que cada tienda puede reescribir (MOD-25).
 *
 * Los textos de fábrica y lo que admite cada uno están en
 * `config/plantillas_whatsapp.php`; aquí vive todo lo demás: qué texto toca
 * a una tienda, qué se publica, qué se guarda y qué se rechaza. El navegador
 * no tiene copia de nada de esto, solo las reglas de rellenado
 * (`utils/plantillasWhatsapp.ts`).
 *
 * En `tenants.whatsapp_templates` se guarda **solo lo que la tienda cambió**
 * (clave => texto). Lo que no está ahí sale de la config, así que mejorar un
 * texto de fábrica le llega a toda tienda que no lo tocó, y "restaurar" es
 * quitar la clave.
 */
class PlantillasWhatsapp
{
    public const GRUPO_COMPRADOR = 'comprador';

    public const GRUPO_TIENDA = 'tienda';

    /** `{cliente}`, `{pedido}`… La misma forma que lee el navegador. */
    private const PATRON_VARIABLE = '/\{([a-z_]+)\}/';

    /** @return array<string, array{grupo: string, etiqueta: string, cuando: string, variables: list<string>, texto: string}> */
    public static function catalogo(): array
    {
        return config('plantillas_whatsapp.plantillas');
    }

    /** @return list<string> */
    public static function claves(): array
    {
        return array_keys(self::catalogo());
    }

    public static function maximo(): int
    {
        return (int) config('plantillas_whatsapp.max');
    }

    /**
     * El texto que usa la tienda para cada plantilla: el suyo si lo cambió, y
     * si no el de fábrica. Con `$grupo`, solo las de ese grupo.
     *
     * Una clave guardada que ya no existe en la config (renombrada o quitada)
     * se ignora: nunca sale un texto huérfano.
     *
     * @return array<string, string>
     */
    public static function resueltas(Tenant $tenant, ?string $grupo = null): array
    {
        $propias = is_array($tenant->whatsapp_templates) ? $tenant->whatsapp_templates : [];

        return collect(self::catalogo())
            ->filter(fn (array $plantilla) => $grupo === null || $plantilla['grupo'] === $grupo)
            ->map(fn (array $plantilla, string $clave) => is_string($propias[$clave] ?? null) && $propias[$clave] !== ''
                ? $propias[$clave]
                : $plantilla['texto'])
            ->all();
    }

    /**
     * Las de fábrica de un grupo, sin mirar ninguna tienda. Las usa el catálogo
     * público como respaldo cuando lo que tenía en caché es de antes de MOD-25.
     *
     * @return array<string, string>
     */
    public static function deFabrica(string $grupo): array
    {
        return collect(self::catalogo())
            ->filter(fn (array $plantilla) => $plantilla['grupo'] === $grupo)
            ->map(fn (array $plantilla) => $plantilla['texto'])
            ->all();
    }

    /**
     * Lo que ve el panel: cada plantilla con su texto, el de fábrica (para
     * "restaurar" y comparar) y si la tienda la cambió.
     *
     * @return array{max: int, variables: array<string, string>, plantillas: list<array<string, mixed>>}
     */
    public static function paraElPanel(Tenant $tenant): array
    {
        $resueltas = self::resueltas($tenant);
        $propias = is_array($tenant->whatsapp_templates) ? $tenant->whatsapp_templates : [];

        return [
            'max'        => self::maximo(),
            'variables'  => config('plantillas_whatsapp.variables'),
            'plantillas' => collect(self::catalogo())
                ->map(fn (array $plantilla, string $clave) => [
                    'clave'         => $clave,
                    'grupo'         => $plantilla['grupo'],
                    'etiqueta'      => $plantilla['etiqueta'],
                    'cuando'        => $plantilla['cuando'],
                    'variables'     => $plantilla['variables'],
                    'texto'         => $resueltas[$clave],
                    'de_fabrica'    => $plantilla['texto'],
                    'personalizada' => array_key_exists($clave, $propias),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Las variables que usa un texto, sin repetir y en el orden en que salen.
     *
     * @return list<string>
     */
    public static function variablesDe(string $texto): array
    {
        preg_match_all(self::PATRON_VARIABLE, $texto, $coincidencias);

        return array_values(array_unique($coincidencias[1]));
    }

    /**
     * Las variables de un texto que esa plantilla no admite.
     *
     * @return list<string>
     */
    public static function variablesQueNoAdmite(string $clave, string $texto): array
    {
        $admitidas = self::catalogo()[$clave]['variables'] ?? [];

        return array_values(array_diff(self::variablesDe($texto), $admitidas));
    }

    /**
     * El texto tal como se guarda: saltos de línea de Windows convertidos y sin
     * espacios en los bordes. Vacío cuenta como "sin texto propio".
     */
    public static function limpiar(?string $texto): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $texto));
    }

    /**
     * Las plantillas propias que quedan después de un guardado.
     *
     * Solo cambian las claves que llegan; las demás se quedan como estaban.
     * Un texto vacío, `null` o idéntico al de fábrica **quita** la clave: no se
     * guarda una copia del de fábrica, o esa tienda dejaría de recibir las
     * mejoras del texto original sin haberlo decidido.
     *
     * Se espera la entrada ya validada (claves conocidas, variables admitidas).
     *
     * @param  array<string, string|null>  $entrada
     * @return array<string, string>|null  `null` cuando no queda ninguna propia.
     */
    public static function fusionar(Tenant $tenant, array $entrada): ?array
    {
        $propias = is_array($tenant->whatsapp_templates) ? $tenant->whatsapp_templates : [];
        $catalogo = self::catalogo();

        foreach ($entrada as $clave => $texto) {
            $limpio = self::limpiar($texto);

            if ($limpio === '' || $limpio === $catalogo[$clave]['texto']) {
                unset($propias[$clave]);

                continue;
            }

            $propias[$clave] = $limpio;
        }

        // Fuera las claves que la config ya no conoce: no se pueden ver ni
        // editar, y guardarlas solo ensuciaría la columna.
        $propias = array_intersect_key($propias, $catalogo);

        return $propias === [] ? null : $propias;
    }
}
