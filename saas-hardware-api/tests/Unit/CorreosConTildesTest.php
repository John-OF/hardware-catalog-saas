<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Los correos van en español escrito como se escribe: con tildes y eñes.
 *
 * Se colaron sin ellas en seis correos («Ya llego», «escribenos», «contrasenia»,
 * «tu catalogo no es visible para el publico»), casi todos a compradores, que
 * son quienes no conocen la tienda y juzgan por cómo escribe. Aquí se leen las
 * cadenas de `app/Notifications` con el tokenizador de PHP —solo las cadenas,
 * no los comentarios, que en este proyecto van a menudo sin tildes— y se busca
 * cada palabra que ya se escapó alguna vez. No es un corrector: es la lista de
 * lo que ya pasó, para que no vuelva.
 */
class CorreosConTildesTest extends TestCase
{
    /** Palabra sin tilde => cómo se escribe. */
    private const SIN_TILDE = [
        'llego' => 'llegó',
        'volvio' => 'volvió',
        'escribenos' => 'escríbenos',
        'respondenos' => 'respóndenos',
        'avisaramos' => 'avisáramos',
        'aqui' => 'aquí',
        'mas' => 'más',
        'numero' => 'número',
        'telefono' => 'teléfono',
        'contrasenia' => 'contraseña',
        'contrasena' => 'contraseña',
        'administracion' => 'administración',
        'preparacion' => 'preparación',
        'catalogo' => 'catálogo',
        'publico' => 'público',
        'categorias' => 'categorías',
        'personalizacion' => 'personalización',
        'dias' => 'días',
        'pagina' => 'página',
        'informacion' => 'información',
        'verificacion' => 'verificación',
        'invitacion' => 'invitación',
    ];

    public function test_las_cadenas_de_los_correos_llevan_tildes(): void
    {
        $archivos = glob(dirname(__DIR__, 2).'/app/Notifications/*.php');
        $this->assertNotEmpty($archivos);

        $fallos = [];

        foreach ($archivos as $archivo) {
            foreach (token_get_all((string) file_get_contents($archivo)) as $token) {
                if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                // Una clave o un nombre de ruta ('telefono', 'verificacion.correo')
                // es código, y el código va sin tildes a propósito.
                if (preg_match('/^[\'"]?[a-z0-9_.\-]+[\'"]?$/', $token[1])) {
                    continue;
                }

                foreach (self::SIN_TILDE as $mal => $bien) {
                    if (preg_match('/(?<![\p{L}_])'.$mal.'(?![\p{L}_])/iu', $token[1])) {
                        $fallos[] = basename($archivo).":{$token[2]}: «{$mal}» → «{$bien}»";
                    }
                }
            }
        }

        $this->assertSame([], $fallos, "Palabras sin tilde en los correos:\n".implode("\n", $fallos));
    }
}
