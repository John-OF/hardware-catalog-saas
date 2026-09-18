<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Busqueda;
use App\Support\Costos;
use App\Support\Reportes;
use Carbon\CarbonImmutable;
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
 * **Solo admin**: el CSV del catalogo lleva el costo de compra (MOD-6), el de
 * pedidos los datos de contacto de todos los clientes de la tienda, y el del
 * reporte (MOD-9) la utilidad.
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
            ->with([
                'category' => fn ($q) => $q->withoutTenant(),
                // MOD-12: sin esto la exportacion de una tienda con variantes
                // sacaba el resumen de la ficha —el precio de la mas barata y el
                // stock sumado— y reimportarlo creaba un producto suelto con los
                // numeros mezclados.
                'variants' => fn ($q) => $q->withoutTenant()->orderBy('sort_order'),
            ])
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            // INF-6: la misma busqueda del listado, o lo que se descarga no es lo
            // que el dueño esta viendo -que es justo lo que promete el boton-.
            ->tap(fn ($q) => Busqueda::aplicar($q, $request->search))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('created_at');

        return $this->csv("catalogo-{$tenant->slug}-".now($tenant->zonaHoraria())->format('Y-m-d').'.csv', function ($salida) use ($consulta) {
            fputcsv($salida, [
                'nombre', 'marca', 'variante', 'sku', 'precio', 'precio_oferta', 'costo',
                'stock', 'categoria', 'descripcion', 'especificaciones', 'estado',
            ], ';');

            foreach ($consulta->lazy(self::POR_LOTE) as $producto) {
                // Lo que describe la ficha va solo en su primera fila, que es
                // como lo vuelve a leer el importador (MOD-12): repetir la
                // descripcion y las specs en cada variante multiplicaria el peso
                // del archivo por el numero de variantes sin añadir un dato.
                $ficha = [
                    $producto->name,
                    $producto->brand,
                ];

                $cola = [
                    $producto->category?->name,
                    // El HTML de la descripcion se queda como esta: es lo que hay
                    // guardado, y limpiarlo aqui haria que reimportar cambiara el
                    // texto sin que nadie lo pidiera.
                    $producto->description,
                    $this->specsEnTexto($producto->specs),
                    $producto->status === 'published' ? 'publicado' : 'borrador',
                ];

                if ($producto->variants->isEmpty()) {
                    fputcsv($salida, [
                        ...$ficha,
                        '',
                        $producto->sku,
                        $producto->price,
                        $producto->sale_price,
                        $producto->cost,
                        $producto->stock,
                        ...$cola,
                    ], ';');

                    continue;
                }

                foreach ($producto->variants as $indice => $variante) {
                    fputcsv($salida, [
                        // El nombre SI se repite: es lo que agrupa las filas al
                        // reimportar, asi que sin el la variante se quedaria
                        // suelta.
                        $producto->name,
                        $indice === 0 ? $producto->brand : '',
                        $this->varianteEnTexto($variante),
                        $variante->sku,
                        $variante->price,
                        $variante->sale_price,
                        $variante->cost,
                        $variante->stock,
                        ...($indice === 0 ? $cola : ['', '', '', '']),
                    ], ';');
                }
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

        $zona = $tenant->zonaHoraria();

        $consulta = $this->sinScopeDeTienda(Order::query(), $tenant->id)
            ->with('items')
            ->when(
                in_array($request->status, ['pending', 'processing', 'attended', 'cancelled'], true),
                fn ($q) => $q->where('status', $request->status),
            )
            // MOD-13: el dia de la tienda empieza y acaba en un instante
            // concreto de UTC. Con `whereDate` sobre la fecha UTC, una tienda en
            // Lima se dejaba fuera las cinco primeras horas de su "desde" y se
            // traia cinco de mas del dia anterior a su "hasta".
            ->when($request->desde, fn ($q) => $q->where(
                'created_at', '>=', CarbonImmutable::parse($request->desde, $zona)->startOfDay()->utc(),
            ))
            ->when($request->hasta, fn ($q) => $q->where(
                'created_at', '<', CarbonImmutable::parse($request->hasta, $zona)->startOfDay()->addDay()->utc(),
            ))
            ->orderBy('created_at');

        return $this->csv("pedidos-{$tenant->slug}-".now($zona)->format('Y-m-d').'.csv', function ($salida) use ($consulta, $zona) {
            fputcsv($salida, [
                'numero', 'fecha', 'estado', 'cliente', 'telefono', 'correo',
                'entrega', 'costo_envio', 'productos', 'total', 'costo_total',
                'utilidad', 'nota',
            ], ';');

            foreach ($consulta->lazy(self::POR_LOTE) as $pedido) {
                fputcsv($salida, [
                    $pedido->number,
                    // En la hora de la tienda, no en UTC (MOD-13): quien abre
                    // esto en Excel cuadra cajas con las horas de su mostrador.
                    $pedido->created_at?->timezone($zona)->format('Y-m-d H:i'),
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
     * El reporte del rango (MOD-9), en **dos bloques dentro del mismo archivo**:
     * la serie periodo a periodo y, debajo, los productos mas vendidos.
     *
     * Dos bloques y no dos descargas porque la pregunta es una sola -"como me
     * fue en agosto"- y quien la lleva a su hoja de calculo quiere las dos
     * tablas al lado. A diferencia del catalogo y de los pedidos, este CSV NO
     * esta pensado para volver a importarse: no hay nada que reimportar, son
     * cuentas.
     *
     * Sale **entero**, sin el tope de diez filas de la pantalla: en pantalla se
     * miran los diez primeros, en la hoja se suman todos.
     *
     * Los numeros salen de `App\Support\Reportes`, el mismo sitio del que los
     * saca la pantalla, para que el archivo no pueda decir otra cosa.
     */
    public function reports(Request $request): StreamedResponse
    {
        $tenant = app('currentTenant');

        [$desde, $hasta, $agrupacion] = Reportes::rango($request, $tenant->zonaHoraria());

        $reportes = new Reportes($tenant->id, $desde, $hasta, $agrupacion);

        // Aqui siempre true: la ruta es de admin, que es justo quien puede ver
        // el costo (MOD-6). Se pregunta igual en vez de escribir `true` para
        // que el dia que la ruta se abra a staff el archivo se ajuste solo.
        $conCostos = Costos::usuarioPuedeVerlos();

        // Se calculan AHORA y no dentro del callback: `streamDownload` lo
        // ejecuta al enviar la respuesta, y un `abort(422)` de un rango
        // imposible a esas alturas ya no puede convertirse en un error para el
        // navegador -las cabeceras ya salieron-.
        $serie = $reportes->serie($conCostos);
        $masVendidos = $reportes->masVendidos($conCostos, tope: null);

        $nombre = "reporte-{$tenant->slug}-{$desde->toDateString()}-a-{$hasta->toDateString()}.csv";

        return $this->csv($nombre, function ($salida) use ($serie, $masVendidos, $agrupacion, $conCostos) {
            $cabeceraSerie = [$agrupacion === 'mes' ? 'mes' : 'fecha', 'ventas', 'pedidos', 'unidades'];
            fputcsv($salida, $conCostos ? [...$cabeceraSerie, 'utilidad'] : $cabeceraSerie, ';');

            foreach ($serie as $fila) {
                fputcsv($salida, array_values($fila), ';');
            }

            // Una fila en blanco separa las dos tablas: pegadas, Excel las lee
            // como una sola con las columnas descuadradas.
            fputcsv($salida, [], ';');

            $cabeceraTop = ['producto', 'unidades', 'ventas'];
            fputcsv($salida, $conCostos ? [...$cabeceraTop, 'utilidad'] : $cabeceraTop, ';');

            foreach ($masVendidos as $fila) {
                // Sin `product_id`: en la hoja no sirve de nada y el nombre es
                // el snapshot de la venta, que es lo que se quiere leer.
                unset($fila['product_id']);

                fputcsv($salida, array_values($fila), ';');
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

    /**
     * Las opciones de una variante como las lee el importador (MOD-12):
     * "Capacidad: 1 TB | Color: Negro".
     */
    private function varianteEnTexto(ProductVariant $variante): string
    {
        return collect($variante->options ?? [])
            ->map(fn ($opcion) => trim((string) ($opcion['name'] ?? '')).': '.trim((string) ($opcion['value'] ?? '')))
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
