import './PlatformPage.css';

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
    <div className="platform-page animate-fade-in page-platform">
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

    </div>
  );
}
