<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lista de espera de "avisame cuando llegue" (FUN-1b).
 *
 * El formulario publico existia desde 5.4 y guardaba filas que **no veia nadie**:
 * el panel solo enseñaba un contador de cuantos esperaban un producto agotado, y
 * el dueño no tenia forma de saber quienes eran ni de escribirles. Con el envio
 * automatico de FUN-1b eso se resuelve solo para quien dejo un correo, pero la
 * otra mitad —los que dejaron un telefono— sigue necesitando que alguien los mire:
 * de ahi esta pantalla.
 */
class StockNotificationController extends Controller
{
    /**
     * Quien espera que vuelva el stock.
     *
     * Ordena los pendientes primero y, dentro, los que llevan mas tiempo
     * esperando: el orden en que hay que atenderlos.
     */
    public function index(Request $request): JsonResponse
    {
        $query = StockNotification::with(['product:id,name,stock,price,sale_price,thumbnail_url'])
            ->orderByRaw('notified_at IS NULL DESC')
            ->orderBy('created_at');

        if ($request->query('status') === 'pending') {
            $query->whereNull('notified_at');
        }

        if ($request->query('status') === 'notified') {
            $query->whereNotNull('notified_at');
        }

        // Se llega aqui desde la insignia "N en espera" de la lista de productos.
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        $avisos = $query->paginate($request->integer('per_page', 20));

        return response()->json($avisos);
    }

    /**
     * Marcar como avisado, o volver a dejarlo pendiente.
     *
     * Existe por los contactos que son un telefono: a esos no les llega el correo
     * automatico, asi que el dueño escribe por WhatsApp y luego lo marca aqui. El
     * camino de vuelta (`notified: false`) es para el error de dedo, que en una
     * lista de una sola columna de botones es cuestion de tiempo.
     */
    public function update(Request $request, StockNotification $stockNotification): JsonResponse
    {
        $data = $request->validate([
            'notified' => 'required|boolean',
        ]);

        $stockNotification->update([
            'notified_at' => $data['notified'] ? now() : null,
        ]);

        return response()->json($stockNotification->load('product:id,name,stock,price,sale_price,thumbnail_url'));
    }

    public function destroy(StockNotification $stockNotification): JsonResponse
    {
        $stockNotification->delete();

        return response()->json(null, 204);
    }
}
