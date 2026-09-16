<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los clientes de la tienda y lo que le han comprado (MOD-10).
 *
 * El dato estaba entero desde hacia meses -cuentas de cliente, favoritos y
 * pedidos asociados- y no habia ni una pantalla que lo enseñara: el dueño podia
 * ver un pedido, pero no a la persona. Quien compra todos los meses y quien
 * compro una vez hace un año se veian exactamente igual.
 *
 * **Un cliente es un `User` con rol `customer`.** Viven en la misma tabla que el
 * equipo del panel, asi que el filtro por rol es lo que separa las dos
 * pantallas: `UserController` lista solo `ROLES_DE_PANEL` y esta, solo
 * `customer`. Sin ese filtro, el equipo apareceria como clientela.
 *
 * **Todas las consultas filtran por `tenant_id` a mano**, por el mismo motivo
 * que las del equipo: `User` es la excepcion al fallo en cerrado de AUD-4 y no
 * puede confiarse en su global scope para aislar tiendas.
 *
 * **Solo salen los que tienen cuenta.** El checkout publico no la exige, asi que
 * un pedido de invitado no crea cliente y no aparece aqui; ese pedido se sigue
 * viendo entero en Pedidos, que es donde vive.
 */
class CustomerController extends Controller
{
    /**
     * Orden de la lista. `recientes` es el que trae la pantalla por defecto;
     * `gasto` y `pedidos` son la pregunta que de verdad se hace el dueño, que es
     * quien le compra mas.
     */
    private const ORDENES = ['recientes', 'gasto', 'pedidos'];

    public function index(Request $request): JsonResponse
    {
        $orden = in_array($request->sort, self::ORDENES, true) ? $request->sort : 'recientes';

        $clientes = $this->base()
            ->when($request->filled('search'), function ($q) use ($request) {
                $termino = trim($request->search);

                $q->where(function ($sub) use ($termino) {
                    $sub->where('name', 'like', "%{$termino}%")
                        ->orWhere('email', 'like', "%{$termino}%")
                        ->orWhere('phone', 'like', "%{$termino}%");
                });
            })
            ->when($orden === 'recientes', fn ($q) => $q->orderByDesc('created_at'))
            // Los que nunca han comprado quedan al final en los dos ordenes de
            // ventas, no fuera: un cliente registrado que no ha comprado todavia
            // es justo al que el dueño querria escribirle.
            ->when($orden === 'gasto', fn ($q) => $q->orderByDesc('total_gastado')->orderByDesc('created_at'))
            ->when($orden === 'pedidos', fn ($q) => $q->orderByDesc('pedidos_count')->orderByDesc('created_at'))
            ->paginate(Paginacion::porPagina($request, 20));

        return response()->json($clientes->through(fn (User $cliente) => $this->conTotales($cliente)));
    }

    /**
     * La ficha: los mismos totales de la lista y su historial de compras.
     */
    public function show(Request $request, string $customer): JsonResponse
    {
        $cliente = $this->base()->where('id', $customer)->firstOrFail();

        // Sin `with` en la consulta de arriba: los pedidos se piden aparte para
        // poder limitarlos. Un cliente fiel puede tener cientos y la ficha no es
        // un listado de pedidos, es un resumen con lo ultimo.
        $pedidos = Order::where('user_id', $cliente->id)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'number', 'status', 'total', 'created_at']);

        return response()->json([
            'customer' => $this->conTotales($cliente),
            'orders'   => $pedidos,
        ]);
    }

    /**
     * Los totales, siempre como numeros.
     *
     * Vienen de subconsultas con `sum()` y `count()`, y lo que devuelve el driver
     * NO es lo mismo en todas partes: MySQL da `"800.00"` (string) donde SQLite
     * -el de los tests- da `800`. Sin normalizar aqui, el panel tendria que
     * adivinar el tipo y la suite pasaria en verde probando algo distinto de lo
     * que corre en produccion.
     */
    private function conTotales(User $cliente): User
    {
        $cliente->pedidos_count = (int) $cliente->pedidos_count;
        $cliente->total_gastado = round((float) $cliente->total_gastado, 2);

        return $cliente;
    }

    /**
     * La consulta comun: los clientes de ESTA tienda con sus totales de compra.
     *
     * Los tres agregados van como subconsultas y no como `withCount`/`withSum`
     * sueltos para poder ordenar por ellos y, sobre todo, para que **los pedidos
     * cancelados no cuenten**: un pedido que se anulo no es dinero que entro, y
     * sumarlo convertiria el "total gastado" en una cifra que no cuadra con la
     * caja.
     */
    private function base()
    {
        $tenantId = app('currentTenant')->id;

        $deEsteCliente = fn ($q) => $q->whereColumn('orders.user_id', 'users.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled');

        return User::query()
            ->where('users.tenant_id', $tenantId)
            ->where('role', 'customer')
            ->select(['users.id', 'users.name', 'users.email', 'users.phone', 'users.is_active', 'users.created_at'])
            ->withCount(['favorites as favoritos_count'])
            ->selectSub(
                Order::withoutTenant()->selectRaw('count(*)')->where($deEsteCliente),
                'pedidos_count',
            )
            ->selectSub(
                Order::withoutTenant()->selectRaw('coalesce(sum(orders.total), 0)')->where($deEsteCliente),
                'total_gastado',
            )
            ->selectSub(
                Order::withoutTenant()->selectRaw('max(orders.created_at)')->where($deEsteCliente),
                'ultima_compra',
            );
    }
}
