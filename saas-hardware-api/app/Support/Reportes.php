<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Support\Cupones;
use App\Support\Impuesto;
use App\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los numeros de los reportes de la tienda (MOD-9).
 *
 * Vive aparte de los controladores porque lo piden dos: la pantalla
 * (`ReportController`) y la exportacion a CSV (`ExportController`). Si cada uno
 * escribiera sus sumas, el dia que cambiara que cuenta como venta cambiaria en
 * una de las dos y el dueño tendria una pantalla que no cuadra con su archivo.
 *
 * **Que cuenta como venta: los pedidos `attended`.** Es lo mismo que ya suma
 * `/dashboard/stats` en "Ventas Totales", y cambiarlo aqui haria que dos
 * pantallas del mismo panel dieran cifras distintas. Un pedido pendiente es una
 * intencion, no caja; los recibidos y los cancelados salen aparte en el resumen
 * para que se vea cuanto se queda por el camino.
 *
 * **La fecha que manda es la de creacion del pedido**, no la de cuando se
 * atendio: es la que ve el dueño en el listado y la que exporta MOD-7, y la
 * unica que no se mueve sola.
 *
 * **Y esa fecha se lee en la zona horaria de la tienda** (MOD-13). Todo se
 * guarda en UTC y se sigue guardando en UTC; lo que cambia es donde se corta el
 * dia. Sin esto, una venta de las 8 de la noche en Peru (UTC-5) contaba como
 * del dia siguiente, que en el total del mes da igual y en una grafica por dia
 * no.
 *
 * **Todas las consultas van con `withoutTenant()` y el `tenant_id` a mano.** No
 * es un descuido del aislamiento sino lo contrario: la exportacion escribe su
 * CSV en un callback que corre al ENVIAR la respuesta, cuando el middleware ya
 * olvido la tienda, y ahi el fallo en cerrado de AUD-4 devolveria un archivo
 * vacio sin ningun error. Escrito asi, la misma clase da los mismos numeros la
 * llame quien la llame.
 */
class Reportes
{
    /** Como se agrupa la serie. */
    public const AGRUPACIONES = ['dia', 'mes'];

    /**
     * Cuanto rango se admite en cada agrupacion.
     *
     * No es un limite de la base de datos, es de la pantalla: 366 puntos ya es
     * un grafico apretado y 3.000 es una linea negra. Quien quiera cinco años
     * los pide por mes, que es como se miran cinco años.
     */
    private const MAXIMO_DIAS = 366;

    private const MAXIMO_MESES = 60;

    /** Cuantas filas devuelven las listas de la pantalla. */
    private const TOPE_DE_LISTA = 10;

    private const TOPE_STOCK_BAJO = 20;

    /**
     * @param  CarbonImmutable  $desde  Primer dia del rango, **en la zona de la
     *                                  tienda** y a las 00:00. Lo construye
     *                                  `rango()`; pasarlo en otra zona hace que
     *                                  el rango empiece a otra hora.
     * @param  CarbonImmutable  $hasta  Ultimo dia del rango, igual.
     */
    public function __construct(
        private string $tenantId,
        private CarbonImmutable $desde,
        private CarbonImmutable $hasta,
        private string $agrupacion = 'dia',
    ) {}

