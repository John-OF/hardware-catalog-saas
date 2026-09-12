import './PlatformPage.css';

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
  Search,
  Loader2,
  Ban,
  Play,
  KeyRound,
  ExternalLink,
} from 'lucide-react';
import {
  getPlatformTenants,
  sendTenantPasswordReset,
  updatePlatformTenant,
} from '../../api/platform';
import type { PlatformTenant } from '../../api/platform';
import type { PaginatedResponse } from '../../types';

type ApiError = { response?: { data?: { message?: string } } };

const PLANES = ['free', 'pro', 'enterprise'];

/**
 * Listado de tiendas del operador (SAAS-4).
 *
 * Es la pantalla de BUSCAR y de las dos acciones rápidas que se hacen sin mirar
 * nada más (suspender y cambiar de plan). Entender una tienda —su equipo, su
 * consumo frente al plan, sus últimos pedidos— es la ficha, y por eso el nombre
 * es un enlace: desde `INF-2` esta tabla ya no intenta contarlo todo.
 *
 * La cabecera, la sesión y la navegación son de `PlatformLayout`.
 */
export default function PlatformPage() {
  const queryClient = useQueryClient();

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
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: { is_active?: boolean; plan?: string } }) =>
      updatePlatformTenant(id, payload),
    onSuccess: (tenant) => {
      queryClient.invalidateQueries({ queryKey: ['platformTenants'] });
      // La ficha y la bitácora hablan de lo mismo: si no se invalidan, quedan
      // enseñando el plan de antes y sin la línea que acaba de escribirse.
      queryClient.invalidateQueries({ queryKey: ['platformTenant', tenant.id] });
      queryClient.invalidateQueries({ queryKey: ['platformLogs'] });
      toast.success(tenant.is_active ? `${tenant.name} está activa` : `${tenant.name} quedó suspendida`);
    },
    onError: (error) => {
      toast.error((error as ApiError).response?.data?.message || 'No se pudo actualizar la tienda.');
    },
  });

  const resetMutation = useMutation({
    mutationFn: (id: string) => sendTenantPasswordReset(id),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['platformLogs'] });
      toast.success(res.message);
    },
    onError: (error) => {
      toast.error((error as ApiError).response?.data?.message || 'No se pudo enviar el enlace.');
    },
  });

  const tenants = data?.data ?? [];

  return (
    <div className="platform-list animate-fade-in">
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
        <span className="platform-count">
          {data ? `${data.total} tienda(s)` : 'Cargando...'}
        </span>
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
                    <Link className="tenant-name" to={`/platform/tenants/${tenant.id}`}>
                      {tenant.name}
                    </Link>
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
