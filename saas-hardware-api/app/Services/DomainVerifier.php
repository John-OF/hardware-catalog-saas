<?php

namespace App\Services;

/**
 * Comprueba si el dueño de un dominio de verdad puso el registro TXT que le
 * pedimos (FUN-6).
 *
 * Envuelto en una clase, y no una llamada suelta a `dns_get_record()` en el
 * controlador, para que la suite pueda sustituirlo por un doble: una consulta
 * DNS de verdad en los tests seria lenta, dependeria de tener red y de
 * dominios que no controlamos, y no se podria fijar la respuesta para probar
 * los dos caminos (verificado / no verificado) de forma determinista.
 */
class DomainVerifier
{
    /**
     * `true` si el host tiene un registro TXT con exactamente ese valor.
     *
     * `@` porque una consulta a un host que no existe (dominio mal escrito, o
     * que aun no propago el DNS) lanza un warning de PHP, no una excepcion; ese
     * caso es exactamente "todavia no", no un error que deba tumbar la
     * peticion.
     */
    public function tieneRegistroTxt(string $host, string $valorEsperado): bool
    {
        $registros = @dns_get_record($host, DNS_TXT) ?: [];

        foreach ($registros as $registro) {
            if (($registro['txt'] ?? null) === $valorEsperado) {
                return true;
            }
        }

        return false;
    }
}