    /**
     * Lee y valida el rango de la peticion, o devuelve el ultimo mes.
     *
     * Los 30 dias por defecto son la pregunta que se hace sola al abrir la
     * pantalla; cualquier otra hay que escribirla. Esta aqui y no en cada
     * controlador para que la pantalla y su exportacion no puedan entender
     * "desde" de dos maneras distintas.
     *
     * **Las dos fechas se interpretan en la zona de la tienda** (MOD-13). "Del
     * 1 al 31" es del 1 al 31 alli, no en UTC: el dueño escribe los dias de su
     * calendario, no los del servidor. Y "hoy" sin fechas es el hoy de la
     * tienda, que puede no ser el del servidor -a las 21:00 en Lima, en UTC ya
     * es maniana-.
     *
     * @param  string  $zona  Identificador IANA de `Tenant::zonaHoraria()`.
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    public static function rango(Request $request, string $zona = 'UTC'): array
    {
        $request->validate([
            'desde'      => 'nullable|date',
            'hasta'      => 'nullable|date|after_or_equal:desde',
            'agrupacion' => 'nullable|in:dia,mes',
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'agrupacion.in'        => 'El reporte se agrupa por día o por mes.',
        ]);

        $agrupacion = in_array($request->agrupacion, self::AGRUPACIONES, true)
            ? $request->agrupacion
            : 'dia';

        $hasta = $request->filled('hasta')
            ? CarbonImmutable::parse($request->hasta, $zona)->startOfDay()
            : CarbonImmutable::now($zona)->startOfDay();

        $desde = $request->filled('desde')
            ? CarbonImmutable::parse($request->desde, $zona)->startOfDay()
            : $hasta->subDays(29);

        self::comprobarLargo($desde, $hasta, $agrupacion);

        return [$desde, $hasta, $agrupacion];
    }

    private static function comprobarLargo(CarbonImmutable $desde, CarbonImmutable $hasta, string $agrupacion): void
    {
        if ($agrupacion === 'dia' && $desde->diffInDays($hasta) + 1 > self::MAXIMO_DIAS) {
            abort(422, 'Por día no se pueden pedir más de '.self::MAXIMO_DIAS.' días seguidos. Agrupa por mes.');
        }

        if ($agrupacion === 'mes' && $desde->startOfMonth()->diffInMonths($hasta->startOfMonth()) + 1 > self::MAXIMO_MESES) {
            abort(422, 'Por mes no se pueden pedir más de '.self::MAXIMO_MESES.' meses seguidos.');
        }
    }

    // ----------------------------------------------------------- el resumen

    /**
     * Las cifras de cabecera del rango.
     *
     * `ventas` es lo cobrado, envio incluido, porque es lo que entro en caja y
     * lo que el dueño ve en el listado de pedidos. `envio` sale aparte porque no
     * es margen de nada: es lo que se le cobra al comprador por llevarselo
     * (MOD-1), y lo que la tienda le paga al repartidor no lo sabe el sistema.
     * `impuesto` sale aparte por la misma razon (MOD-2): entro en caja, pero es
     * del fisco. `descuento` (MOD-4) es lo contrario —lo que NO entro—, y sale
     * aparte porque `ventas` ya lo tiene restado: sin esta linea no habria forma
     * de saber cuanto costo una campaña. Por eso el margen se mide contra las
     * ventas SIN envio, SIN impuesto y con el cupon ya repartido, igual que hace
     * `Order::getUtilidadAttribute()`.
     *
     * @param  bool  $conCostos  si quien mira puede ver el costo (MOD-6). En
     *                           falso, las claves de costo y utilidad ni
     *                           siquiera existen en el resultado.
     * @return array<string, float|int>
     */
    public function resumen(bool $conCostos): array
    {
        $vendidos = $this->pedidosDelRango()
            ->where('status', 'attended')
            ->selectRaw('count(*) as pedidos, coalesce(sum(total), 0) as ventas, coalesce(sum(delivery_cost), 0) as envio')
            // MOD-2: lo que de esas ventas es impuesto. Sale aparte por lo mismo
            // que el envio: entro en caja pero no es de la tienda.
            ->selectRaw('coalesce(sum(tax_amount), 0) as impuesto')
            // MOD-4: lo que se dejo de cobrar en cupones. Sale aparte porque es
            // lo unico que dice si una campaña salio cara: `ventas` ya viene con
            // el descuento restado y por si sola no deja verlo.
            ->selectRaw('coalesce(sum(discount_amount), 0) as descuento')
            ->first();

        $pedidos = (int) $vendidos->pedidos;
        $ventas = round((float) $vendidos->ventas, 2);

        $lineas = $this->lineasDelRango()
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as unidades')
            ->selectRaw('coalesce(sum(case when order_items.unit_cost is null then 1 else 0 end), 0) as sin_costo')
            ->selectRaw($this->sumaDeVentaConCosto())
            ->selectRaw($this->sumaDeCosto())
            ->first();

        $resumen = [
            'ventas'          => $ventas,
            'envio'           => round((float) $vendidos->envio, 2),
            'impuesto'        => round((float) $vendidos->impuesto, 2),
            'descuento'       => round((float) $vendidos->descuento, 2),
            'pedidos'         => $pedidos,
            // Con el envio incluido, que es lo que pago el cliente. Sin pedidos
            // da 0, no una division por cero.
            'ticket_promedio' => $pedidos > 0 ? round($ventas / $pedidos, 2) : 0.0,
            'unidades'        => (int) $lineas->unidades,
            // Los dos de abajo NO son ventas: son todo lo que entro en esas
            // fechas, atendido o no. Es lo que deja ver que se esta cancelando
            // la mitad de lo que llega.
            'recibidos'       => $this->pedidosDelRango()->count(),
            'cancelados'      => $this->pedidosDelRango()->where('status', 'cancelled')->count(),
        ];

        if (! $conCostos) {
            return $resumen;
        }

        $costo = round((float) $lineas->costo, 2);
        $ventaConCosto = round((float) $lineas->venta_con_costo, 2);
        $utilidad = round($ventaConCosto - $costo, 2);

        return $resumen + [
            'costo'            => $costo,
            'utilidad'         => $utilidad,
            // El margen se mide solo contra lo que TIENE costo conocido; meter
            // dentro las lineas sin costo daria un porcentaje inventado.
            'margen'           => $ventaConCosto > 0 ? round($utilidad / $ventaConCosto * 100, 1) : 0.0,
            'lineas_sin_costo' => (int) $lineas->sin_costo,
        ];
    }

