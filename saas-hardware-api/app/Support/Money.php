<?php

namespace App\Support;

/**
 * Formato de importes del lado del servidor (OWN-1).
 *
 * Lo usan la cotización en PDF, los correos, el SEO y la descripción de un
 * cupón: todo lo que el servidor le enseña a una persona. Tiene que escribir el
 * precio **igual que el panel y el catálogo** (`src/utils/money.ts`), y por eso
 * los dos leen la misma tabla de `config/currencies.php` —separadores y patrón
 * por moneda— en vez de fiarse cada uno de su motor (FUN-23): antes este lado
 * usaba `number_format()` con coma de miles para todas las monedas y el panel
 * `Intl`, y en 10 de las 15 el mismo precio salía escrito de dos formas.
 * Tampoco se usa la extensión `intl` de PHP, que no siempre está instalada y
 * trae su propia versión de ICU: sus datos no coinciden con los del navegador
 * (el guaraní sale como `Gs.`, y redondea el medio al par).
 *
 * `FormatoDeMonedaTest` y `utils/money.test.ts` comprueban los mismos casos
 * con el mismo resultado esperado.
 */
class Money
{
    /** El espacio que no se parte: el símbolo no queda solo al final de una línea. */
    private const ESPACIO = "\u{A0}";

    public static function format(float|string $amount, ?string $currency): string
    {
        $currency = strtoupper($currency ?: 'USD');
        $config = config("currencies.{$currency}");
        $valor = (float) $amount;

        // Moneda desconocida (p. ej. una fila vieja o una quitada de la lista):
        // se muestra el codigo delante en vez de inventarse un simbolo.
        if (! $config) {
            return $currency.' '.number_format($valor, 2);
        }

        $numero = number_format(abs($valor), $config['decimals'], $config['decimal'], $config['miles']);

        // El signo va delante de todo, en todas las monedas —`Intl` lo ponía en
        // sitios distintos según el país: `$-5`, `₲ -5`, `-$ 5`—, y un cero
        // redondeado (-0,4 en pesos chilenos) no lo lleva.
        $signo = $valor < 0 && preg_match('/[1-9]/', $numero) ? '-' : '';

        return $signo.strtr($config['patron'], [
            '¤' => $config['symbol'],
            '#' => $numero,
            ' ' => self::ESPACIO,
        ]);
    }
}
