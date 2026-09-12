import './PlatformOverviewPage.css';

import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Loader2, TrendingUp } from 'lucide-react';
import { getPlatformStats } from '../../api/platform';
import type { PlatformStats } from '../../api/platform';

/**
 * Resumen del negocio (INF-2).
 *
 * Contesta lo que un listado no puede: si entran altas, cuántas arrancan y qué
 * se está usando. Hasta aquí el operador contaba las tiendas a mano en la tabla.
 *
 * **No hay ninguna cifra de dinero**, y es deliberado: cada tienda factura en su
 * moneda, así que sumar los totales de sus pedidos daría un número sin
 * significado con pinta de ingreso. El dinero de la plataforma es la
 * suscripción, y eso llega con la facturación (7.7b).
 */
export default function PlatformOverviewPage() {
  const { data, isLoading } = useQuery<PlatformStats>({
    queryKey: ['platformStats'],
    queryFn: getPlatformStats,
  });

  if (isLoading || !data) {
    return (
      <div className="platform-loader">
        <Loader2 className="spinner" size={28} />
      </div>
    );
  }

  const { tiendas, altas, planes, catalogo, actividad, ultimas_altas } = data;

  return (
    <div className="platform-overview animate-fade-in">
      <section className="stat-grid">
        <Cifra valor={tiendas.total} etiqueta="Tiendas" pie={`${tiendas.activas} activas · ${tiendas.suspendidas} suspendidas`} />
        <Cifra valor={tiendas.publicadas} etiqueta="Con el catálogo abierto" pie="Activas y publicadas" />
        {/* La única cifra donde MÁS es PEOR, así que va marcada: son altas que
            se registraron y no llegaron a subir nada. */}
        <Cifra
          valor={tiendas.sin_arrancar}
          etiqueta="Sin arrancar"
          pie="Activas sin ningún producto"
          alerta={tiendas.sin_arrancar > 0}
        />
        <Cifra valor={altas.semana} etiqueta="Altas esta semana" pie={`${altas.hoy} hoy · ${altas.mes} en 30 días`} />
      </section>

      <section className="overview-cols">
        <div className="glass-card overview-card">
          <h2>Reparto por plan</h2>
          {Object.keys(planes).length === 0 ? (
            <p className="overview-muted">Todavía no hay tiendas.</p>
          ) : (
            <ul className="plan-list">
              {Object.entries(planes).map(([plan, total]) => (
                <li key={plan}>
                  <span className="plan-name">{plan}</span>
                  <span className="plan-bar">
                    <span
                      className="plan-bar-fill"
                      style={{ width: `${tiendas.total ? (total / tiendas.total) * 100 : 0}%` }}
                    />
                  </span>
                  <strong>{total}</strong>
                </li>
              ))}
            </ul>
          )}
          <p className="overview-note">
            <TrendingUp size={13} /> Cuando haya cobro, esta es la línea que se convierte en ingreso
            recurrente.
          </p>
        </div>

        <div className="glass-card overview-card">
          <h2>Lo que hay dentro</h2>
          <dl className="overview-dl">
            <div>
              <dt>Productos</dt>
              <dd>{catalogo.productos}</dd>
            </div>
            <div>
              <dt>Pedidos</dt>
              <dd>{catalogo.pedidos}</dd>
            </div>
            <div>
              <dt>Pedidos esta semana</dt>
              <dd>{actividad.pedidos_semana}</dd>
            </div>
            <div>
              {/* Del equipo: los clientes registrados del catálogo comparten
                  tabla pero no son gente que trabaje en un panel. */}
              <dt>Personas en los paneles</dt>
              <dd>{catalogo.equipo}</dd>
            </div>
          </dl>
        </div>
      </section>

      <section className="glass-card overview-card">
        <h2>Últimas altas</h2>
        {ultimas_altas.length === 0 ? (
          <p className="overview-muted">Todavía no hay tiendas.</p>
        ) : (
          <ul className="altas-list">
            {ultimas_altas.map((tenant) => (
              <li key={tenant.id}>
                <Link to={`/platform/tenants/${tenant.id}`}>{tenant.name}</Link>
                <span className="altas-meta">
                  <span className="badge badge-neutral">{tenant.plan}</span>
                  {!tenant.is_active && <span className="badge badge-danger">Suspendida</span>}
                  {tenant.is_active && !tenant.is_published && (
                    <span className="badge badge-warning">Sin publicar</span>
                  )}
                  <time>{new Date(tenant.created_at).toLocaleDateString()}</time>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function Cifra({
  valor,
  etiqueta,
  pie,
  alerta = false,
}: {
  valor: number;
  etiqueta: string;
  pie: string;
  alerta?: boolean;
}) {
  return (
    <div className={`glass-card stat-card${alerta ? ' stat-card-alert' : ''}`}>
      <strong className="stat-value">{valor}</strong>
      <span className="stat-label">{etiqueta}</span>
      <span className="stat-foot">{pie}</span>
    </div>
  );
}
