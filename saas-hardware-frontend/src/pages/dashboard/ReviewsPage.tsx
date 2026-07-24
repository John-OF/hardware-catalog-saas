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
    <div className="reviews-page animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }}>
      <div className="page-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <h1 style={{ fontSize: '1.75rem', fontWeight: 700, margin: 0, fontFamily: 'var(--font-heading)' }}>
            Moderación de Reseñas
          </h1>
          <p style={{ color: 'var(--text-muted)', margin: '0.25rem 0 0 0', fontSize: '0.9rem' }}>
            Gestiona las calificaciones y comentarios que los clientes publican en tus productos.
          </p>
        </div>
      </div>

      {/* Filters */}
      <div className="glass-card" style={{ padding: '1.25rem', display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'center' }}>
        <div style={{ flex: 1, minWidth: '240px', position: 'relative' }}>
          <Search size={18} style={{ position: 'absolute', left: '0.75rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input
            type="text"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            placeholder="Buscar por cliente, comentario o producto..."
            style={{ width: '100%', padding: '0.6rem 0.6rem 0.6rem 2.25rem', background: 'rgba(var(--overlay-mix),0.03)', border: '1px solid var(--border)', borderRadius: 'var(--radius-md)', color: 'var(--text-primary)', fontSize: '0.9rem' }}
          />
        </div>

        <div style={{ width: '160px' }}>
          <select
            value={isApproved}
            onChange={(e) => { setIsApproved(e.target.value); setPage(1); }}
            style={{ width: '100%', padding: '0.6rem', background: 'rgba(var(--overlay-mix),0.03)', border: '1px solid var(--border)', borderRadius: 'var(--radius-md)', color: 'var(--text-primary)', fontSize: '0.9rem', outline: 'none' }}
          >
            <option value="" style={{ background: 'var(--bg-select-option)' }}>Todos los estados</option>
            <option value="true" style={{ background: 'var(--bg-select-option)' }}>Visibles (Aprobados)</option>
            <option value="false" style={{ background: 'var(--bg-select-option)' }}>Ocultos</option>
          </select>
        </div>

        <div style={{ width: '150px' }}>
          <select
            value={rating}
            onChange={(e) => { setRating(e.target.value); setPage(1); }}
            style={{ width: '100%', padding: '0.6rem', background: 'rgba(var(--overlay-mix),0.03)', border: '1px solid var(--border)', borderRadius: 'var(--radius-md)', color: 'var(--text-primary)', fontSize: '0.9rem', outline: 'none' }}
          >
            <option value="" style={{ background: 'var(--bg-select-option)' }}>Calificaciones</option>
            {[5, 4, 3, 2, 1].map((val) => (
              <option key={val} value={val} style={{ background: 'var(--bg-select-option)' }}>
                {val} {val === 1 ? 'estrella' : 'estrellas'}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="glass-card" style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '300px' }}>
          <Loader2 size={32} className="spinner" style={{ animation: 'spin 1s linear infinite', color: 'var(--primary)' }} />
        </div>
      ) : reviews.length === 0 ? (
        <div className="glass-card" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', minHeight: '300px', gap: '1rem', color: 'var(--text-muted)' }}>
          <MessageSquare size={48} />
          <h3>No se encontraron reseñas</h3>
          <p style={{ margin: 0, fontSize: '0.9rem' }}>Los clientes no han dejado calificaciones que coincidan con estos filtros.</p>
        </div>
      ) : (
        <div className="glass-card" style={{ overflow: 'hidden' }}>
          <div className="table-wrapper" style={{ overflowX: 'auto', width: '100%' }}>
            <table className="dashboard-table" style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '0.9rem' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                  <th style={{ padding: '1rem' }}>Producto</th>
                  <th style={{ padding: '1rem' }}>Cliente</th>
                  <th style={{ padding: '1rem' }}>Calificación</th>
                  <th style={{ padding: '1rem' }}>Comentario</th>
                  <th style={{ padding: '1rem' }}>Fecha</th>
                  <th style={{ padding: '1rem' }}>Visibilidad</th>
                  <th style={{ padding: '1rem', textAlign: 'right' }}>Acciones</th>
                </tr>
              </thead>
              <tbody>
                {reviews.map((review) => (
                  <tr key={review.id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td style={{ padding: '1rem' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                        {review.product?.image_url ? (
                          <img
                            src={review.product.image_url}
                            alt={review.product.name}
                            style={{ width: '40px', height: '40px', objectFit: 'contain', background: 'rgba(var(--overlay-mix),0.03)', borderRadius: '4px', border: '1px solid var(--border)' }}
                          />
                        ) : (
                          <div style={{ width: '40px', height: '40px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(var(--overlay-mix),0.03)', borderRadius: '4px', border: '1px solid var(--border)' }}>
                            <Star size={16} />
                          </div>
                        )}
                        <span style={{ fontWeight: 500, maxWidth: '180px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={review.product?.name}>
                          {review.product?.name || 'Producto Eliminado'}
                        </span>
                      </div>
                    </td>
                    <td style={{ padding: '1rem' }}>
                      <div style={{ display: 'flex', flexDirection: 'column' }}>
                        <span style={{ fontWeight: 600 }}>{review.customer_name}</span>
                        {review.customer_email && (
                          <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>{review.customer_email}</span>
                        )}
                      </div>
                    </td>
                    <td style={{ padding: '1rem' }}>
                      <div style={{ display: 'flex', color: '#fbbf24', gap: '0.1rem' }}>
                        {Array.from({ length: 5 }).map((_, i) => (
                          <Star
                            key={i}
                            size={12}
                            fill={i < review.rating ? '#fbbf24' : 'none'}
                          />
                        ))}
                      </div>
                    </td>
                    <td style={{ padding: '1rem', maxWidth: '300px' }}>
                      <p style={{ margin: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={review.comment || ''}>
                        {review.comment || <em style={{ color: 'var(--text-muted)' }}>Sin comentario</em>}
                      </p>
                    </td>
                    <td style={{ padding: '1rem', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                      {new Date(review.created_at).toLocaleDateString()}
                    </td>
                    <td style={{ padding: '1rem' }}>
                      <button
                        type="button"
                        onClick={() => handleToggleApproval(review.id, review.is_approved)}
                        className={`badge-btn ${review.is_approved ? 'badge-success' : 'badge-danger'}`}
                        style={{
                          border: 'none',
                          cursor: 'pointer',
                          padding: '0.25rem 0.5rem',
                          borderRadius: '4px',
                          fontSize: '0.75rem',
                          fontWeight: 600,
                          transition: 'var(--transition)'
                        }}
                      >
                        {review.is_approved ? 'Público' : 'Oculto'}
                      </button>
                    </td>
                    <td style={{ padding: '1rem', textAlign: 'right' }}>
                      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.5rem' }}>
                        <button
                          type="button"
                          onClick={() => handleToggleApproval(review.id, review.is_approved)}
                          className="btn-action"
                          title={review.is_approved ? 'Ocultar reseña' : 'Aprobar reseña'}
                          style={{ background: 'rgba(var(--overlay-mix),0.04)', border: '1px solid var(--border)', color: 'var(--text-primary)', borderRadius: '4px', padding: '0.4rem', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                        >
                          {review.is_approved ? <X size={14} /> : <Check size={14} />}
                        </button>
                        <button
                          type="button"
                          onClick={() => handleDelete(review.id, review.customer_name)}
                          className="btn-action btn-action-danger"
                          title="Eliminar reseña"
                          style={{ background: 'rgba(239, 68, 68, 0.1)', border: '1px solid rgba(239, 68, 68, 0.2)', color: '#ef4444', borderRadius: '4px', padding: '0.4rem', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
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
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '1rem', borderTop: '1px solid var(--border)', flexWrap: 'wrap', gap: '1rem' }}>
              <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>
                Mostrando {reviews.length} de {pagination.total} reseñas
              </span>
              <div style={{ display: 'flex', gap: '0.5rem' }}>
                <button
                  type="button"
                  disabled={page === 1}
                  onClick={() => setPage(page - 1)}
                  style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', padding: '0.4rem 0.8rem', background: 'rgba(var(--overlay-mix),0.04)', border: '1px solid var(--border)', borderRadius: '4px', color: 'var(--text-primary)', fontSize: '0.85rem', cursor: page === 1 ? 'not-allowed' : 'pointer', opacity: page === 1 ? 0.5 : 1 }}
                >
                  <ChevronLeft size={16} /> Anterior
                </button>
                <button
                  type="button"
                  disabled={page === pagination.last_page}
                  onClick={() => setPage(page + 1)}
                  style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', padding: '0.4rem 0.8rem', background: 'rgba(var(--overlay-mix),0.04)', border: '1px solid var(--border)', borderRadius: '4px', color: 'var(--text-primary)', fontSize: '0.85rem', cursor: page === pagination.last_page ? 'not-allowed' : 'pointer', opacity: page === pagination.last_page ? 0.5 : 1 }}
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
