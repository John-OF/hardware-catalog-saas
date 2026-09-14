<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Cuantas filas devuelve un listado paginado (TEC-12).
 *
 * Los listados del panel y de plataforma leian `per_page` tal cual con
 * `$request->integer('per_page', N)`, asi que `per_page=100000` devolvia la
 * tabla entera en una sola respuesta. Solo la bitacora de plataforma lo
 * limitaba, con su propio `min(max(...))`. Aqui se decide una vez para todos,
 * para que un listado nuevo no nazca sin tope.
 *
 * No expone datos ajenos -cada listado ya ve solo lo suyo-: es poner techo a una
 * respuesta enorme pedida por alguien que ya tiene acceso.
 */
class Paginacion
{
    /** Ningun listado devuelve mas filas por pagina que esto. */
    public const MAXIMO = 100;

    /**
     * Un valor que no es un numero positivo (`0`, `-5`, `abc`) cae al valor por
     * defecto del listado, no a 1: quien lo manda no pidio "una fila".
     */
    public static function porPagina(Request $request, int $porDefecto): int
    {
        $pedido = $request->integer('per_page', $porDefecto);

        if ($pedido < 1) {
            return $porDefecto;
        }

        return min($pedido, self::MAXIMO);
    }
}