    // ------------------------------------------------------------- la serie

    /**
     * Ventas periodo a periodo, **con los periodos vacios rellenos**.
     *
     * Agrupar en SQL solo devuelve los dias que tuvieron algo. Pintar eso en un
     * grafico pega el lunes con el jueves y dibuja una semana buenisima: los
     * huecos se rellenan aqui con ceros para que el eje sea el tiempo real.
     *
     * @return array<int, array<string, mixed>>
     */
    public function serie(bool $conCostos): array
    {
        $periodo = $this->expresionDePeriodo();

        $ventas = $this->pedidosDelRango()
            ->where('status', 'attended')
            ->selectRaw("{$periodo} as periodo, count(*) as pedidos, coalesce(sum(total), 0) as ventas")
            ->groupBy('periodo')
            ->get()
            ->keyBy('periodo');

        $lineas = $this->lineasDelRango()
            ->selectRaw("{$periodo} as periodo")
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as unidades')
            ->selectRaw($this->sumaDeVentaConCosto())
            ->selectRaw($this->sumaDeCosto())
            ->groupBy('periodo')
            ->get()
            ->keyBy('periodo');

        return collect($this->periodos())
            ->map(function (string $clave) use ($ventas, $lineas, $conCostos) {
                $v = $ventas->get($clave);
                $l = $lineas->get($clave);

                $fila = [
                    'periodo'  => $clave,
                    'ventas'   => $v ? round((float) $v->ventas, 2) : 0.0,
                    'pedidos'  => $v ? (int) $v->pedidos : 0,
                    'unidades' => $l ? (int) $l->unidades : 0,
                ];

                if ($conCostos) {
                    $fila['utilidad'] = $l
                        ? round((float) $l->venta_con_costo - (float) $l->costo, 2)
                        : 0.0;
                }

                return $fila;
            })
            ->values()
            ->all();
    }

