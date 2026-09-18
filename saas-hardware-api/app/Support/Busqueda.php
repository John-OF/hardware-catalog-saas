<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * La búsqueda de productos, la misma en los tres sitios que la ofrecen (`INF-6`).
 *
 * Sigue siendo `LIKE '%trozo%'` y eso es deliberado: la mitad de lo que busca un
 * comprador de componentes es un fragmento —"7600" dentro de "Ryzen 5 7600X",
 * "b550" dentro de un SKU— y `MATCH ... AGAINST` busca palabras enteras, así que
 * un índice más rápido devolvería menos (ver `AUD-21` en la auditoría). Lo que
 * cambia aquí no es el motor sino **qué se pregunta**:
 *
 * 1. **Cada palabra por separado, y todas tienen que aparecer.** Antes el término
 *    entero era un solo `LIKE`, así que "ryzen 7600" no encontraba "Ryzen 5
 *    7600X": entre las dos palabras hay un "5" y la cadena no casaba. Ahora se
 *    parte por espacios y cada palabra se busca en todas las columnas; el
 *    producto sale si las cumple todas. Es el fallo que más se nota, porque
 *    escribir marca y modelo juntos es lo normal.
 * 2. **Siempre las mismas columnas**: nombre, marca, SKU de la ficha y SKU de
 *    cualquiera de sus variantes (`MOD-5`). El panel miraba nombre y SKU, la
 *    exportación solo nombre y SKU, y el catálogo público los cuatro: tres
 *    resultados distintos para la misma palabra, y una exportación que no traía
 *    lo que el dueño veía en pantalla.
 * 3. **`%` y `_` dejan de ser comodines.** Escritos por el visitante eran
 *    comodines de SQL: buscar "50%" devolvía el catálogo entero.
 *
 * Lo que **no** arregla, y sigue necesitando un índice de búsqueda de verdad
 * (Meilisearch/Typesense vía Scout): las erratas. "riyzen" no encuentra nada, y
 * ninguna cantidad de `LIKE` lo va a encontrar.
 */
class Busqueda
{
    /**
     * Cuántas palabras se miran. Cada una añade una condición y una subconsulta
     * sobre las variantes, así que el tope es lo que impide que una frase pegada
     * desde ninguna parte convierta una búsqueda en veinte subconsultas. Seis
     * sobran: "asus rog strix b550-f gaming wifi" son seis.
     */
    public const MAX_TERMINOS = 6;

    /** Lo que cabe en un término. Más largo que el nombre más largo no busca nada. */
    private const MAX_LARGO = 60;

    /**
     * El carácter de escape del `LIKE`.
     *
     * No es `\` a propósito: en MySQL el backslash es además el escape de la
     * cadena, así que habría que escribirlo doble y a partir de ahí cada capa
     * (PDO, la cadena de PHP) se lleva uno. Un signo que no aparece en un SKU
     * evita toda esa discusión. Va explícito con `ESCAPE` porque SQLite —donde
     * corre la suite— no tiene carácter de escape por defecto y MySQL sí: sin
     * declararlo, los dos motores no harían lo mismo.
     */
    private const ESCAPE = '!';

    /** Dónde se busca. Nunca llega del exterior: son nombres de columna fijos. */
    private const COLUMNAS = ['name', 'brand', 'sku'];

    /**
     * Las palabras de una búsqueda, ya limpias.
     *
     * @return array<int, string>
     */
    public static function terminos(?string $busqueda): array
    {
        $palabras = preg_split('/\s+/u', trim((string) $busqueda), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(
            array_map(fn (string $palabra) => mb_substr($palabra, 0, self::MAX_LARGO), $palabras),
            0,
            self::MAX_TERMINOS,
        );
    }

    /**
     * Filtra la consulta por la búsqueda. Sin término no toca nada.
     *
     * Los `OR` de cada palabra van agrupados en su propio closure a propósito:
     * sueltos se mezclarían con los `where` de tienda y de publicación, y la
     * búsqueda acabaría enseñando productos de otras tiendas o despublicados.
     *
     * @param  Builder<\App\Models\Product>  $consulta
     */
    public static function aplicar(Builder $consulta, ?string $busqueda): void
    {
        foreach (self::terminos($busqueda) as $termino) {
            $patron = self::patron($termino);

            $consulta->where(function (Builder $grupo) use ($patron) {
                foreach (self::COLUMNAS as $columna) {
                    $grupo->orWhereRaw(self::comparacion($columna), [$patron]);
                }

                // MOD-5: con variantes, el SKU que se busca suele ser el de una de
                // ellas ("KF432C16BB/16"), que no está en la ficha.
                $grupo->orWhereHas('variants', fn ($v) => $v->whereRaw(self::comparacion('sku'), [$patron]));
            });
        }
    }

    /**
     * Ordena por lo cerca que queda cada producto de lo que se escribió.
     *
     * Sin esto, buscar "kingston" en una tienda con cincuenta productos de esa
     * marca devolvía primero lo que el dueño hubiera arrastrado arriba, que con
     * una búsqueda puesta no significa nada. Se mira el **nombre** contra la
     * búsqueda entera: el que se llama exactamente así, el que empieza por ahí,
     * el que la lleva dentro, y el resto —los que salieron por marca, por SKU o
     * por juntar varias palabras—.
     *
     * Es un desempate, no un filtro: no quita ni añade ningún producto. Por eso
     * `LOWER()` basta aunque en SQLite solo baje ASCII.
     *
     * @param  Builder<\App\Models\Product>  $consulta
     */
    public static function ordenarPorRelevancia(Builder $consulta, ?string $busqueda): void
    {
        $termino = trim((string) $busqueda);

        if ($termino === '') {
            return;
        }

        $termino = mb_substr($termino, 0, self::MAX_LARGO);
        $escapado = self::escapar($termino);
        $escape = self::ESCAPE;

        $consulta->orderByRaw(
            "CASE
                WHEN LOWER(name) = LOWER(?) THEN 0
                WHEN name LIKE ? ESCAPE '{$escape}' THEN 1
                WHEN name LIKE ? ESCAPE '{$escape}' THEN 2
                ELSE 3
            END",
            [$termino, $escapado.'%', '%'.$escapado.'%'],
        );
    }

    private static function comparacion(string $columna): string
    {
        return "{$columna} LIKE ? ESCAPE '".self::ESCAPE."'";
    }

    private static function patron(string $termino): string
    {
        return '%'.self::escapar($termino).'%';
    }

    /**
     * Deja `%`, `_` y el propio escape como texto y no como comodín. El escape va
     * primero, o se escaparían los signos que acaba de poner esta función.
     */
    private static function escapar(string $termino): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $termino,
        );
    }
}
