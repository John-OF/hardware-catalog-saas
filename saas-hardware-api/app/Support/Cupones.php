<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Aplicar un cupón a una venta (`MOD-4`).
 *
 * Vive aparte porque lo piden tres sitios —el checkout público, la venta de
 * mostrador y la comprobación del código desde el carrito, antes de confirmar— y
 * porque el orden de las operaciones importa y escrito tres veces acabaría
 * dando tres totales:
 *
 *     productos → descuento → + envío → impuesto → total
 *
 * **El descuento va antes del impuesto** a propósito. Al revés, el cupón
 * descontaría también de la parte que es del fisco, que la tienda paga igual: un
 * 25% acabaría costándole a la tienda más del 25%.
 *
 * **El envío queda fuera del descuento**, por lo mismo que queda fuera del
 * margen (`MOD-1`): lo que se le paga al repartidor no baja porque el comprador
 * tenga un código.
 */
class Cupones
{
    /**
     * Comprueba un código y devuelve cuánto descuenta, o explica por qué no.
     *
     * Sin código devuelve el cupón a `null` y descuento cero, que es el camino
     * normal: la mayoría de los pedidos no llevan ninguno.
     *
     * @return array{coupon: Coupon|null, discount: float}
     *
     * @throws ValidationException con un mensaje que el comprador puede leer
     */
    public static function resolver(Tenant $tenant, ?string $codigo, float $subtotalProductos): array
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            return ['coupon' => null, 'discount' => 0.0];
        }

        $cupon = Coupon::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->porCodigo($codigo)
            ->first();

        // "No existe" y "no es de esta tienda" dan el mismo mensaje: son lo
        // mismo desde fuera, y decir cuál de los dos es le confirmaría a quien
        // prueba códigos que ha acertado uno de otra tienda.
        if (! $cupon) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Ese código no existe.'],
            ]);
        }

        $motivo = $cupon->motivoParaRechazar($subtotalProductos);

        if ($motivo !== null) {
            throw ValidationException::withMessages(['coupon_code' => [$motivo]]);
        }

        return ['coupon' => $cupon, 'discount' => $cupon->descuentoSobre($subtotalProductos)];
    }

    /**
     * Sube el contador de usos, comprobando el tope con la fila bloqueada.
     *
     * **Se llama DENTRO de la transacción que crea el pedido**, y por eso vuelve
     * a mirar el tope aunque `resolver()` ya lo hubiera mirado: entre las dos
     * comprobaciones cabe otro comprador. Con `lockForUpdate`, dos pedidos
     * simultáneos sobre una campaña de 50 usos se ponen en fila en vez de leer
     * los dos 49 y entrar los dos.
     *
     * El uso **no se devuelve** si el pedido se cancela o se borra. Es
     * deliberado: devolverlo abriría pedir, cancelar y volver a pedir para
     * estirar una campaña limitada, y una campaña que se agota es justo lo que
     * el dueño quería que pasara.
     *
     * @throws ValidationException si el último hueco se lo llevó otro
     */
    public static function consumir(Coupon $cupon): void
    {
        $fresco = Coupon::withoutTenant()->lockForUpdate()->find($cupon->id);

        if (! $fresco) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Ese código ya no está disponible.'],
            ]);
        }

        if ($fresco->max_uses !== null && $fresco->used_count >= $fresco->max_uses) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Ese código ya se agotó.'],
            ]);
        }

        $fresco->increment('used_count');
    }

    /**
     * La expresión SQL que descuenta de una línea la parte que le tocó del cupón.
     *
     * El descuento se guarda en el pedido, no repartido por línea: `subtotal` es
     * el snapshot de precio × cantidad y reescribirlo mentiría sobre a cuánto se
     * vendió. Así que al medir el margen se reparte **en proporción**, que es lo
     * que hace que la suma de las líneas netas dé lo que de verdad cobró la
     * tienda.
     *
     * Sin descuento, o con `items_subtotal` a null —los pedidos de antes de
     * MOD-4—, devuelve la columna tal cual, así que los números viejos no se
     * mueven.
     *
     * Aritmética a secas, sin funciones de fecha ni de formato, para que valga
     * igual en MySQL y en SQLite (donde corre la suite). **El `* 1.0` no es
     * decorativo**: SQLite divide enteros como enteros, así que
     * `discount_amount / items_subtotal` con 200 y 1000 da `0` y no `0.2`,
     * mientras que MySQL devuelve decimal y acierta.
     *
     * Es la trampa de `MOD-13` **al revés, y por eso es peor**: allí la suite
     * pasaba y producción fallaba; aquí producción habría estado bien y la que
     * mentía era la suite. Un test que no puede distinguir una fórmula correcta
     * de una rota deja de servir para lo único que hace — y así se coló durante
     * `MOD-2`, cuyo `expresionNeta()` tenía el mismo `/` y acertaba solo porque
     * las cifras del test daban división exacta.
     */
    public static function expresionNeta(string $columna): string
    {
        return "(case
            when orders.discount_amount is null
              or orders.items_subtotal is null
              or orders.items_subtotal <= 0
            then {$columna}
            else {$columna} * (1 - (orders.discount_amount * 1.0 / orders.items_subtotal))
        end)";
    }
}
