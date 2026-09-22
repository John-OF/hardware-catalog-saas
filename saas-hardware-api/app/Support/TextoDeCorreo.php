<?php

namespace App\Support;

/**
 * Escapa el Markdown de un dato antes de meterlo en la línea de un correo
 * (`SEC-8`).
 *
 * **El problema.** Las líneas de `MailMessage` se renderizan como Markdown. El
 * HTML sí se escapa por el camino —`{{ $line }}` en la plantilla de Laravel—,
 * así que `<script>` llega como texto y no hay XSS. Pero el Markdown se
 * interpreta entero, y `[Verifica tu cuenta](http://malo)` sale como un enlace
 * de verdad. `NewOrderNotification` interpola el nombre, el teléfono, la nota y
 * la entrega del checkout —público, sin autenticación— en un correo que recibe
 * el dueño, enviado desde el servidor de la propia tienda con su SPF y su DKIM
 * en regla: un correo de phishing dentro de un aviso legítimo.
 *
 * **Por qué vive aquí y no en el constructor de cada notificación.** Es la misma
 * lección que `SEC-6`: el escapado pertenece al **contexto de salida**, no al
 * dato. El dato es correcto; lo que hay que escapar es su viaje a un renderizador
 * de Markdown. Escapar al construir ensuciaría también el canal de base de datos
 * y cualquier otro que se añada después, que no son Markdown.
 *
 * **Lo que NO se escapa, a propósito:**
 * - `<`, `>` y `&`: la plantilla de Laravel los convierte en entidades HTML
 *   antes de que CommonMark los vea, así que un `>` no llega nunca a ser una
 *   cita y un `<http://x>` no llega nunca a ser un autoenlace. Escaparlos aquí
 *   sólo añadiría una barra invertida visible en la versión de texto plano.
 * - `!`, `(` y `)`: sólo significan algo pegados a un `[` o a un `]`, que sí se
 *   escapan. Dejarlos en paz es lo que permite que un teléfono `+51 (999)` se
 *   lea limpio.
 *
 * **El coste conocido:** en la **versión de texto plano** del correo —la que
 * usan los clientes que no pintan HTML— las barras invertidas sí se ven. Por eso
 * el juego de caracteres es el mínimo que mata la inyección y no el juego
 * completo de puntuación de CommonMark: un nombre o un teléfono normales no
 * llevan ninguno de estos seis.
 */
final class TextoDeCorreo
{
    /**
     * Los que crean estructura en cualquier posición de la línea.
     *
     * La barra invertida va **primera**: escapa las demás, y si se procesara al
     * final se comería las barras que acabamos de poner.
     */
    private const EN_LINEA = ['\\', '`', '*', '_', '[', ']'];

    /**
     * Los que sólo significan algo al principio de una línea: título (`#`),
     * línea horizontal y título subrayado (`-`, `=`), lista (`+`) y tabla (`|`).
     */
    private const AL_EMPEZAR = ['#', '-', '=', '+', '|'];

    /**
     * Un valor de una sola línea: un nombre, un teléfono, un producto, el nombre
     * de una tienda.
     *
     * Aplasta los saltos de línea además de escapar, y esto es **cinturón, no el
     * arreglo**: se comprobó que hoy un salto dentro de un `->line()` no llega a
     * forjar nada —la plantilla de Laravel junta las líneas antes de que
     * CommonMark las vea, así que un `# Titulo` en la segunda sale como texto—.
     * Se aplastan igual porque esa garantía es de la plantilla y no nuestra: el
     * día que Laravel cambie de plantilla, o que alguien publique una propia que
     * respete los saltos, un `customer_name` —que es `string|max:150`, y un
     * `string` admite saltos— podría abrir una línea y escribir un título en
     * ella. Y de paso un nombre de tres líneas no descuadra el correo.
     */
    public static function enLinea(?string $texto): string
    {
        $texto = preg_replace('/\s+/u', ' ', (string) $texto) ?? '';

        return self::escaparEnLinea(trim($texto));
    }

    /**
     * Un valor que puede traer varias líneas de verdad: la nota del cliente.
     *
     * Conserva los saltos, así que escapa además lo que sólo cuenta al empezar
     * una línea —título, raya, lista, tabla— y recorta los cuatro espacios que
     * serían un bloque de código. Por lo mismo que arriba: hoy la plantilla de
     * Laravel junta las líneas y nada de eso llega a cuajar, y se escapa igual
     * para no depender de que siga haciéndolo.
     */
    public static function enParrafo(?string $texto): string
    {
        $lineas = preg_split('/\R/u', (string) $texto) ?: [];

        $escapadas = array_map(function (string $linea): string {
            $linea = self::escaparEnLinea(trim($linea));

            if ($linea !== '' && in_array($linea[0], self::AL_EMPEZAR, true)) {
                return '\\'.$linea;
            }

            return $linea;
        }, $lineas);

        return trim(implode("\n", $escapadas));
    }

    private static function escaparEnLinea(string $texto): string
    {
        foreach (self::EN_LINEA as $caracter) {
            $texto = str_replace($caracter, '\\'.$caracter, $texto);
        }

        return $texto;
    }
}
