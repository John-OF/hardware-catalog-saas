<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Los métodos de pago que arma cada tienda (MOD-23).
 *
 * Hasta el 2026-11-05 eran cuatro fijos —Yape, Plin, transferencia y efectivo—,
 * y dos de ellos solo existen en Perú: una tienda de Ecuador (Deuna), Colombia
 * (Nequi) o Venezuela (Pago Móvil) solo podía poner «transferencia». Ahora son
 * una lista que escribe la tienda, en `tenants.payment_methods`:
 *
 *     [{"id": "k3j9x2", "name": "Yape", "details": "987654321 · Ana",
 *       "instructions": "Manda la captura por WhatsApp", "qr_url": "pagos/…png",
 *       "enabled": true}]
 *
 * **Sigue sin ser una pasarela.** Es lo que se le enseña al comprador para que
 * sepa cómo pagar; nada de esto cobra.
 *
 * Todo lo que sabe de esa forma vive aquí: el cast (`MetodosDePagoDeTienda`) la
 * normaliza al leer y al guardar, y por eso lo de antes —el objeto con los
 * cuatro fijos— se convierte solo, aunque la migración que lo reescribe no
 * haya corrido.
 */
class MetodosDePago
{
    /** Cuántos puede tener una tienda. No es del plan: es un techo para el formulario. */
    public const MAXIMO = 8;

    /** Las claves del formato de antes, en el orden en que se enseñaban. */
    private const ANTIGUOS = ['yape', 'plin', 'transferencia', 'efectivo'];

    /**
     * Lo guardado, siempre como lista con la forma de arriba. Acepta el formato
     * de antes y tira lo que no es un método (sin nombre o que no es un array).
     *
     * @return array<int, array{id: string, name: string, details: ?string, instructions: ?string, qr_url: ?string, enabled: bool}>
     */
    public static function normalizar(mixed $guardado): array
    {
        if (! is_array($guardado) || $guardado === []) {
            return [];
        }

        if (! array_is_list($guardado)) {
            $guardado = self::desdeFormatoAntiguo($guardado);
        }

        $lista = [];

        foreach ($guardado as $metodo) {
            if (! is_array($metodo)) {
                continue;
            }

            $nombre = trim((string) ($metodo['name'] ?? ''));

            if ($nombre === '') {
                continue;
            }

            $lista[] = [
                'id'           => self::idValido($metodo['id'] ?? null) ?? self::nuevoId(),
                'name'         => $nombre,
                'details'      => self::textoOpcional($metodo['details'] ?? null),
                'instructions' => self::textoOpcional($metodo['instructions'] ?? null),
                'qr_url'       => self::textoOpcional($metodo['qr_url'] ?? null),
                'enabled'      => filter_var($metodo['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return array_slice($lista, 0, self::MAXIMO);
    }

    /**
     * Los que ve el comprador: encendidos. Mostrar uno apagado a medio llenar es
     * peor que no mostrarlo.
     *
     * @param  array<int, array<string, mixed>>  $lista  ya normalizada
     * @return array<int, array<string, mixed>>
     */
    public static function activos(array $lista): array
    {
        return array_values(array_filter($lista, fn (array $m) => $m['enabled'] ?? false));
    }

    /**
     * Los cuatro fijos de antes, como lista. Se conservan también los apagados,
     * con `enabled` en false: la tienda escribió esos datos y no hay por qué
     * perderlos. Los campos sueltos (banco, número, titular) se juntan en el
     * texto que ahora escribe la tienda, en el orden en que se leen.
     *
     * @param  array<string, mixed>  $viejo
     * @return array<int, array<string, mixed>>
     */
    public static function desdeFormatoAntiguo(array $viejo): array
    {
        $lista = [];

        foreach (self::ANTIGUOS as $clave) {
            $datos = $viejo[$clave] ?? null;

            if (! is_array($datos)) {
                continue;
            }

            [$nombre, $detalle] = match ($clave) {
                'yape' => ['Yape', self::juntar([$datos['phone'] ?? null, $datos['holder_name'] ?? null])],
                'plin' => ['Plin', self::juntar([$datos['phone'] ?? null, $datos['holder_name'] ?? null])],
                'transferencia' => ['Transferencia bancaria', self::juntar([
                    $datos['bank'] ?? null,
                    self::tipoDeCuenta($datos['account_type'] ?? null, $datos['account_number'] ?? null),
                    filled($datos['cci'] ?? null) ? 'CCI '.trim($datos['cci']) : null,
                    $datos['holder_name'] ?? null,
                ])],
                'efectivo' => ['Efectivo contra entrega', null],
            };

            $lista[] = [
                'id'           => $clave,
                'name'         => $nombre,
                'details'      => $detalle,
                'instructions' => null,
                'qr_url'       => null,
                'enabled'      => filter_var($datos['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $lista;
    }

    /** Un identificador corto y sin caracteres raros, para seguir el mismo método entre guardados. */
    public static function nuevoId(): string
    {
        return Str::lower(Str::random(10));
    }

    private static function idValido(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[a-z0-9_-]{1,20}$/i', $id) ? $id : null;
    }

    private static function textoOpcional(mixed $valor): ?string
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        return $texto === '' ? null : $texto;
    }

    /** @param  array<int, mixed>  $partes */
    private static function juntar(array $partes): ?string
    {
        $texto = implode(' · ', array_filter(array_map(fn ($p) => is_scalar($p) ? trim((string) $p) : '', $partes)));

        return $texto === '' ? null : $texto;
    }

    private static function tipoDeCuenta(mixed $tipo, mixed $numero): ?string
    {
        if (! filled($numero)) {
            return null;
        }

        $cuenta = match ($tipo) {
            'corriente' => 'Cuenta corriente',
            default => 'Cuenta de ahorros',
        };

        return $cuenta.' '.trim((string) $numero);
    }
}
