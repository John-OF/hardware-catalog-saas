<?php

namespace Tests\Feature;

use App\Support\Money;
use Tests\TestCase;

/**
 * `FUN-23`. El panel y el catálogo formateaban los precios con `Intl` y el
 * servidor —la cotización en PDF, los correos, el SEO— con `number_format()`
 * y coma de miles para todas las monedas: en 10 de las 15 el mismo precio salía
 * escrito de dos formas, y el cliente argentino recibía `$9,000,000.50` en la
 * cotización de un precio que en el catálogo decía `$ 9.000.000,50`.
 *
 * Ahora los dos lados leen la misma tabla, y aquí se vigilan las dos mitades:
 * que las copias de la tabla no se separen, y que las dos implementaciones den
 * lo mismo. Los casos de `test_cada_moneda_*` y `test_el_redondeo_*` son
 * **exactamente** los de `utils/money.test.ts`: si se cambia uno, el otro.
 *
 * `·` en los esperados es el espacio que no se parte (U+00A0), que se escribe
 * así para que se vea.
 */
class FormatoDeMonedaTest extends TestCase
{
    public function test_cada_moneda_se_escribe_como_en_el_panel(): void
    {
        $esperados = [
            'USD' => '$1,234,567.50',
            'PEN' => 'S/·1,234,567.50',
            'MXN' => '$1,234,567.50',
            'COP' => '$·1.234.568',
            'CLP' => '$1.234.568',
            'ARS' => '$·1.234.567,50',
            'BOB' => 'Bs·1.234.567,50',
            'BRL' => 'R$·1.234.567,50',
            'UYU' => '$U·1.234.567,50',
            'PYG' => '₲·1.234.568',
            'VES' => 'Bs.·1.234.567,50',
            'GTQ' => 'Q·1,234,567.50',
            'DOP' => 'RD$1,234,567.50',
            'CRC' => '₡1·234·567,50',
            'EUR' => '1.234.567,50·€',
        ];

        $this->assertSame(array_keys(config('currencies')), array_keys($esperados), 'Falta un caso para alguna moneda de la lista.');

        foreach ($esperados as $moneda => $esperado) {
            $this->assertSame($this->visible($esperado), Money::format(1234567.5, $moneda), $moneda);
        }
    }

    public function test_el_redondeo_y_el_signo(): void
    {
        // Sin decimales se redondea el medio hacia fuera, como en el panel.
        $this->assertSame('$1.029', Money::format(1028.98, 'CLP'));
        $this->assertSame($this->visible('₲·1'), Money::format(0.5, 'PYG'));
        // `number_format()` redondea 1.005 a 1.01; el panel tiene que hacer lo mismo.
        $this->assertSame('$1.01', Money::format(1.005, 'USD'));
        // El signo va delante de todo, y un cero redondeado no lo lleva.
        $this->assertSame($this->visible('-S/·5.00'), Money::format(-5, 'PEN'));
        $this->assertSame($this->visible('-5,00·€'), Money::format(-5, 'EUR'));
        $this->assertSame('$0', Money::format(-0.4, 'CLP'));
        // Lo que devuelve la API: el decimal como texto.
        $this->assertSame($this->visible('$·1.028,98'), Money::format('1028.98', 'ARS'));
    }

    public function test_sin_moneda_o_con_una_desconocida(): void
    {
        $this->assertSame('$1,028.98', Money::format(1028.98, null));
        $this->assertSame('$1,028.98', Money::format(1028.98, 'usd'));
        // No se inventa un símbolo: el código delante.
        $this->assertSame('XYZ 1,028.98', Money::format(1028.98, 'XYZ'));
    }

    /**
     * Mismo patrón que las zonas horarias (`ZonaHorariaDeLaTiendaTest`) y los
     * topes de subida (`LimitesDeSubidaTest`): la copia del frontend se lee del
     * archivo y se compara campo a campo.
     */
    public function test_la_copia_del_frontend_no_se_ha_separado_de_la_config(): void
    {
        $archivo = base_path('../saas-hardware-frontend/src/utils/money.ts');

        if (! file_exists($archivo)) {
            $this->markTestSkipped('El frontend no está en este árbol.');
        }

        preg_match('/export const CURRENCIES[^{]*\{(.*?)\n\};/s', file_get_contents($archivo), $bloque);
        $this->assertNotEmpty($bloque, 'No se encontró CURRENCIES en utils/money.ts.');

        preg_match_all(
            "/^\s*([A-Z]{3}): \{ label: '[^']*', simbolo: '([^']*)', decimales: (\d+), miles: '([^']*)', decimal: '([^']*)', patron: '([^']*)' \},$/m",
            $bloque[1], $filas, PREG_SET_ORDER,
        );

        // Las cadenas del .ts pueden llevar escapes (`\u00A0`): se leen como JSON.
        $texto = fn (string $valor) => json_decode('"'.$valor.'"');

        $delFrontend = [];
        foreach ($filas as [, $moneda, $simbolo, $decimales, $miles, $decimal, $patron]) {
            $delFrontend[$moneda] = [
                'symbol' => $texto($simbolo), 'decimals' => (int) $decimales,
                'miles' => $texto($miles), 'decimal' => $texto($decimal), 'patron' => $texto($patron),
            ];
        }

        $delBackend = array_map(
            fn (array $moneda) => array_intersect_key($moneda, array_flip(['symbol', 'decimals', 'miles', 'decimal', 'patron'])),
            config('currencies'),
        );

        $this->assertCount(count($delBackend), $delFrontend, 'utils/money.ts tiene monedas que el test no sabe leer, o le faltan.');
        $this->assertSame($delBackend, $delFrontend, 'config/currencies.php y utils/money.ts escriben los precios distinto.');
    }

    /** `·` → el espacio que no se parte. */
    private function visible(string $texto): string
    {
        return str_replace('·', "\u{A0}", $texto);
    }
}
