import './PlatformLogsPage.css';

import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { getPlatformLogs } from '../../api/platform';
import type { ActivityLogEntry } from '../../api/platform';
import type { PaginatedResponse } from '../../types';

/** Los verbos que hoy escribe el backend (App\Models\ActivityLog). */
const ACCIONES: Record<string, string> = {
  'tenant.suspendida': 'Suspensiones',
  'tenant.reactivada': 'Reactivaciones',
  'tenant.plan': 'Cambios de plan',
  'tenant.reset_password': 'Rescates de acceso',
  'tenant.soporte': 'Entradas como soporte',
};

/**
 * Bitácora del operador (INF-2).
 *
 * Es el requisito que hizo posible "entrar como soporte": una llave que abre
 * tiendas ajenas sin dejar rastro no debe existir, aunque solo lea. Aquí se ve
 * quién hizo qué, en qué tienda, desde qué IP y cuándo.
 *
 * Solo se lee: no hay borrar ni editar, ni en la interfaz ni en la API. Una
 * bitácora que se puede limpiar no prueba nada.
 */
export default function PlatformLogsPage() {
  const [action, setAction] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery<PaginatedResponse<ActivityLogEntry>>({
    queryKey: ['platformLogs', action, page],
    queryFn: () => getPlatformLogs({ action: action || undefined, page }),
  });

  const logs = data?.data ?? [];

  return (
    <div className="platform-logs animate-fade-in">
      <div className="platform-filters glass-card">
        <select
          className="premium-input"
          value={action}
          onChange={(e) => {
            setAction(e.target.value);
            // Sin esto, filtrar desde la página 3 pide la página 3 de un
            // resultado que a lo mejor solo tiene una, y sale vacío.
            setPage(1);
          }}
        >
          <option value="">Todas las acciones</option>
          {Object.entries(ACCIONES).map(([clave, nombre]) => (
            <option key={clave} value={clave}>{nombre}</option>
          ))}
        </select>
        <span className="platform-count">{data ? `${data.total} movimiento(s)` : 'Cargando...'}</span>
      </div>

      {isLoading ? (
        <div className="platform-loader">
          <Loader2 className="spinner" size={28} />
        </div>
      ) : logs.length === 0 ? (
        <div className="glass-card platform-empty">
          No hay nada anotado todavía.
        </div>
      ) : (
        <ul className="glass-card log-list">
          {logs.map((log) => (
            <li key={log.id}>
              <div className="log-main">
                <span className="log-desc">{log.description}</span>
                <span className="log-meta">
                  {log.actor_email ?? 'operador borrado'}
                  {log.ip && <> · {log.ip}</>} · {new Date(log.created_at).toLocaleString()}
                </span>
              </div>
              {/* Si la tienda se borró, la relación viene vacía y el nombre lo
                  guarda el snapshot de `context` (por eso se escribe allí). */}
              {log.tenant ? (
                <Link className="log-tenant" to={`/platform/tenants/${log.tenant.id}`}>
                  {log.tenant.name}
                </Link>
              ) : (
                <span className="log-tenant log-tenant-gone">
                  {(log.context?.tienda as string) ?? '—'} (borrada)
                </span>
              )}
            </li>
          ))}
        </ul>
      )}

      {data && data.last_page > 1 && (
        <div className="log-pager">
          <button
            type="button"
            className="btn-secondary btn-mini"
            disabled={page <= 1}
            onClick={() => setPage((p) => p - 1)}
          >
            Anterior
          </button>
          <span>Página {data.current_page} de {data.last_page}</span>
          <button
            type="button"
            className="btn-secondary btn-mini"
            disabled={page >= data.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Siguiente
          </button>
        </div>
      )}
    </div>
  );
}
