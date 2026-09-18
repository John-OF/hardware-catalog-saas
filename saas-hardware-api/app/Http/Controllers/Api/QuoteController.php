<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Money;
use App\Support\StoreUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La cotización de un pedido, en PDF (`MOD-2`).
 *
 * **Por qué es una función de venta y no de contabilidad.** En este rubro se
 * cotiza mucho antes de vender: el comprador pide tres presupuestos por WhatsApp
 * y compara. Un PDF con el logo de la tienda, las líneas y el total compite; una
 * lista de precios pegada en el chat, no.
 *
 * **Lo que NO es, y está escrito también en el propio papel.** No es un
 * comprobante de pago electrónico. Para que lo fuera hay que firmarlo con un
 * certificado digital y declararlo ante la autoridad tributaria a través de un
 * proveedor autorizado, que es un proyecto entero y una decisión de proveedor —del
 * mismo tamaño que la pasarela de pago (`SAAS-3`)—. Un documento con pinta de
 * factura que no está declarada no es una función a medias: es un problema legal
 * para quien lo entrega, así que el aviso va impreso y no en la documentación.
 *
 * **Se genera en el servidor** (dompdf) y no imprimiendo desde el navegador, para
 * que salga igual en todas partes y para que mañana se pueda adjuntar a un
 * correo sin rehacerlo.
 *
 * Lo ven **admin y staff**: quien atiende el mostrador es justo quien cotiza.
 */
class QuoteController extends Controller
{
    public function __invoke(Request $request, Order $order): Response
    {
        $tenant = app('currentTenant');

        $order->load('items');

        $moneda = $tenant->currency;

        // Los importes se formatean aquí y no en la vista: la moneda de la tienda
        // se escribe con las mismas reglas que en el panel (`App\Support\Money`),
        // y una plantilla llena de llamadas a helpers es donde se cuela el `$`
        // fijo que OWN-1 ya tuvo que quitar una vez.
        $importes = [
            'lineas' => $order->items->map(fn ($linea) => [
                'unitario' => Money::format($linea->unit_price, $moneda),
                'subtotal' => Money::format($linea->subtotal, $moneda),
            ])->all(),
            'envio'    => Money::format($order->delivery_cost ?? 0, $moneda),
            // MOD-4: el subtotal de los productos antes de descontar, y lo
            // descontado. `items_subtotal` es null en los pedidos de antes.
            'subtotal'  => Money::format($order->items_subtotal ?? $order->total, $moneda),
            'descuento' => Money::format($order->discount_amount ?? 0, $moneda),
            'base'     => Money::format($order->base_imponible, $moneda),
            'impuesto' => Money::format($order->tax_amount ?? 0, $moneda),
            'total'    => Money::format($order->total, $moneda),
        ];

        $pdf = Pdf::loadView('cotizacion', [
            'pedido'    => $order,
            'tienda'    => $tenant,
            'titulo'    => 'Cotización',
            // El correlativo de la tienda (FUN-3), que es el número que el dueño
            // y el cliente se dicen por teléfono. No se inventa una serie aparte:
            // sería un segundo número para la misma operación.
            'numero'    => 'N.° '.str_pad((string) $order->number, 6, '0', STR_PAD_LEFT),
            // En la zona de la tienda (MOD-13): la fecha de un documento suyo no
            // puede depender de dónde esté el servidor.
            'fecha'     => $order->created_at->timezone($tenant->zonaHoraria())->format('d/m/Y H:i'),
            'color'     => $tenant->primary_color ?: '#111827',
            'urlTienda' => StoreUrl::forTenant($tenant),
            'importes'  => $importes,
        ]);

        // `download` y no `stream`: lo que el dueño hace con esto es mandarlo por
        // WhatsApp o adjuntarlo a un correo, y para eso necesita el archivo.
        return $pdf->download("cotizacion-{$tenant->slug}-{$order->number}.pdf");
    }
}
