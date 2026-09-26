<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * `FUN-22`. Ningún archivo de código lleva caracteres de control invisibles.
 *
 * Un `\b` escrito desde un script (Python lanzado desde Bash) atraviesa dos capas
 * de escape y puede llegar como el byte de retroceso `0x08`: el editor no lo
 * enseña, el diff lo pinta como `^H` si alguien mira, y el código sigue
 * compilando. Así se rompió en silencio el reorden de los complementarios por
 * socket y por memoria (del 2026-09-10 al 2026-09-25), y así se coló otro en un
 * test al cerrar `UI-15`. Este test es la alarma que faltó las dos veces.
 *
 * Se permiten el tabulador y los saltos de línea (`\t`, `\n`, `\r`); cualquier
 * otro byte por debajo de 0x20, o el 0x7F, es un error.
 */
class SinCaracteresDeControlTest extends TestCase
{
    private const PATRON = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    private const EXTENSIONES = ['*.php', '*.json', '*.ts', '*.tsx', '*.css', '*.mjs', '*.js', '*.md', '*.yml', '*.html'];

    public function test_el_detector_encuentra_un_retroceso(): void
    {
        $this->assertSame(1, preg_match(self::PATRON, "preg_match('/".chr(8)."(am5)/i')"));
        $this->assertSame(0, preg_match(self::PATRON, "preg_match('/\\b(am5)/i')\n\t\r"));
    }

    public function test_ningun_archivo_de_codigo_lleva_caracteres_de_control(): void
    {
        $raiz = dirname(base_path());

        $carpetas = array_filter([
            base_path('app'),
            base_path('bootstrap'),
            base_path('config'),
            base_path('database'),
            base_path('lang'),
            base_path('resources'),
            base_path('routes'),
            base_path('tests'),
            $raiz.'/saas-hardware-frontend/src',
            $raiz.'/saas-hardware-frontend/scripts',
            $raiz.'/.github',
        ], 'is_dir');

        $archivos = Finder::create()->files()->in($carpetas)->name(self::EXTENSIONES)->exclude('cache');

        $conControl = [];
        $revisados = 0;

        foreach ($archivos as $archivo) {
            $revisados++;

            foreach (file($archivo->getRealPath()) as $n => $linea) {
                if (preg_match(self::PATRON, $linea, $encontrado)) {
                    $conControl[] = sprintf('%s:%d (0x%02X)', $archivo->getRealPath(), $n + 1, ord($encontrado[0]));
                }
            }
        }

        $this->assertGreaterThan(100, $revisados, 'El test no está leyendo el código: revisa las carpetas.');
        $this->assertSame([], $conControl, 'Estos archivos llevan caracteres de control invisibles.');
    }
}
