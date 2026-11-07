<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ImageService;
use App\Support\Cotizacion;
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
    public function __invoke(Request $request, Order $order, ImageService $imagenes): Response
    {
        $tenant = app('currentTenant');

        // FUN-26: los datos los arma `App\Support\Cotizacion`, también quién la
        // emite (la persona del equipo que la descarga) y hasta cuándo vale.
        $pdf = Pdf::loadView('cotizacion', Cotizacion::datos($order, $tenant, $request->user(), $imagenes))
            // TEC-17: solo los glifos que se usan. El paquete trae el recorte
            // apagado y empotraba DejaVu Sans y su negrita enteras (unos 730 KB
            // de los 860 que pesaba el PDF), y esto es lo que el dueño manda por
            // WhatsApp. Se enciende aquí y no en un `config/dompdf.php` publicado
            // porque es el único PDF del sistema y así no se copia la config
            // entera del paquete para cambiar una línea.
            ->setOption('enable_font_subsetting', true);

        // `download` y no `stream`: lo que el dueño hace con esto es mandarlo por
        // WhatsApp o adjuntarlo a un correo, y para eso necesita el archivo.
        return $pdf->download("cotizacion-{$tenant->slug}-{$order->number}.pdf");
    }
}
