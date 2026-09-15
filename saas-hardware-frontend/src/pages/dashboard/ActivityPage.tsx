import './ActivityPage.css';

import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, History, Loader2 } from 'lucide-react';
import { getActividad } from '../../api/activity';
import { getTeamUsers } from '../../api/users';
import type { AreaDeActividad } from '../../types';

/** En el orden del menú del panel. */
const AREAS: { valor: AreaDeActividad; etiqueta: string }[] = [
  { valor: 'producto', etiqueta: 'Productos' },
  { valor: 'categoria', etiqueta: 'Categorías' },
  { valor: 'pedido', etiqueta: 'Pedidos' },
  { valor: 'pagina', etiqueta: 'Páginas' },
  { valor: 'resena', etiqueta: 'Reseñas' },
  { valor: 'espera', etiqueta: 'Lista de espera' },
  { valor: 'equipo', etiqueta: 'Equipo' },
  { valor: 'configuracion', etiqueta: 'Configuración' },
];

const etiquetaDeArea = (action: string) =>
  AREAS.find((a) => action.startsWith(`${a.valor}.`))?.etiqueta ?? 'Otro';

const fecha = new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' });

/**
 * Quién cambió qué en el panel (INF-3). Solo admin.
 *
 * Con una sola persona por tienda no hacía falta; con equipo (FUN-4), "¿quién
 * bajó este precio?" no tenía respuesta. Las líneas las escribe el backend
 * (`App\Support\Bitacora`) al guardar cada cambio: aquí solo se leen.
 *
 * Los filtros viven en la URL, como en el catálogo (UI-1): un enlace a "lo que
 * hizo Ana en pedidos" se puede guardar o pasar.
 */
export default function ActivityPage() {
  const [searchParams, setSearchParams] = useSearchParams();

  const area = (searchParams.get('area') as AreaDeActividad | null) || undefined;
  const actor = searchParams.get('persona') || undefined;
  const page = Math.max(1, Number(searchParams.get('pagina')) || 1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['actividad', area, actor, page],
    queryFn: () => getActividad({ area, actor, page }),
  });

  const { data: equipo = [] } = useQuery({
    // La misma clave que Equipo, para compartir la lista ya cargada.
    queryKey: ['teamUsers'],
    queryFn: getTeamUsers,
  });

  const cambiar = (cambios: Record<string, string | null>) => {
    const siguiente = new URLSearchParams(searchParams);
    Object.entries(cambios).forEach(([clave, valor]) => {
      if (valor) siguiente.set(clave, valor);
      else siguiente.delete(clave);
    });
    setSearchParams(siguiente);
  };

  // Al filtrar se vuelve a la primera página: la 4 de "todo" no existe en "pedidos".
  const filtrar = (clave: 'area' | 'persona', valor: string) => cambiar({ [clave]: valor || null, pagina: null });

  const lineas = data?.data ?? [];

  return (
    <div className="activity-page animate-fade-in page-activity">
      <div className="page-header">
        <div>
          <h1>Actividad</h1>
          <p className="page-description">
            Quién cambió qué en el panel de tu tienda: precios, pedidos, categorías, equipo y
            configuración. Solo lo ven los administradores.
          </p>
        </div>
      </div>

      <div className="glass-card filters-bar">
        <select
          className="premium-input filter-select"
          value={area ?? ''}
          onChange={(e) => filtrar('area', e.target.value)}
          aria-label="Filtrar por área"
        >
          <option value="">Todas las áreas</option>
          {AREAS.map((a) => (
            <option key={a.valor} value={a.valor}>{a.etiqueta}</option>
          ))}
        </select>

        <select
          className="premium-input filter-select"
          value={actor ?? ''}
          onChange={(e) => filtrar('persona', e.target.value)}
          aria-label="Filtrar por persona"
        >
          <option value="">Todo el equipo</option>
          {equipo.map((u) => (
            <option key={u.id} value={u.email}>{u.name}</option>
          ))}
          {/* Alguien que ya no está en el equipo, llegado por un enlace guardado. */}
          {actor && !equipo.some((u) => u.email === actor) && <option value={actor}>{actor}</option>}
        </select>
      </div>

      {isLoading ? (
        <div className="glass-card state-card">
          <Loader2 size={32} className="spinner" />
        </div>
      ) : isError ? (
        <div className="glass-card state-card">
          <h3>No se pudo cargar la actividad</h3>
          <p>Vuelve a intentarlo en unos segundos.</p>
        </div>
      ) : lineas.length === 0 ? (
        <div className="glass-card state-card">
          <History size={48} />
          <h3>{area || actor ? 'Nada con estos filtros' : 'Todavía no hay actividad'}</h3>
          <p>
            {area || actor
              ? 'Prueba con otra área o con todo el equipo.'
              : 'Cada cambio que alguien haga en el panel aparecerá aquí, con su nombre y la hora.'}
          </p>
        </div>
      ) : (
        <div className="glass-card list-card">
          <ul className="activity-list">
            {lineas.map((linea) => (
              <li key={linea.id} className="activity-item">
                <div className="activity-meta">
                  <span className="activity-actor">
                    {linea.actor?.name ?? linea.actor_email ?? 'Alguien'}
                    {!linea.actor && linea.actor_email && <span className="activity-gone"> (ya no está en el equipo)</span>}
                  </span>
                  {linea.actor_role && (
                    <span className="activity-role">{linea.actor_role === 'admin' ? 'Administrador' : 'Colaborador'}</span>
                  )}
                  <span className="activity-area">{etiquetaDeArea(linea.action)}</span>
                  <time className="activity-time" dateTime={linea.created_at}>
                    {fecha.format(new Date(linea.created_at))}
                  </time>
                </div>
                <p className="activity-description">{linea.description}</p>
              </li>
            ))}
          </ul>

          {data && data.last_page > 1 && (
            <div className="pagination-bar">
              <span className="pagination-info">
                Página {data.current_page} de {data.last_page} · {data.total} en total
              </span>
              <div className="pagination-buttons">
                <button
                  type="button"
                  className="page-btn"
                  onClick={() => cambiar({ pagina: String(page - 1) })}
                  disabled={page <= 1}
                >
                  <ChevronLeft size={14} /> Anterior
                </button>
                <button
                  type="button"
                  className="page-btn"
                  onClick={() => cambiar({ pagina: String(page + 1) })}
                  disabled={page >= data.last_page}
                >
                  Siguiente <ChevronRight size={14} />
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
