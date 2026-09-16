<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sacar el catalogo y los pedidos de la tienda en CSV (MOD-7).
 *
 * Habia importacion y no habia exportacion: el dueño metia su catalogo y ya no
 * podia volver a sacarlo. Para un SaaS de pago poder irse es una funcion, no un
 * detalle, y mientras tanto es lo que le permite editar en Excel, llevar sus
 * ventas a la contabilidad y guardarse una copia suya.
 *
 * **Se escribe directamente a la salida, fila a fila.** Un catalogo de 5.000
 * productos armado en memoria como un string es un 500 por memoria agotada justo
 * en la tienda mas grande, que es la que mas necesita exportar.
 *
 * **Delimitador `;` y BOM de UTF-8.** Los dos son por Excel: con `,` parte mal
 * las columnas en los Windows en español, y sin BOM pinta los acentos rotos. El
 * importador acepta los dos delimitadores y se come el BOM (OWN-5), asi que lo
 * exportado se puede volver a importar.
 *
 * **Solo admin**: el CSV del catalogo lleva el costo de compra (MOD-6) y el de
 * pedidos, los datos de contacto de todos los clientes de la tienda.
 */
class ExportController extends Controller
{
    /**
     * Cuantas filas se leen de golpe. Ni una por consulta ni todas de una vez.
     */
    private const POR_LOTE = 300;

