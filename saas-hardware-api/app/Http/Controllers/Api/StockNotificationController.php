<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\StockNotification;
use App\Support\Bitacora;
use App\Support\Paginacion;
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
        $query = StockNotification::with([
            'product:id,name,stock,price,sale_price,thumbnail_url',
            // MOD-5: la variante que espera, con su propio stock.
            'variant:id,product_id,options,stock,price,sale_price',
        ])
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

        $avisos = $query->paginate(Paginacion::porPagina($request, 20));

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

        $estabaAvisado = $stockNotification->notified_at !== null;

        $stockNotification->update([
            'notified_at' => $data['notified'] ? now() : null,
        ]);

        $stockNotification->load('product:id,name,stock,price,sale_price,thumbnail_url');

        if ($estabaAvisado !== (bool) $data['notified']) {
            Bitacora::anotar(
                ActivityLog::ESPERA_MARCADA,
                ($data['notified'] ? 'Marcó como avisado' : 'Volvió a dejar pendiente')
                    ." a {$stockNotification->customer_name}, que espera «{$this->queEspera($stockNotification)}».",
                ['espera_id' => $stockNotification->id, 'avisado' => (bool) $data['notified']],
            );
        }

        return response()->json($stockNotification);
    }

    public function destroy(StockNotification $stockNotification): JsonResponse
    {
        $stockNotification->loadMissing(['product:id,name', 'variant']);
        $stockNotification->delete();

        Bitacora::anotar(
            ActivityLog::ESPERA_BORRADA,
            "Quitó de la lista de espera a {$stockNotification->customer_name}, que esperaba «{$this->queEspera($stockNotification)}».",
            ['espera_id' => $stockNotification->id, 'contacto' => $stockNotification->customer_contact],
        );

        return response()->json(null, 204);
    }

    /** "Kingston Fury (32 GB)", o el aviso de que el producto ya no existe. */
    private function queEspera(StockNotification $espera): string
    {
        $espera->loadMissing(['product:id,name', 'variant']);

        $nombre = $espera->product?->name ?? 'un producto borrado';

        return $espera->variant ? "{$nombre} ({$espera->variant->nombre})" : $nombre;
    }
}