    /**
     * Todas las etiquetas del rango, en orden, tengan ventas o no.
     *
     * @return array<int, string>
     */
    private function periodos(): array
    {
        $claves = [];

        if ($this->agrupacion === 'mes') {
            $cursor = $this->desde->startOfMonth();
            $fin = $this->hasta->startOfMonth();

            while ($cursor <= $fin) {
                $claves[] = $cursor->format('Y-m');
                $cursor = $cursor->addMonth();
            }

            return $claves;
        }

        $cursor = $this->desde;

        while ($cursor <= $this->hasta) {
            $claves[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $claves;
    }

    /**
     * La etiqueta del periodo, **en el dialecto de cada motor y en la hora de la
     * tienda**.
     *
     * MySQL no tiene `strftime` y SQLite no tiene `DATE_FORMAT`. La suite corre
     * sobre SQLite y produccion sobre MySQL, asi que escribir solo una de las
     * dos deja un test en verde y un 500 en el servidor, que es justo el fallo
     * que nadie ve venir.
     *
     * El `created_at` esta en UTC, asi que antes de recortarlo a "dia" o "mes"
     * se le suma el desplazamiento de la tienda (MOD-13). Se hace sumando
     * minutos y no con `CONVERT_TZ`: esa funcion devuelve NULL si el servidor
     * MySQL no tiene cargadas las tablas de zonas horarias -que es lo normal en
     * una instalacion recien hecha-, y un NULL aqui agruparia todas las ventas
     * en una sola fila vacia sin dar ningun error.
     *
     * `$minutos` sale de Carbon y se fuerza a entero antes de entrar en el SQL:
     * es el unico valor de esta cadena que no es literal.
     */
    private function expresionDePeriodo(): string
    {
        $formato = $this->agrupacion === 'mes' ? '%Y-%m' : '%Y-%m-%d';
        $minutos = $this->desplazamientoEnMinutos();

        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('{$formato}', orders.created_at, '{$minutos} minutes')"
            : "DATE_FORMAT(DATE_ADD(orders.created_at, INTERVAL {$minutos} MINUTE), '{$formato}')";
    }

    /**
     * Cuantos minutos separan la hora de la tienda de UTC.
     *
     * Se toma **al final del rango** y se usa uno solo para todo el. Donde hay
     * horario de verano -Chile, Paraguay, Espania- un rango que cruce el cambio
     * reparte mal las ventas de esa hora concreta; el resto del mercado al que
     * apunta esto (Peru, Colombia, Mexico, Bolivia, Brasil...) no lo tiene. La
     * alternativa correcta al cien por cien seria convertir fila a fila con las
     * tablas de zonas de MySQL, que no siempre estan, o traerse un anio de
     * ventas a PHP para agruparlas ahi. Queda escrito como limite en
     * `funcionalidades.md`, que es donde tiene que estar.
     *
     * Los limites del rango NO usan esto: se calculan con Carbon, que si sabe de
     * horario de verano, asi que el rango es exacto siempre.
     */
    private function desplazamientoEnMinutos(): int
    {
        return (int) $this->hasta->utcOffset();
    }

    // -------------------------------------------------------- mas vendidos

    /**
     * Los productos que mas UNIDADES movieron, que no es lo mismo que los mas
     * vistos: lo que enseña el Resumen es interes, esto es venta.
     *
     * Se agrupa por el snapshot (`product_id` + `product_name`) y no por el
     * producto de hoy: un producto borrado deja `product_id` en null y su
     * historial tiene que seguir contando, con el nombre que tenia entonces.
     *
     * @param  int|null  $tope  null para traerlos todos, que es lo que exporta el CSV.
     * @return array<int, array<string, mixed>>
     */
    public function masVendidos(bool $conCostos, ?int $tope = self::TOPE_DE_LISTA): array
    {
        $filas = $this->lineasDelRango()
            ->selectRaw('order_items.product_id, order_items.product_name')
            ->selectRaw('sum(order_items.quantity) as unidades')
            // Lo cobrado por ese producto, con el impuesto dentro si asi se
            // vendio: es lo que entro en caja, igual que `ventas` del resumen.
            ->selectRaw('sum(order_items.subtotal) as ventas')
            ->selectRaw($this->sumaDeVentaConCosto())
            ->selectRaw($this->sumaDeCosto())
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('unidades')
            ->orderByDesc('ventas')
            ->when($tope !== null, fn ($q) => $q->limit($tope))
            ->get();

        return $filas->map(function ($fila) use ($conCostos) {
            $item = [
                'product_id' => $fila->product_id,
                'nombre'     => $fila->product_name,
                'unidades'   => (int) $fila->unidades,
                'ventas'     => round((float) $fila->ventas, 2),
            ];

            if ($conCostos) {
                $item['utilidad'] = round((float) $fila->venta_con_costo - (float) $fila->costo, 2);
            }

            return $item;
        })->all();
    }

    // ----------------------------------------------------------- stock bajo

    /**
     * Lo que se esta acabando, segun el umbral que ya tenia cada ficha.
     *
     * `low_stock_threshold` existia desde hacia meses y no lo miraba nadie: el
     * dueño lo rellenaba y el panel no hacia nada con el.
     *
     * **Las variantes van por su cuenta.** Con variantes, `products.stock` es la
     * SUMA de todas (MOD-5), asi que un producto con 40 unidades de una opcion y
     * 0 de otra no daria nunca "bajo" mirando la ficha, y lo agotado sigue
     * agotado. Cada variante trae su propio umbral y es ese el que se compara.
     *
     * No depende del rango de fechas: es una foto de ahora.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stockBajo(): array
    {
        $fichas = Product::withoutTenant()
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->whereDoesntHave('variants', fn ($q) => $q->withoutTenant())
            ->whereColumn('stock', '<=', 'low_stock_threshold')
            ->orderBy('stock')
            ->limit(self::TOPE_STOCK_BAJO)
            ->get(['id', 'name', 'sku', 'stock', 'low_stock_threshold'])
            ->map(fn (Product $p) => [
                'product_id' => $p->id,
                'nombre'     => $p->name,
                'sku'        => $p->sku,
                'stock'      => (int) $p->stock,
                'umbral'     => (int) $p->low_stock_threshold,
            ]);

        $variantes = ProductVariant::withoutTenant()
            ->where('tenant_id', $this->tenantId)
            ->whereColumn('stock', '<=', 'low_stock_threshold')
            ->whereHas('product', fn ($q) => $q->withoutTenant()->where('is_active', true))
            ->with(['product' => fn ($q) => $q->withoutTenant()->select('id', 'name')])
            ->orderBy('stock')
            ->limit(self::TOPE_STOCK_BAJO)
            ->get()
            ->map(fn (ProductVariant $v) => [
                'product_id' => $v->product_id,
                'nombre'     => $v->product?->name.' ('.$v->nombre.')',
                'sku'        => $v->sku,
                'stock'      => (int) $v->stock,
                'umbral'     => (int) $v->low_stock_threshold,
            ]);

        // Los dos listados se mezclan y se vuelven a cortar: lo que hay que
        // reponer primero es lo que menos queda, venga de una ficha o de una
        // opcion.
        return $fichas->concat($variantes)
            ->sortBy('stock')
            ->take(self::TOPE_STOCK_BAJO)
            ->values()
            ->all();
    }

    // ------------------------------------------------------ consultas base

    /**
     * Los pedidos de la tienda dentro del rango, sin filtrar por estado.
     *
     * **Instantes exactos, no `whereDate`.** El dia de la tienda empieza y
     * acaba en un momento concreto de UTC -las 00:00 del 1 de septiembre en Lima
     * son las 05:00 UTC- y `whereDate` comparaba la fecha UTC, o sea que se
     * comia las cinco primeras horas del dia y se traia cinco de mas del
     * anterior (MOD-13).
     *
     * El corte de arriba es `<` sobre el comienzo del dia SIGUIENTE, y no `<=`
     * sobre el final de este: asi no hay que discutir si el ultimo segundo lleva
     * milisegundos ni si la columna los guarda.
     *
     * Las dos fronteras las calcula Carbon a partir de la zona de la tienda, asi
     * que son correctas tambien donde hay horario de verano.
     */
    private function pedidosDelRango()
    {
        return Order::withoutTenant()
            ->where('tenant_id', $this->tenantId)
            ->where('created_at', '>=', $this->desde->utc())
            ->where('created_at', '<', $this->hasta->addDay()->utc());
    }

    /**
     * Las LINEAS vendidas del rango: los `order_items` de los pedidos atendidos.
     *
     * Va por join y no por relacion porque todo lo que se pregunta aqui son
     * sumas sobre las lineas -unidades, costo, utilidad, mas vendidos- y traerse
     * los modelos para sumarlos en PHP seria cargar un año de ventas en memoria.
     *
     * `order_items` no tiene `tenant_id` -cuelga del pedido-, asi que el
     * aislamiento lo pone el `where` sobre `orders`.
     */
    private function lineasDelRango()
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $this->tenantId)
            // MOD-8: la papelera. Esta es la unica consulta de pedidos que no va
            // por Eloquent, asi que el global scope de `SoftDeletes` no la toca y
            // el filtro se escribe a mano. Sin el, un pedido borrado seguiria
            // contando como venta en los reportes y en su CSV -y cuadraria
            // consigo mismo, que es lo que lo hace dificil de ver-.
            ->whereNull('orders.deleted_at')
            ->where('orders.status', 'attended')
            // Los mismos instantes exactos que `pedidosDelRango()`; ver alli.
            ->where('orders.created_at', '>=', $this->desde->utc())
            ->where('orders.created_at', '<', $this->hasta->addDay()->utc());
    }

