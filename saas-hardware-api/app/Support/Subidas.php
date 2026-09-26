<?php

namespace App\Support;

/**
 * Los topes de subida de `config/subidas.php`, como reglas y como texto (UI-15).
 *
 * Toda regla que acepte un archivo saca de aquí su `mimes` y su `max`, nunca
 * los escribe a mano: el navegador avisa con el mismo número, y la copia del
 * frontend se compara con la config en `LimitesDeSubidaTest`.
 */
class Subidas
{
    /**
     * Las reglas `mimes` y `max` de un tipo de subida. Quien llama pone delante
     * `image` o `file` (el favicon no puede llevar `image`: no reconoce el .ico).
     *
     * @return array{0: string, 1: string}
     */
    public static function reglas(string $tipo): array
    {
        $limite = config("subidas.{$tipo}");

        if (! is_array($limite)) {
            throw new \InvalidArgumentException("No hay tope de subida para '{$tipo}' en config/subidas.php.");
        }

        return ['mimes:'.implode(',', $limite['tipos']), 'max:'.$limite['kb']];
    }

    /**
     * Un tope en kilobytes como lo dice una persona: 10240 → "10 MB",
     * 1536 → "1,5 MB", 512 → "512 KB".
     *
     * Es lo que pone el mensaje de la regla `max` de un archivo (`:tamano`, ver
     * `AppServiceProvider::mensajesDeValidacion()`), y `tamanoLegible()` de
     * `utils/subidas.ts` hace lo mismo en el navegador: si cambia una, la otra.
     */
    public static function legible(int $kb): string
    {
        if ($kb < 1024) {
            return "{$kb} KB";
        }

        $megas = round($kb / 1024, 1);

        return str_replace('.', ',', (string) $megas).' MB';
    }
}
