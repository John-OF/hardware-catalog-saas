<?php

namespace App\Support;

use App\Enums\ComponentType;

/**
 * Las specs con nombre fijo por tipo de componente (FUN-8, Fase 13).
 *
 * La lista vive en `config/component_specs.php`; aqui esta todo lo que se hace
 * con ella, para que el formulario del panel, el import CSV y la vista para
 * buscadores lean la misma definicion en vez de copiarla:
 *
 *  - `normalizar()` acepta lo que una persona escribiria ("am5", "105 W", "Sí")
 *    y lo deja en la forma guardada ("AM5", "105", "si"). Lo que no reconoce lo
 *    deja TAL CUAL, para que `errores()` lo vea y lo diga; nunca lo borra en
 *    silencio, que es el fallo que esta fase viene a cerrar.
 *  - `errores()` dice que valor fijo no vale, con un mensaje que nombra el campo.
 *  - `paraMostrar()` pone etiqueta y unidad para pintarlas.
 *
 * Lo que NO hace: decidir que falta. Un campo fijo ausente no es un error
 * —nunca impide guardar un producto—; es el armador (13.5) quien dira "no
 * comprobado". Y las claves que no son fijas del tipo pasan sin tocar: siguen
 * siendo specs libres.
 */
final class SpecsFijas
{
    /** El valor mas largo que se admite en un campo de texto libre. */
    private const MAX_TEXTO = 255;

    /** Cifras como mucho de un numero: 999999 mm o W ya no es una pieza de PC. */
    private const MAX_CIFRAS = 6;

    /**
     * Los campos de un tipo, en orden y con las opciones ya resueltas.
     *
     * @return array<string, array{etiqueta: string, tipo: string, unidad: ?string, opciones: list<string>, armador: bool, ayuda: ?string}>
     */
    public static function camposDe(ComponentType|string|null $tipo): array
    {
        $clave = $tipo instanceof ComponentType ? $tipo->value : $tipo;

        if ($clave === null) {
            return [];
        }

        $listas = config('component_specs.listas', []);

        return collect(config("component_specs.tipos.{$clave}", []))
            ->map(fn (array $campo) => [
                'etiqueta' => $campo['etiqueta'],
                'tipo'     => $campo['tipo'],
                'unidad'   => $campo['unidad'] ?? null,
                'opciones' => isset($campo['opciones']) ? array_values($listas[$campo['opciones']] ?? []) : [],
                'armador'  => (bool) ($campo['armador'] ?? false),
                'ayuda'    => $campo['ayuda'] ?? null,
            ])
            ->all();
    }

    /**
     * Todas las claves fijas de todos los tipos, en el orden en que aparecen.
     *
     * Para pintar: la ficha publica no sabe de que tipo es el producto, y no le
     * hace falta, porque una clave repetida entre tipos tiene la misma etiqueta
     * y unidad en todos (lo fija `SpecsFijasTest`).
     *
     * @return array<string, array{etiqueta: string, tipo: string, unidad: ?string, opciones: list<string>, armador: bool, ayuda: ?string}>
     */
    public static function todas(): array
    {
        $todas = [];

        foreach (array_keys(config('component_specs.tipos', [])) as $tipo) {
            $todas += self::camposDe($tipo);
        }

        return $todas;
    }

    /**
     * La definicion entera para el frontend: por tipo, cada campo con su clave.
     *
     * @return array{tipos: array<string, list<array<string, mixed>>>}
     */
    public static function paraApi(): array
    {
        $tipos = [];

        foreach (array_keys(config('component_specs.tipos', [])) as $tipo) {
            $tipos[$tipo] = collect(self::camposDe($tipo))
                ->map(fn (array $campo, string $clave) => ['clave' => $clave, ...$campo])
                ->values()
                ->all();
        }

        return ['tipos' => $tipos];
    }

    /**
     * Deja los campos fijos del tipo en la forma en que se guardan.
     *
     * Un campo fijo vacio se quita (como hace el formulario con una spec libre
     * vacia). Las claves que no son fijas de este tipo no se tocan.
     */
    public static function normalizar(array $specs, ComponentType|string|null $tipo): array
    {
        foreach (self::camposDe($tipo) as $clave => $campo) {
            if (! array_key_exists($clave, $specs)) {
                continue;
            }

            $valor = $specs[$clave];

            if ($valor === null || (is_string($valor) && trim($valor) === '') || $valor === []) {
                unset($specs[$clave]);

                continue;
            }

            $specs[$clave] = self::normalizarValor($valor, $campo);
        }

        return $specs;
    }

