<?php

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;

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
     * Los tipos que `ImageService::uploadProductImage()` decodifica enteros en
     * memoria (el logo y la portada pasan por ahí). El favicon se guarda tal
     * cual y el CSV no es una imagen.
     */
    private const SE_DECODIFICAN = ['imagen', 'logo', 'banner'];

    /**
     * `INF-14`: lo que cuesta cada píxel al decodificar con GD —4 bytes por
     * píxel de la imagen entera, más los búferes del decodificador—, con margen.
     * Medido el 2026-09-26 con el proceso de `uploadProductImage()` fuera de
     * Laravel: 12 MP → 70 MB, 24 MP → 114 MB, 48 MP → 210 MB. Dentro, con 128M,
     * 12 MP llega a 104 MB y 24 MP ya no cabe.
     */
    private const BYTES_POR_PIXEL = 5;

    /** Lo que ya usa la petición antes de tocar la foto (Laravel, la sesión…). */
    private const RESERVA = 64 * 1024 * 1024;

    /**
     * Las reglas `mimes` y `max` de un tipo de subida, y en las fotos, que
     * quepa en memoria. Quien llama pone delante `image` o `file` (el favicon
     * no puede llevar `image`: no reconoce el .ico).
     *
     * @return list<string|Closure>
     */
    public static function reglas(string $tipo): array
    {
        $limite = config("subidas.{$tipo}");

        if (! is_array($limite)) {
            throw new \InvalidArgumentException("No hay tope de subida para '{$tipo}' en config/subidas.php.");
        }

        $reglas = ['mimes:'.implode(',', $limite['tipos']), 'max:'.$limite['kb']];

        if (in_array($tipo, self::SE_DECODIFICAN, true)) {
            $reglas[] = self::cabeEnMemoria();
        }

        return $reglas;
    }

    /**
     * Cuántos megapíxeles se pueden decodificar con un `memory_limit`, o `null`
     * si no hay límite (`-1`). Recibe el valor tal como lo da `ini_get()`.
     */
    public static function megapixelesProcesables(string|false $memoryLimit): ?int
    {
        $bytes = self::aBytes((string) $memoryLimit);

        if ($bytes === null) {
            return null;
        }

        return max(0, intdiv($bytes - self::RESERVA, self::BYTES_POR_PIXEL * 1_000_000));
    }

    /**
     * `INF-14`. El tope en MB no dice nada de la memoria que hace falta para
     * procesar una foto: GD la decodifica entera, y lo que cuenta son sus
     * píxeles, no lo que pesa el archivo. Una foto de 48 MP pesa 8,6 MB —dentro
     * de los 10— y pide 210 MB; con el `memory_limit` de fábrica (128M) PHP moría
     * a mitad y el dueño veía un "Server Error". En local nunca se veía: Laragon
     * trae 512M.
     *
     * Aquí se miden las dimensiones con `getimagesize()`, que solo lee la
     * cabecera, y se compara con lo que cabe en el `memory_limit` de ESTE
     * servidor: con más memoria, entran fotos más grandes sin tocar nada.
     */
    private static function cabeEnMemoria(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile || ! $value->isValid()) {
                return;
            }

            // Si no se lee como imagen, ya lo dicen `image` y `mimes`.
            $medidas = @getimagesize($value->getRealPath());
            $maximo = self::megapixelesProcesables(ini_get('memory_limit'));

            if ($medidas === false || $maximo === null || $medidas[0] * $medidas[1] <= $maximo * 1_000_000) {
                return;
            }

            [$ancho, $alto] = $medidas;
            $megapixeles = str_replace('.', ',', (string) round($ancho * $alto / 1_000_000, 1));

            $fail("El campo :attribute mide {$ancho} × {$alto} px ({$megapixeles} MP) y el servidor solo puede "
                ."procesar fotos de hasta {$maximo} MP. Redúcela antes de subirla.");
        };
    }

    /** `128M` → bytes, como `ValidatePostSize` de Laravel; `-1` → sin límite. */
    private static function aBytes(string $valor): ?int
    {
        $valor = trim($valor);

        if ($valor === '' || (int) $valor < 0) {
            return null;
        }

        return match (strtoupper(substr($valor, -1))) {
            'K' => (int) $valor * 1024,
            'M' => (int) $valor * 1024 * 1024,
            'G' => (int) $valor * 1024 * 1024 * 1024,
            default => (int) $valor,
        };
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
