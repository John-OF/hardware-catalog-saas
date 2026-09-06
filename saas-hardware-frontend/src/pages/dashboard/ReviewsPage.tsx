import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
  Search,
  Check,
  X,
  Trash2,
  Loader2,
  Star,
  ChevronLeft,
  ChevronRight,
  MessageSquare
} from 'lucide-react';
import { getReviews, updateReviewApproval, deleteReview } from '../../api/reviews';
import type { Review, PaginatedResponse } from '../../types';
import './ReviewsPage.css';

export default function ReviewsPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [isApproved, setIsApproved] = useState('');
  const [rating, setRating] = useState('');
  const [page, setPage] = useState(1);

  // Fetch reviews
  const { data, isLoading } = useQuery<PaginatedResponse<Review>>({
    queryKey: ['dashboardReviews', search, isApproved, rating, page],
    queryFn: () => getReviews({
      search: search || undefined,
      is_approved: isApproved !== '' ? isApproved : undefined,
      rating: rating !== '' ? Number(rating) : undefined,
      page,
      per_page: 10
    }),
  });

  const reviews = data?.data || [];
  const pagination = data;

  // Toggle approval mutation
  const toggleApprovalMutation = useMutation({
    mutationFn: ({ id, isApproved }: { id: string; isApproved: boolean }) =>
      updateReviewApproval(id, isApproved),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['dashboardReviews'] });
      toast.success('Estado de la reseña actualizado');
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al actualizar la reseña';
      toast.error(msg);
    }
  });

  // Delete review mutation
  const deleteMutation = useMutation({
    mutationFn: deleteReview,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['dashboardReviews'] });
      toast.success('Reseña eliminada permanentemente');
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al eliminar la reseña';
      toast.error(msg);
    }
  });

  const handleToggleApproval = (id: string, currentStatus: boolean) => {
    toggleApprovalMutation.mutate({ id, isApproved: !currentStatus });
  };

  const handleDelete = (id: string, reviewerName: string) => {
    if (window.confirm(`¿Seguro que deseas eliminar permanentemente la reseña de "${reviewerName}"?`)) {
      deleteMutation.mutate(id);
    }
  };

  return (
    <div className="reviews-page animate-fade-in page-reviews">
      <div className="page-header">
        <div>
          <h1>Moderación de Reseñas</h1>
          <p className="page-description">
            Gestiona las calificaciones y comentarios que los clientes publican en tus productos.
          </p>
        </div>
      </div>

      {/* Filters */}
      <div className="glass-card filters-bar">
        <div className="search-box">
          <Search size={18} className="search-icon" />
          <input
            type="text"
            className="search-input"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            placeholder="Buscar por cliente, comentario o producto..."
          />
        </div>

        <select
          className="filter-select filter-estado"
          value={isApproved}
          onChange={(e) => { setIsApproved(e.target.value); setPage(1); }}
        >
          <option value="">Todos los estados</option>
          <option value="true">Visibles (Aprobados)</option>
          <option value="false">Ocultos</option>
        </select>

        <select
          className="filter-select filter-rating"
          value={rating}
          onChange={(e) => { setRating(e.target.value); setPage(1); }}
        >
          <option value="">Calificaciones</option>
          {[5, 4, 3, 2, 1].map((val) => (
            <option key={val} value={val}>
              {val} {val === 1 ? 'estrella' : 'estrellas'}
            </option>
          ))}
        </select>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="glass-card state-card">
          <Loader2 size={32} className="spinner" />
        </div>
      ) : reviews.length === 0 ? (
        <div className="glass-card state-card">
          <MessageSquare size={48} />
          <h3>No se encontraron reseñas</h3>
          <p>Los clientes no han dejado calificaciones que coincidan con estos filtros.</p>
        </div>
      ) : (
        <div className="glass-card table-card">
          <div className="table-wrapper">
            <table className="dashboard-table">
              <thead>
                <tr>
                  <th>Producto</th>
                  <th>Cliente</th>
                  <th>Calificación</th>
                  <th>Comentario</th>
                  <th>Fecha</th>
                  <th>Visibilidad</th>
                  <th className="actions-header">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {reviews.map((review) => (
                  <tr key={review.id}>
                    <td>
                      <div className="product-cell">
                        {review.product?.image_url ? (
                          <img loading="lazy" decoding="async"
                            className="product-thumb"
                            src={review.product.image_url}
                            alt={review.product.name}
                          />
                        ) : (
                          <div className="product-thumb product-thumb-empty">
                            <Star size={16} />
                          </div>
                        )}
                        <span className="product-name" title={review.product?.name}>
                          {review.product?.name || 'Producto Eliminado'}
                        </span>
                      </div>
                    </td>
                    <td>
                      <div className="customer-cell">
                        <span className="customer-name">{review.customer_name}</span>
                        {review.customer_email && (
                          <span className="customer-email">{review.customer_email}</span>
                        )}
                      </div>
                    </td>
                    <td>
                      <div className="rating-stars">
                        {Array.from({ length: 5 }).map((_, i) => (
                          <Star
                            key={i}
                            size={12}
                            fill={i < review.rating ? 'currentColor' : 'none'}
                          />
                        ))}
                      </div>
                    </td>
                    <td className="comment-cell">
                      <p title={review.comment || ''}>
                        {review.comment || <em className="comment-empty">Sin comentario</em>}
                      </p>
                    </td>
                    <td className="date-cell">
                      {new Date(review.created_at).toLocaleDateString()}
                    </td>
                    <td>
                      <button
                        type="button"
                        onClick={() => handleToggleApproval(review.id, review.is_approved)}
                        className={`badge-btn ${review.is_approved ? 'is-public' : 'is-hidden'}`}
                      >
                        {review.is_approved ? 'Público' : 'Oculto'}
                      </button>
                    </td>
                    <td className="actions-cell">
                      <div className="action-buttons">
                        <button
                          type="button"
                          onClick={() => handleToggleApproval(review.id, review.is_approved)}
                          className="btn-action"
                          title={review.is_approved ? 'Ocultar reseña' : 'Aprobar reseña'}
                        >
                          {review.is_approved ? <X size={14} /> : <Check size={14} />}
                        </button>
                        <button
                          type="button"
                          onClick={() => handleDelete(review.id, review.customer_name)}
                          className="btn-action btn-action-danger"
                          title="Eliminar reseña"
                        >
                          <Trash2 size={14} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {pagination && pagination.last_page > 1 && (
            <div className="pagination-bar">
              <span className="pagination-info">
                Mostrando {reviews.length} de {pagination.total} reseñas
              </span>
              <div className="pagination-buttons">
                <button
                  type="button"
                  className="page-btn"
                  disabled={page === 1}
                  onClick={() => setPage(page - 1)}
                >
                  <ChevronLeft size={16} /> Anterior
                </button>
                <button
                  type="button"
                  className="page-btn"
                  disabled={page === pagination.last_page}
                  onClick={() => setPage(page + 1)}
                >
                  Siguiente <ChevronRight size={16} />
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