    /**
     * Lo vendido que SI sabemos lo que costo, y lo que costo.
     *
     * Van juntas y escritas una sola vez porque el par es lo que hace honesta a
     * la utilidad: una linea sin `unit_cost` no suma ni de un lado ni del otro
     * (MOD-6). Sumarla solo en ventas inflaria el margen; contarla como costo 0
     * seria afirmar que la tienda gano el precio entero.
     */
    private function sumaDeVentaConCosto(): string
    {
        // MOD-2: neta de impuesto, y esto no es un detalle de formato. Si los
        // precios de la tienda llevan el impuesto dentro, parte de cada
        // `subtotal` es del fisco: contarlo como venta infla el margen en el
        // porcentaje entero, y ese numero es el que el dueño usa para decidir
        // precios. `Impuesto::expresionNeta()` descuenta lo mismo que descuenta
        // `Order::getUtilidadAttribute()` en un pedido suelto, que es lo que
        // impide que la ficha de un pedido y el reporte den dos margenes
        // distintos de la misma venta.
        // MOD-4: primero se reparte el cupon y despues se saca el impuesto, en
        // ese orden, porque el descuento se aplico sobre precios que ya llevaban
        // el impuesto dentro. Al reves saldria un margen distinto del que enseña
        // la ficha del pedido.
        $neta = Impuesto::expresionNeta(Cupones::expresionNeta('order_items.subtotal'));

        return "coalesce(sum(case when order_items.unit_cost is null then 0 else {$neta} end), 0) as venta_con_costo";
    }

    private function sumaDeCosto(): string
    {
        return 'coalesce(sum(case when order_items.unit_cost is null then 0 else order_items.unit_cost * order_items.quantity end), 0) as costo';
    }
}