    /**
     * Que campo fijo trae un valor que no vale, con el mensaje para la persona.
     *
     * Se llama con las specs YA normalizadas: aqui solo se compara contra la
     * forma guardada.
     *
     * @return array<string, string> clave => mensaje
     */
    public static function errores(array $specs, ComponentType|string|null $tipo): array
    {
        $errores = [];

        foreach (self::camposDe($tipo) as $clave => $campo) {
            if (array_key_exists($clave, $specs) && ! self::esValido($specs[$clave], $campo)) {
                $errores[$clave] = self::mensaje($specs[$clave], $campo);
            }
        }

        return $errores;
    }

    /**
     * Las specs listas para pintar: primero las fijas, en el orden de la
     * definicion y con su etiqueta y unidad; despues las libres, como se
     * guardaron.
     *
     * @return list<array{clave: string, etiqueta: string, valor: string}>
     */
    public static function paraMostrar(?array $specs): array
    {
        if (! $specs) {
            return [];
        }

        $fijas = [];
        $libres = [];
        $todas = self::todas();

        foreach ($todas as $clave => $campo) {
            if (array_key_exists($clave, $specs)) {
                $fijas[] = ['clave' => $clave, 'etiqueta' => $campo['etiqueta'], 'valor' => self::valorParaMostrar($specs[$clave], $campo)];
            }
        }

        foreach ($specs as $clave => $valor) {
            if (! isset($todas[$clave])) {
                $libres[] = ['clave' => (string) $clave, 'etiqueta' => (string) $clave, 'valor' => self::comoTexto($valor)];
            }
        }

        return [...$fijas, ...$libres];
    }

    private static function normalizarValor(mixed $valor, array $campo): mixed
    {
        return match ($campo['tipo']) {
            'texto'  => is_string($valor) ? trim($valor) : $valor,
            'numero' => self::normalizarNumero($valor, $campo['unidad']),
            'si_no'  => self::normalizarSiNo($valor),
            'lista'  => self::normalizarOpcion($valor, $campo) ?? $valor,
            'varios' => self::normalizarVarias($valor, $campo),
            default  => $valor,
        };
    }

    private static function normalizarNumero(mixed $valor, ?string $unidad): mixed
    {
        if (is_int($valor)) {
            return $valor >= 0 ? (string) $valor : $valor;
        }

        if (! is_string($valor)) {
            return $valor;
        }

        $texto = self::sinUnidad(trim($valor), $unidad);

        // `(string) (int)` quita los ceros de delante: "0105" y "105" son el
        // mismo dato y no pueden salir como dos valores en los filtros.
        return preg_match('/^\d{1,'.self::MAX_CIFRAS.'}$/', $texto) ? (string) (int) $texto : $valor;
    }

    private static function normalizarSiNo(mixed $valor): mixed
    {
        if (is_bool($valor)) {
            return $valor ? 'si' : 'no';
        }

        if (! is_string($valor) && ! is_int($valor)) {
            return $valor;
        }

        return match (self::clave((string) $valor)) {
            'si', 'yes', 'true', '1' => 'si',
            'no', 'false', '0'       => 'no',
            default                  => $valor,
        };
    }

    /** La opcion de la lista que es este valor escrito de otra forma, o null. */
    private static function normalizarOpcion(mixed $valor, array $campo): ?string
    {
        if (! is_string($valor) && ! is_int($valor)) {
            return null;
        }

        $buscada = self::clave(self::sinUnidad(trim((string) $valor), $campo['unidad']));

        foreach ($campo['opciones'] as $opcion) {
            if (self::clave($opcion) === $buscada) {
                return $opcion;
            }
        }

        return null;
    }