    /**
     * El catalogo, con las mismas cabeceras que lee el importador.
     *
     * Acepta los mismos filtros que el listado del panel para que lo que el dueño
     * ve filtrado en pantalla sea lo que se lleva: si esta mirando "Procesadores"
     * y exporta, espera procesadores.
     */
    public function products(Request $request): StreamedResponse
    {
        $tenant = app('currentTenant');

        $consulta = $this->sinScopeDeTienda(Product::query(), $tenant->id)
            ->with(['category' => fn ($q) => $q->withoutTenant()])
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->search, fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('name', 'like', "%{$request->search}%")
                    ->orWhere('sku', 'like', "%{$request->search}%");
            }))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('created_at');

        return $this->csv("catalogo-{$tenant->slug}-".now()->format('Y-m-d').'.csv', function ($salida) use ($consulta) {
            fputcsv($salida, [
                'nombre', 'marca', 'sku', 'precio', 'precio_oferta', 'costo',
                'stock', 'categoria', 'descripcion', 'especificaciones', 'estado',
            ], ';');

            foreach ($consulta->lazy(self::POR_LOTE) as $producto) {
                fputcsv($salida, [
                    $producto->name,
                    $producto->brand,
                    $producto->sku,
                    $producto->price,
                    $producto->sale_price,
                    $producto->cost,
                    $producto->stock,
                    $producto->category?->name,
                    // El HTML de la descripcion se queda como esta: es lo que hay
                    // guardado, y limpiarlo aqui haria que reimportar cambiara el
                    // texto sin que nadie lo pidiera.
                    $producto->description,
                    $this->specsEnTexto($producto->specs),
                    $producto->status === 'published' ? 'publicado' : 'borrador',
                ], ';');
            }
        });
    }

    /**
     * Los pedidos, **una fila por pedido**.
     *
     * Una fila por linea dejaria sumar mal el total (se contaria una vez por
     * producto) y lo que se lleva a la contabilidad son pedidos. Lo vendido va
     * resumido en una columna de texto.
     *
     * Ademas de los filtros del listado, acepta un rango de fechas: lo que se
     * exporta para cuadrar cuentas es un mes, no "todo".
     */
    public function orders(Request $request): StreamedResponse
    {
        $tenant = app('currentTenant');

        $request->validate([
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date|after_or_equal:desde',
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $consulta = $this->sinScopeDeTienda(Order::query(), $tenant->id)
            ->with('items')
            ->when(
                in_array($request->status, ['pending', 'processing', 'attended', 'cancelled'], true),
                fn ($q) => $q->where('status', $request->status),
            )
            ->when($request->desde, fn ($q) => $q->whereDate('created_at', '>=', $request->desde))
            ->when($request->hasta, fn ($q) => $q->whereDate('created_at', '<=', $request->hasta))
            ->orderBy('created_at');

        return $this->csv("pedidos-{$tenant->slug}-".now()->format('Y-m-d').'.csv', function ($salida) use ($consulta) {
            fputcsv($salida, [
                'numero', 'fecha', 'estado', 'cliente', 'telefono', 'correo',
                'entrega', 'costo_envio', 'productos', 'total', 'costo_total',
                'utilidad', 'nota',
            ], ';');

            foreach ($consulta->lazy(self::POR_LOTE) as $pedido) {
                fputcsv($salida, [
                    $pedido->number,
                    $pedido->created_at?->format('Y-m-d H:i'),
                    $this->estadoEnTexto($pedido->status),
                    $pedido->customer_name,
                    $pedido->customer_phone,
                    $pedido->customer_email,
                    $this->entregaEnTexto($pedido->delivery_method),
                    $pedido->delivery_cost,
                    $pedido->items->map(fn (OrderItem $i) => "{$i->quantity}x ".$i->descripcion())->implode(' | '),
                    $pedido->total,
                    // Vacio, y no 0, cuando ninguna linea tenia costo: cero seria
                    // afirmar que la tienda gano el precio entero (MOD-6).
                    $pedido->costo_total,
                    $pedido->utilidad,
                    $pedido->customer_note,
                ], ';');
            }
        });
    }

    /**
     * La tienda, filtrada A MANO y con el global scope apagado.
     *
     * **No es un atajo: es lo unico que funciona aqui.** Lo que escribe el CSV es
     * un callback que Laravel ejecuta al ENVIAR la respuesta, y para entonces el
     * middleware de tienda ya termino y la olvido. Con el scope puesto, el
     * `whereRaw('1 = 0')` del fallo en cerrado (AUD-4) se aplicaria justo al
     * recorrer las filas y el dueño se descargaria un archivo con la cabecera y
     * nada mas —sin ningun error, que es lo peor que podia pasar—. Salio en el
     * primer test que conto las filas.
     *
     * El aislamiento no se pierde: el `tenant_id` se fija aqui, con la tienda que
     * resolvio el middleware antes de empezar.
     */
    private function sinScopeDeTienda($consulta, string $tenantId)
    {
        return $consulta->withoutTenant()->where('tenant_id', $tenantId);
    }

    /**
     * El envoltorio comun: cabeceras de descarga, BOM y la salida abierta.
     */
    private function csv(string $nombre, callable $escribir): StreamedResponse
    {
        return response()->streamDownload(function () use ($escribir) {
            $salida = fopen('php://output', 'w');

            fwrite($salida, "\xEF\xBB\xBF");
            $escribir($salida);

            fclose($salida);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Sin esto, un navegador o un proxy pueden servir la exportacion de
            // ayer creyendo que la URL no ha cambiado.
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Las specs en el mismo formato que lee el importador: "Socket: AM5 | TDP: 65W".
     */
    private function specsEnTexto(?array $specs): string
    {
        if (! $specs) {
            return '';
        }

        return collect($specs)
            ->map(fn ($valor, $clave) => "{$clave}: ".(is_array($valor) ? implode(', ', $valor) : $valor))
            ->implode(' | ');
    }

    private function estadoEnTexto(string $estado): string
    {
        return [
            'pending'    => 'pendiente',
            'processing' => 'en proceso',
            'attended'   => 'atendido',
            'cancelled'  => 'cancelado',
        ][$estado] ?? $estado;
    }

    private function entregaEnTexto(?string $metodo): string
    {
        return match ($metodo) {
            'delivery' => 'envío a domicilio',
            'pickup'   => 'recojo en tienda',
            default    => '',
        };
    }
}
