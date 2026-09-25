<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Review;
use App\Support\Bitacora;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * Devuelve la lista de reseñas del tenant actual.
     * Admite búsqueda por nombre de cliente, comentario o producto, y filtro por valoración y estado de aprobación.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Review::with('product:id,name,image_url')
            ->orderBy('created_at', 'desc');

        // Filtro por aprobación
        if ($request->has('is_approved') && $request->is_approved !== '') {
            $query->where('is_approved', filter_var($request->is_approved, FILTER_VALIDATE_BOOLEAN));
        }

        // Filtro por valoración (estrellas)
        if ($request->filled('rating')) {
            $query->where('rating', $request->integer('rating'));
        }

        // Búsqueda por cliente, comentario o producto
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('comment', 'like', "%{$search}%")
                  ->orWhereHas('product', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Paginación
        $reviews = $query->paginate(Paginacion::porPagina($request, 15));

        // ACC-1: el correo va oculto en el modelo; el panel es el único sitio que lo ve.
        $reviews->getCollection()->each->makeVisible('customer_email');

        return response()->json($reviews);
    }

    /**
     * Actualiza el estado de aprobación de una reseña.
     */
    public function update(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate([
            'is_approved' => 'required|boolean',
        ]);

        $estabaAprobada = (bool) $review->is_approved;

        $review->update([
            'is_approved' => $data['is_approved'],
        ]);

        $review->load('product:id,name,image_url');

        if ($estabaAprobada !== (bool) $data['is_approved']) {
            Bitacora::anotar(
                ActivityLog::RESENA_MODERADA,
                ($data['is_approved'] ? 'Aprobó' : 'Ocultó')." la reseña de {$review->customer_name} ({$review->rating}★) en «{$this->nombreDelProducto($review)}».",
                ['resena_id' => $review->id, 'aprobada' => (bool) $data['is_approved']],
            );
        }

        return response()->json($review->makeVisible('customer_email'));
    }

    /**
     * Elimina una reseña.
     */
    public function destroy(Review $review): JsonResponse
    {
        $review->loadMissing('product:id,name');
        $review->delete();

        Bitacora::anotar(
            ActivityLog::RESENA_BORRADA,
            "Borró la reseña de {$review->customer_name} ({$review->rating}★) en «{$this->nombreDelProducto($review)}».",
            ['resena_id' => $review->id, 'comentario' => mb_strimwidth((string) $review->comment, 0, 200, '…')],
        );

        return response()->json(null, 204);
    }

    private function nombreDelProducto(Review $review): string
    {
        return $review->product?->name ?? 'un producto borrado';
    }
}