    /**
     * "am5, AM4" o ['AM4', 'am5'] → "AM4, AM5": sin repetidas y en el orden de
     * la lista, para que el mismo conjunto se guarde siempre igual.
     */
    private static function normalizarVarias(mixed $valor, array $campo): mixed
    {
        $partes = is_array($valor) ? $valor : (is_string($valor) ? explode(',', $valor) : null);

        if ($partes === null) {
            return $valor;
        }

        $elegidas = [];

        foreach ($partes as $parte) {
            if (is_string($parte) && trim($parte) === '') {
                continue;
            }

            $opcion = self::normalizarOpcion($parte, $campo);

            // Una sola que no se reconozca y se devuelve lo que llego: quitarla
            // en silencio guardaria un conjunto que nadie escribio.
            if ($opcion === null) {
                return $valor;
            }

            $elegidas[$opcion] = true;
        }

        $enOrden = array_values(array_filter($campo['opciones'], fn (string $o) => isset($elegidas[$o])));

        return implode(', ', $enOrden);
    }

    private static function esValido(mixed $valor, array $campo): bool
    {
        if (! is_string($valor)) {
            return false;
        }

        return match ($campo['tipo']) {
            'texto'  => mb_strlen($valor) <= self::MAX_TEXTO,
            'numero' => (bool) preg_match('/^\d{1,'.self::MAX_CIFRAS.'}$/', $valor),
            'si_no'  => in_array($valor, ['si', 'no'], true),
            'lista'  => in_array($valor, $campo['opciones'], true),
            'varios' => $valor !== '' && collect(explode(', ', $valor))->every(fn (string $o) => in_array($o, $campo['opciones'], true)),
            default  => false,
        };
    }

    private static function mensaje(mixed $valor, array $campo): string
    {
        $nombre = "«{$campo['etiqueta']}»";
        $recibido = is_scalar($valor) ? ' Llegó «'.self::comoTexto($valor).'».' : '';

        return match ($campo['tipo']) {
            'texto'  => "{$nombre} tiene que ser un texto de hasta ".self::MAX_TEXTO.' caracteres.',
            'numero' => "{$nombre} tiene que ser un número entero".($campo['unidad'] ? ", en {$campo['unidad']}" : '').'.'.$recibido,
            'si_no'  => "{$nombre} tiene que ser sí o no.".$recibido,
            'lista'  => "{$nombre} tiene que ser uno de estos: ".implode(', ', $campo['opciones']).'.'.$recibido,
            'varios' => "{$nombre} solo admite estos: ".implode(', ', $campo['opciones']).'.'.$recibido,
            default  => "{$nombre} no es válido.",
        };
    }

    private static function valorParaMostrar(mixed $valor, array $campo): string
    {
        $texto = self::comoTexto($valor);

        if ($campo['tipo'] === 'si_no') {
            return match ($texto) {
                'si'    => 'Sí',
                'no'    => 'No',
                default => $texto,
            };
        }

        // La unidad solo se pega a un valor que ya es el numero limpio: a uno
        // escrito a mano ("unos 105W") se le dejaria "unos 105W W".
        $conUnidad = in_array($campo['tipo'], ['numero', 'lista'], true)
            && $campo['unidad']
            && preg_match('/^\d+$/', $texto);

        return $conUnidad ? "{$texto} {$campo['unidad']}" : $texto;
    }

    private static function comoTexto(mixed $valor): string
    {
        if (is_array($valor)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $valor));
        }

        if (is_bool($valor)) {
            return $valor ? 'si' : 'no';
        }

        return is_scalar($valor) ? (string) $valor : '';
    }

    /** "105 W", "105W" y "105 w" → "105", si la unidad es la del campo. */
    private static function sinUnidad(string $texto, ?string $unidad): string
    {
        if (! $unidad) {
            return $texto;
        }

        return trim(preg_replace('/\s*'.preg_quote($unidad, '/').'$/iu', '', $texto) ?? $texto);
    }

    /**
     * Como se comparan dos formas de escribir lo mismo: sin mayusculas, tildes,
     * espacios ni guiones. "Micro ATX" es "Micro-ATX" y "Líquida (AIO)" es
     * "liquida (aio)". No traduce nombres distintos ("mATX"): eso es el
     * diccionario de 13.4.
     */
    private static function clave(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);

        return str_replace([' ', '-', '_'], '', $texto);
    }
}
