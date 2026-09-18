<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Services\ImageService;
use App\Support\Bitacora;
use App\Support\Costos;
use App\Support\Paginacion;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * La papelera de la tienda (`MOD-8`).
 *
 * Borrar dejó de ser definitivo para las dos cosas que más duelen: un producto
 * —que además se llevaba sus fotos del disco— y un pedido —que salía del
 * historial de ventas—. Ahora los dos pasan por aquí, y de aquí se vuelve o se
 * va del todo.
 *
 * **Sólo admin.** Un colaborador no borra (`FUN-4`), así que tampoco restaura ni
 * vacía: si pudiera, la restricción de borrar no serviría de nada, porque
 * restaurar y volver a borrar es reordenar el catálogo de otro.
 *
 * **Lo que hay aquí caduca.** A los `DIAS_DE_RETENCION` días se borra de verdad,
 * lo hace `papelera:purgar` desde el scheduler. Sin eso la papelera sería una
 * fuga lenta: las fotos de lo borrado seguirían ocupando disco para siempre y el
 * dueño pagaría almacenamiento por lo que cree borrado.
 */
class TrashController extends Controller
{
    /**
     * Cuánto vive lo borrado antes de irse solo.
     *
     * Treinta días es el plazo en el que alguien se da cuenta de que le falta
     * algo: un producto de temporada se echa en falta al mes siguiente. Más
     * largo no añade seguridad y sí factura de disco.
     */
    public const DIAS_DE_RETENCION = 30;

    /** Los dos tipos que tienen papelera, y su modelo. */
    private const TIPOS = [
        'productos' => Product::class,
        'pedidos'   => Order::class,
    ];

    public function __construct(private ImageService $imageService) {}

    /**
     * Lo que hay en la papelera, de un tipo por vez.
     *
     * Los totales de los dos van siempre, aunque sólo se pida uno: la pantalla
     * enseña las dos pestañas con su número, y pedirlos en dos peticiones
     * separadas haría que al entrar se viera "Pedidos (0)" un instante.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = app('currentTenant');
        $tipo = $this->tipo($request->query('tipo', 'productos'));

        $consulta = $tipo === 'productos'
            ? Product::onlyTrashed()->with(['category:id,name,icon', 'images', 'variants'])
            : Order::onlyTrashed()->withCount('items');

        $listado = $consulta
            ->orderByDesc('deleted_at')
            ->paginate(Paginacion::porPagina($request, 20));

        return response()->json([
            'tipo'     => $tipo,
            'items'    => Costos::mostrar($listado),
            'totales'  => [
                'productos' => Product::onlyTrashed()->count(),
                'pedidos'   => Order::onlyTrashed()->count(),
            ],
            'retencion' => [
                'dias'  => self::DIAS_DE_RETENCION,
                // La fecha a partir de la cual algo sigue vivo. El frontend pinta
                // los días que le quedan a cada fila restándole su `deleted_at`,
                // en la zona de la tienda (MOD-13).
                'corte' => now()->subDays(self::DIAS_DE_RETENCION)->toIso8601String(),
            ],
            'tenant_timezone' => $tenant->zonaHoraria(),
        ]);
    }

    /**
     * Devuelve algo de la papelera a donde estaba.
     *
     * **El tope del plan se comprueba aquí y no al borrar** (`SAAS-3`): un
     * producto en la papelera no ocupa hueco, porque quien borra cinco productos
     * para hacer sitio espera tener sitio. La consecuencia es que restaurar
     * puede no caber, y entonces se dice por qué en vez de pasarse del plan a
     * escondidas.
     */
    public function restore(Request $request, string $tipo, string $id): JsonResponse
    {
        $tipo = $this->tipo($tipo);

        if ($tipo === 'productos') {
            $producto = Product::onlyTrashed()->findOrFail($id);

            PlanGate::ensureCanCreate('products');

            $producto->restore();

            Bitacora::anotar(
                ActivityLog::PAPELERA_RESTAURADO,
                "Restauró de la papelera el producto «{$producto->name}».",
                ['tipo' => 'producto', 'producto_id' => $producto->id],
            );

            return response()->json(Costos::mostrar($producto->fresh()->load(['category', 'images', 'variants'])));
        }

        $pedido = Order::onlyTrashed()->with('items')->findOrFail($id);

        DB::transaction(function () use ($pedido) {
            $pedido->restore();

            // Borrar un pedido atendido devolvió su stock; restaurarlo vuelve a
            // descontarlo, o la venta contaría en los reportes sin haber salido
            // del almacén. Puede dejar el stock en negativo si mientras tanto se
            // vendió lo mismo por otro lado — igual que una venta de mostrador,
            // que también puede: el número refleja lo que pasó, y el dueño lo
            // corrige. Mentir en la otra dirección sería peor.
            if ($pedido->status === 'attended') {
                $pedido->moverStock(decrement: true);
            }
        });

        Bitacora::anotar(
            ActivityLog::PAPELERA_RESTAURADO,
            "Restauró de la papelera el pedido #{$pedido->number} de {$pedido->customer_name}"
                .($pedido->status === 'attended' ? ' (se volvió a descontar su stock).' : '.'),
            ['tipo' => 'pedido', 'pedido_id' => $pedido->id, 'numero' => $pedido->number],
        );

        return response()->json(Costos::mostrar($pedido->fresh()->load('items')));
    }

