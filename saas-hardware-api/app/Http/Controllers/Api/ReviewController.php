<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
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
        $reviews = $query->paginate($request->integer('per_page', 15));

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

        $review->update([
            'is_approved' => $data['is_approved'],
        ]);

        return response()->json($review->load('product:id,name,image_url'));
    }

    /**
     * Elimina una reseña.
     */
    public function destroy(Review $review): JsonResponse
    {
        $review->delete();
        return response()->json(null, 204);
    }
}
