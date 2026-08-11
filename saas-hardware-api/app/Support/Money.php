<?php

namespace App\Support;

/**
 * Formato de importes del lado del servidor (OWN-1).
 *
 * Lo usan los correos, que son el unico sitio del backend donde se le enseña un
 * precio a una persona. El formato "de verdad" de la tienda lo hace el frontend
 * con Intl.NumberFormat (`src/utils/money.ts`); aqui se hace a mano a proposito,
 * sin depender de la extension `intl` de PHP, que no siempre esta instalada.
 */
class Money
{
    public static function format(float|string $amount, ?string $currency): string
    {
        $currency = strtoupper($currency ?: 'USD');
        $config = config("currencies.{$currency}");

        // Moneda desconocida (p. ej. una fila vieja o una quitada de la lista):
        // se muestra el codigo delante en vez de inventarse un simbolo.
        if (! $config) {
            return $currency.' '.number_format((float) $amount, 2);
        }

        return $config['symbol'].number_format((float) $amount, $config['decimals']);
    }
}