    /** Borra del todo un elemento de la papelera. No tiene vuelta. */
    public function destroy(string $tipo, string $id): JsonResponse
    {
        $tipo = $this->tipo($tipo);

        if ($tipo === 'productos') {
            $producto = Product::onlyTrashed()->with(['images', 'variants'])->findOrFail($id);
            $nombre = $producto->name;

            $this->borrarProductos(collect([$producto]));

            Bitacora::anotar(
                ActivityLog::PAPELERA_PURGADO,
                "Eliminó definitivamente el producto «{$nombre}».",
                ['tipo' => 'producto', 'producto_id' => $id],
            );

            return response()->json(null, 204);
        }

        $pedido = Order::onlyTrashed()->findOrFail($id);
        $numero = $pedido->number;
        $cliente = $pedido->customer_name;

        // Las líneas caen por ON DELETE CASCADE. El stock no se toca: ya se
        // devolvió al mandarlo a la papelera.
        $pedido->forceDelete();

        Bitacora::anotar(
            ActivityLog::PAPELERA_PURGADO,
            "Eliminó definitivamente el pedido #{$numero} de {$cliente}.",
            ['tipo' => 'pedido', 'pedido_id' => $id, 'numero' => $numero],
        );

        return response()->json(null, 204);
    }

    /** Vacía la papelera entera, los dos tipos. */
    public function empty(): JsonResponse
    {
        $productos = Product::onlyTrashed()->with(['images', 'variants'])->get();
        $pedidos = Order::onlyTrashed()->get(['id']);

        $this->borrarProductos($productos);

        if ($pedidos->isNotEmpty()) {
            Order::onlyTrashed()->whereIn('id', $pedidos->pluck('id'))->forceDelete();
        }

        if ($productos->isNotEmpty() || $pedidos->isNotEmpty()) {
            Bitacora::anotar(
                ActivityLog::PAPELERA_VACIADA,
                "Vació la papelera: {$productos->count()} productos y {$pedidos->count()} pedidos.",
                ['productos' => $productos->count(), 'pedidos' => $pedidos->count()],
            );
        }

        return response()->json([
            'message'   => 'Papelera vaciada.',
            'productos' => $productos->count(),
            'pedidos'   => $pedidos->count(),
        ]);
    }

    /**
     * El borrado definitivo de unos productos, con sus fotos.
     *
     * Las URL se apuntan **antes** del DELETE —después ya no hay de dónde
     * leerlas— y los archivos se borran **después**, cuando la galería y las
     * variantes ya han caído por cascada: si una copia del producto (duplicar)
     * sigue usándolos, se quedan (`TEC-14`). Un producto que siga en la papelera
     * también cuenta como que las usa, porque `borrarSiNadieLasUsa()` mira la
     * tabla con `DB::table` y ahí la papelera no se ve.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $productos
     */
    private function borrarProductos(\Illuminate\Support\Collection $productos): void
    {
        if ($productos->isEmpty()) {
            return;
        }

        $fotos = $this->imageService->fotosDe($productos);

        Product::onlyTrashed()->whereIn('id', $productos->pluck('id'))->forceDelete();

        $this->imageService->borrarSiNadieLasUsa($fotos);

        // El DELETE masivo no pasa por el hook `deleted` del modelo, que es quien
        // sube la versión de caché (AUD-6). Aquí el catálogo público no cambia
        // —lo borrado ya no salía—, pero la versión es una sola para toda la
        // tienda y dejarla atrás sería una excepción que habría que recordar.
        $tenant = app('currentTenant');
        Cache::increment("tenant:{$tenant->slug}:cache_version");
    }

    /** El tipo pedido, contra la lista cerrada. Nada de la URL entra en el SQL. */
    private function tipo(?string $tipo): string
    {
        abort_unless(array_key_exists((string) $tipo, self::TIPOS), 404);

        return (string) $tipo;
    }
}
