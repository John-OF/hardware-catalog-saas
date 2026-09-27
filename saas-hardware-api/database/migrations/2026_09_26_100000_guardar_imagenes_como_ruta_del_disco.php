<?php

use App\Support\ImagenesDelDisco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las imágenes subidas pasan de guardarse como URL completa a guardarse como
 * ruta dentro del disco (`TEC-15`).
 *
 * No cambia ninguna columna: cambia lo que hay dentro. Con la URL completa, el
 * dominio del almacén quedaba escrito en cada fila, y cambiar de bucket, de CDN
 * o de disco rompía todas las imágenes a la vez. Desde los casts `ImagenDelDisco`
 * y `TemaDeTienda`, lo nuevo ya se guarda como ruta; esto convierte lo de antes.
 *
 * **Solo toca lo que empieza por la URL base del disco de ahora** (la inversa
 * exacta de `Storage::url()`, en `ImagenesDelDisco::ruta()`). Un logo pegado de
 * otra web, o una URL de un dominio anterior, se queda como está y se sigue
 * leyendo igual: el cast deja pasar lo que no es una ruta. Por lo mismo, el
 * código nuevo funciona antes y después de migrar, y el orden del despliegue no
 * importa.
 *
 * Fila a fila y por `id`, con `DB::table`: sin el scope de tienda —es para
 * todas— y sin los casts, que son justo lo que se está migrando. `down()`
 * vuelve a escribir la URL completa con el disco de ese momento.
 */
return new class extends Migration
{
    private const COLUMNAS = [
        'products' => ['image_url', 'thumbnail_url'],
        'product_images' => ['image_url', 'thumbnail_url'],
        'product_variants' => ['image_url', 'thumbnail_url'],
        'tenants' => ['logo_url'],
    ];

    /** Portada y favicon viven dentro de `tenants.theme`. */
    private const EN_EL_TEMA = ['banner_url', 'favicon_url'];

    public function up(): void
    {
        $this->convertir(fn (?string $valor) => ImagenesDelDisco::ruta($valor));
    }

    public function down(): void
    {
        $this->convertir(fn (?string $valor) => ImagenesDelDisco::url($valor));
    }

    private function convertir(Closure $como): void
    {
        foreach (self::COLUMNAS as $tabla => $columnas) {
            DB::table($tabla)
                ->select(['id', ...$columnas])
                ->chunkById(500, function ($filas) use ($tabla, $columnas, $como) {
                    foreach ($filas as $fila) {
                        $cambios = [];

                        foreach ($columnas as $columna) {
                            $nuevo = $como($fila->{$columna});

                            if ($nuevo !== $fila->{$columna}) {
                                $cambios[$columna] = $nuevo;
                            }
                        }

                        if ($cambios !== []) {
                            DB::table($tabla)->where('id', $fila->id)->update($cambios);
                        }
                    }
                });
        }

        DB::table('tenants')
            ->whereNotNull('theme')
            ->select(['id', 'theme'])
            ->chunkById(200, function ($filas) use ($como) {
                foreach ($filas as $fila) {
                    $tema = json_decode($fila->theme, true);

                    if (! is_array($tema)) {
                        continue;
                    }

                    $cambiado = false;

                    foreach (self::EN_EL_TEMA as $clave) {
                        if (isset($tema[$clave]) && is_string($tema[$clave]) && $como($tema[$clave]) !== $tema[$clave]) {
                            $tema[$clave] = $como($tema[$clave]);
                            $cambiado = true;
                        }
                    }

                    if ($cambiado) {
                        DB::table('tenants')->where('id', $fila->id)->update(['theme' => json_encode($tema)]);
                    }
                }
            });
    }
};
