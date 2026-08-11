import { useEffect, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
  ShieldCheck,
  Search,
  Loader2,
  Ban,
  Play,
  KeyRound,
  ExternalLink,
  LogOut,
} from 'lucide-react';
import {
  getPlatformTenants,
  platformLogout,
  sendTenantPasswordReset,
  updatePlatformTenant,
} from '../../api/platform';
import type { PlatformTenant } from '../../api/platform';
import type { PaginatedResponse } from '../../types';
import { usePlatformAuthStore } from '../../stores/platformAuthStore';

type ApiError = { response?: { data?: { message?: string } } };

const PLANES = ['free', 'pro', 'enterprise'];

export default function PlatformPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { isAuthenticated, clearPlatformAuth } = usePlatformAuthStore();

  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [status, setStatus] = useState<'' | 'active' | 'suspended'>('');

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(timer);
  }, [search]);

  const { data, isLoading } = useQuery<PaginatedResponse<PlatformTenant>>({
    queryKey: ['platformTenants', debouncedSearch, status],
    queryFn: () =>
      getPlatformTenants({
        search: debouncedSearch || undefined,
        status: status || undefined,
      }),
    enabled: isAuthenticated,
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: { is_active?: boolean; plan?: string } }) =>
      updatePlatformTenant(id, payload),
    onSuccess: (tenant) => {
      queryClient.invalidateQueries({ queryKey: ['platformTenants'] });
      toast.success(tenant.is_active ? `${tenant.name} está activa` : `${tenant.name} quedó suspendida`);
    },
    onError: (error) => {
      toast.error((error as ApiError).response?.data?.message || 'No se pudo actualizar la tienda.');
    },
  });

  const resetMutation = useMutation({
    mutationFn: (id: string) => sendTenantPasswordReset(id),
    onSuccess: (res) => toast.success(res.message),
    onError: (error) => {
      toast.error((error as ApiError).response?.data?.message || 'No se pudo enviar el enlace.');
    },
  });

  const handleLogout = async () => {
    try {
      await platformLogout();
    } finally {
      clearPlatformAuth();
      navigate('/platform/login', { replace: true });
    }
  };

  if (!isAuthenticated) return <Navigate to="/platform/login" replace />;

  const tenants = data?.data ?? [];

  return (
    <div className="platform-page animate-fade-in">
      <header className="platform-header">
        <div className="platform-title">
          <ShieldCheck size={20} />
          <div>
            <h1>Tiendas de la plataforma</h1>
            <p>{data ? `${data.total} tienda(s) registradas` : 'Cargando...'}</p>
          </div>
        </div>
        <button type="button" className="btn-secondary" onClick={handleLogout}>
          <LogOut size={15} /> Salir
        </button>
      </header>

      <div className="platform-filters glass-card">
        <div className="platform-search">
          <Search size={16} className="platform-search-icon" />
          <input
            type="text"
            className="premium-input platform-search-input"
            placeholder="Buscar por nombre, slug o dominio..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <select
          className="premium-input"
          value={status}
          onChange={(e) => setStatus(e.target.value as '' | 'active' | 'suspended')}
        >
          <option value="">Todas</option>
          <option value="active">Activas</option>
          <option value="suspended">Suspendidas</option>
        </select>
      </div>

      {isLoading ? (
        <div className="platform-loader">
          <Loader2 className="spinner" size={28} />
        </div>
      ) : tenants.length === 0 ? (
        <div className="glass-card platform-empty">No hay tiendas que coincidan.</div>
      ) : (
        <div className="glass-card platform-table-wrap">
          <table className="platform-table">
            <thead>
              <tr>
                <th>Tienda</th>
                <th>Plan</th>
                <th>Contenido</th>
                <th>Alta</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              {tenants.map((tenant) => (
                <tr key={tenant.id} className={tenant.is_active ? '' : 'row-suspended'}>
                  <td>
                    <strong>{tenant.name}</strong>
                    <a
                      className="tenant-slug"
                      href={`/${tenant.slug}`}
                      target="_blank"
                      rel="noreferrer"
                    >
                      /{tenant.slug} <ExternalLink size={11} />
                    </a>
                  </td>
                  <td>
                    <select
                      className="premium-input plan-select"
                      value={tenant.plan}
                      disabled={updateMutation.isPending}
                      onChange={(e) =>
                        updateMutation.mutate({ id: tenant.id, payload: { plan: e.target.value } })
                      }
                    >
                      {PLANES.map((plan) => (
                        <option key={plan} value={plan}>{plan}</option>
                      ))}
                    </select>
                  </td>
                  <td className="counts-cell">
                    {tenant.products_count} prod · {tenant.orders_count} ped · {tenant.users_count} usu
                  </td>
                  <td className="date-cell">{new Date(tenant.created_at).toLocaleDateString()}</td>
                  <td>
                    <span className={`badge ${tenant.is_active ? 'badge-success' : 'badge-danger'}`}>
                      {tenant.is_active ? 'Activa' : 'Suspendida'}
                    </span>
                  </td>
                  <td className="actions-cell">
                    <button
                      type="button"
                      className="btn-secondary btn-mini"
                      disabled={updateMutation.isPending}
                      onClick={() =>
                        updateMutation.mutate({
                          id: tenant.id,
                          payload: { is_active: !tenant.is_active },
                        })
                      }
                      title={tenant.is_active ? 'Suspender la tienda' : 'Reactivar la tienda'}
                    >
                      {tenant.is_active ? <><Ban size={14} /> Suspender</> : <><Play size={14} /> Reactivar</>}
                    </button>
                    <button
                      type="button"
                      className="btn-secondary btn-mini"
                      disabled={resetMutation.isPending}
                      onClick={() => resetMutation.mutate(tenant.id)}
                      title="Enviar al dueño el enlace para restablecer su contraseña"
                    >
                      <KeyRound size={14} /> Rescatar acceso
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <style>{`
        .platform-page {
          min-height: 100vh;
          background: var(--bg-app);
          padding: 2rem;
          display: flex;
          flex-direction: column;
          gap: 1.25rem;
          /* #root es flex column: sin min-width los hijos no encogen por debajo
             de su ancho intrinseco y la tabla arrastra la pagina entera. */
          min-width: 0;
        }

        .platform-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 1rem;
          flex-wrap: wrap;
        }

        .platform-title {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          color: var(--primary);
        }

        .platform-title h1 {
          font-size: 1.35rem;
          color: var(--text-primary);
        }

        .platform-title p {
          font-size: 0.85rem;
          color: var(--text-secondary);
        }

        .platform-filters {
          display: flex;
          gap: 0.75rem;
          padding: 0.85rem 1rem;
          border-radius: var(--radius-lg);
          flex-wrap: wrap;
        }

        .platform-search {
          position: relative;
          display: flex;
          align-items: center;
          flex: 1;
          min-width: 220px;
        }

        .platform-search-icon {
          position: absolute;
          left: 0.75rem;
          color: var(--text-muted);
          pointer-events: none;
        }

        .platform-search-input {
          width: 100%;
          padding-left: 2.35rem;
        }

        .platform-loader,
        .platform-empty {
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 3rem;
          color: var(--text-secondary);
          border-radius: var(--radius-lg);
        }

        .platform-table-wrap {
          border-radius: var(--radius-lg);
          /* min-width:0 es lo que hace efectivo el overflow-x dentro del flex. */
          min-width: 0;
          max-width: 100%;
          overflow-x: auto;
        }

        .platform-table {
          width: 100%;
          border-collapse: collapse;
          font-size: 0.86rem;
        }

        .platform-table th {
          text-align: left;
          padding: 0.85rem 1rem;
          font-size: 0.72rem;
          text-transform: uppercase;
          letter-spacing: 0.05em;
          color: var(--text-muted);
          border-bottom: 1px solid var(--border);
          white-space: nowrap;
        }

        .platform-table td {
          padding: 0.85rem 1rem;
          border-bottom: 1px solid var(--border);
          color: var(--text-primary);
          vertical-align: middle;
        }

        .platform-table tbody tr:last-child td {
          border-bottom: none;
        }

        .row-suspended {
          opacity: 0.6;
        }

        .tenant-slug {
          display: flex;
          align-items: center;
          gap: 0.25rem;
          margin-top: 0.15rem;
          font-size: 0.78rem;
          color: var(--text-secondary);
        }

        .tenant-slug:hover {
          color: var(--primary);
        }

        .plan-select {
          padding: 0.3rem 0.5rem;
          font-size: 0.8rem;
          width: auto;
        }

        .counts-cell,
        .date-cell {
          color: var(--text-secondary);
          white-space: nowrap;
        }

        .actions-cell {
          display: flex;
          gap: 0.4rem;
          flex-wrap: wrap;
        }

        .btn-mini {
          display: inline-flex;
          align-items: center;
          gap: 0.3rem;
          padding: 0.35rem 0.6rem;
          font-size: 0.78rem;
        }

        .spinner {
          animation: spin 1s linear infinite;
        }

        @keyframes spin {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }

        @media (max-width: 720px) {
          .platform-page {
            padding: 1rem;
          }
        }
      `}</style>
    </div>
  );
}
