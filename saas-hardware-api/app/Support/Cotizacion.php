<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\ImageService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Lo que lleva la cotización en PDF de un pedido (MOD-2, FUN-26).
 *
 * Vive aquí y no en `QuoteController` para poder comprobar el texto del
 * documento renderizando la vista: el PDF va comprimido y no se puede buscar en
 * él. Los importes llegan ya formateados (`App\Support\Money`), para que la
 * moneda de la tienda se escriba igual aquí que en el panel.
 *
 * FUN-26 añadió lo que una cotización necesita para mandarse a un cliente que
 * compara presupuestos: **quién la emite** (la persona del equipo que la
 * descarga), **hasta cuándo vale** (hoy + los días de la tienda), los datos
 * fiscales de la tienda (los de su pie de página, PERS-7), sus **condiciones** y
 * **cómo pagar** (los métodos encendidos, `MOD-23`). Sigue sin ser un
 * comprobante fiscal (`MOD-14`), y lo lleva impreso.
 *
 * @return array<string, mixed>
 */
class Cotizacion
{
    public static function datos(Order $pedido, Tenant $tienda, ?Authenticatable $quien, ImageService $imagenes): array
    {
        $pedido->loadMissing('items');

        $moneda = $tienda->currency;
        $zona = $tienda->zonaHoraria();
        $hoy = now($zona);
        $tema = $tienda->theme ?? [];

        return [
            'pedido'    => $pedido,
            'tienda'    => $tienda,
            // FUN-20: empotrado, no una URL que dompdf no va a descargar.
            'logo'      => $imagenes->logoEmpotrable($tienda),
            'titulo'    => 'Cotización',
            // El correlativo de la tienda (FUN-3), que es el número que el dueño
            // y el cliente se dicen por teléfono. No se inventa una serie aparte:
            // sería un segundo número para la misma operación.
            'numero'    => 'N.° '.str_pad((string) $pedido->number, 6, '0', STR_PAD_LEFT),
            // En la zona de la tienda (MOD-13): la fecha de un documento suyo no
            // puede depender de dónde esté el servidor.
            'fecha'     => $pedido->created_at->timezone($zona)->format('d/m/Y H:i'),
            'color'     => $tienda->primary_color ?: '#111827',
            'urlTienda' => StoreUrl::forTenant($tienda),
            'importes'  => [
                'lineas' => $pedido->items->map(fn ($linea) => [
                    'unitario' => Money::format($linea->unit_price, $moneda),
                    'subtotal' => Money::format($linea->subtotal, $moneda),
                ])->all(),
                'envio'     => Money::format($pedido->delivery_cost ?? 0, $moneda),
                // MOD-4: el subtotal de los productos antes de descontar, y lo
                // descontado. `items_subtotal` es null en los pedidos de antes.
                'subtotal'  => Money::format($pedido->items_subtotal ?? $pedido->total, $moneda),
                'descuento' => Money::format($pedido->discount_amount ?? 0, $moneda),
                'base'      => Money::format($pedido->base_imponible, $moneda),
                'impuesto'  => Money::format($pedido->tax_amount ?? 0, $moneda),
                'total'     => Money::format($pedido->total, $moneda),
            ],

            // FUN-26: los datos fiscales que la tienda ya escribe para su pie.
            'idFiscal'    => self::texto($tema['footer_tax_id'] ?? null),
            'direccion'   => self::texto($tema['footer_address'] ?? null),
            // Cuándo se emite y quién: la cotización es de hoy aunque el pedido
            // sea de ayer, y la validez cuenta desde hoy.
            'emitida'     => $hoy->format('d/m/Y'),
            'emitidaPor'  => self::texto($quien?->name ?? null),
            'validaHasta' => $tienda->quote_validity_days
                ? $hoy->copy()->addDays($tienda->quote_validity_days)->format('d/m/Y')
                : null,
            'condiciones' => self::texto($tienda->quote_terms),
            'pagos'       => array_map(
                fn (array $m) => trim($m['name'].($m['details'] ? ' · '.$m['details'] : '')),
                $tienda->metodosDePagoActivos(),
            ),
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : $texto;
    }
}
