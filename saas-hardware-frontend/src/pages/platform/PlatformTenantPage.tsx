import './PlatformTenantPage.css';

import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
  AlertTriangle,
  ArrowLeft,
  Ban,
  ExternalLink,
  Eye,
  KeyRound,
  Loader2,
  Play,
  ShieldCheck,
} from 'lucide-react';
import {
  getPlatformTenant,
  impersonateTenant,
  rescueAdmin,
  sendTenantPasswordReset,
  updatePlatformTenant,
} from '../../api/platform';
import type { PlatformTenantDetail } from '../../api/platform';
import { useAuthStore } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';

type ApiError = { response?: { data?: { message?: string } } };

const PLANES = ['free', 'pro', 'enterprise'];

/** Cómo se llama cada límite del plan en pantalla (las claves vienen de config/plans.php). */
const NOMBRES: Record<string, string> = {
  products: 'Productos',
  categories: 'Categorías',
  pages: 'Páginas',
  users: 'Equipo',
  images_per_product: 'Imágenes por producto',
  custom_domain: 'Dominio propio',
  csv_import: 'Importar CSV',
};

/**
 * Ficha de una tienda (INF-2).
 *
 * El listado sirve para encontrarla; esto para entenderla cuando alguien
 * escribe pidiendo ayuda. Antes, saber quién es su admin o por qué no puede
 * subir más productos exigía abrir la base de datos.
 */
export default function PlatformTenantPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const setAuth = useAuthStore((s) => s.setAuth);
  const setSoporte = useAuthStore((s) => s.setSoporte);
  const setTenant = useTenantStore((s) => s.setTenant);

  // Del rescate de abajo: a quién se va a nombrar administrador.
  const [candidato, setCandidato] = useState('');

  const { data, isLoading } = useQuery<PlatformTenantDetail>({
    queryKey: ['platformTenant', id],
    queryFn: () => getPlatformTenant(id),
    enabled: !!id,
  });

  const refrescar = () => {
    queryClient.invalidateQueries({ queryKey: ['platformTenant', id] });
    queryClient.invalidateQueries({ queryKey: ['platformTenants'] });
    queryClient.invalidateQueries({ queryKey: ['platformLogs'] });
    queryClient.invalidateQueries({ queryKey: ['platformStats'] });
  };

  const updateMutation = useMutation({
    mutationFn: (payload: { is_active?: boolean; plan?: string }) => updatePlatformTenant(id, payload),
    onSuccess: (tenant) => {
      refrescar();
      toast.success(tenant.is_active ? `${tenant.name} está activa` : `${tenant.name} quedó suspendida`);
    },
    onError: (error) => {
      toast.error((error as ApiError).response?.data?.message || 'No se pudo actualizar la tienda.');
    },
  });

  const resetMutation = useMutation({
    mutationFn: () => sendTenantPasswordReset(id),
    onSuccess: (res) => {
      refrescar();
      toast.success(res.message);
    },
    onError: (error) => {
      toast.error((error as ApiError).response?.data?.message || 'No se pudo enviar el enlace.');
    },
  });

  const rescueMutation = useMutation({
    mutationFn: (userId: string) => rescueAdmin(id, userId),
    onSuccess: (usuario) => {
      refrescar();
      setCandidato('');
      toast.success(`${usuario.name} ya es administrador. Se le cerró la sesión para que entre con su nuevo acceso.`);
    },
    onError: (error) => {
      toast.error((error as ApiError).response?.data?.message || 'No se pudo nombrar administrador.');
    },
  });

  const soporteMutation = useMutation({
    mutationFn: () => impersonateTenant(id),
    onSuccess: (res) => {
      // La sesión de la tienda se monta con las MISMAS piezas que un login
      // normal —token en `authStore`, slug en `tenantStore`— porque a partir de
      // aquí el panel es el de siempre. La diferencia la marca el token, no el
      // navegador: es de solo lectura y caduca solo.
      setAuth(res.token, res.user);
      setTenant(res.tenant);
      // Se marca ya, sin esperar a `getMe()`, para que el panel no llegue a
      // pintarse ni un instante sin el aviso de que se está en casa ajena.
      setSoporte(true);
      toast.success(res.message);
      navigate('/dashboard');
    },
    onError: (error) => {
      toast.error((error as ApiError).response?.data?.message || 'No se pudo entrar como soporte.');
    },
  });

  if (isLoading || !data) {
    return (
      <div className="platform-loader">
        <Loader2 className="spinner" size={28} />
      </div>
    );
  }

  const { tenant, plan, equipo, clientes, ultimos_pedidos, bitacora } = data;

  // Puede pasar por la carrera que cierra `UserController::impedirQuedarseSinAdmins`
  // o porque alguien desactivó al último a mano. Sin esto no hay forma de saber
  // por qué el dueño escribe "no puedo entrar a Configuración" sin abrir la base.
  const sinAdminActivo = !equipo.some((u) => u.role === 'admin' && u.is_active);
  const candidatosRescate = equipo.filter((u) => u.role === 'staff' && u.is_active);

  return (
    <div className="tenant-detail animate-fade-in">
      <div className="tenant-detail-top">
        <Link to="/platform/tenants" className="volver">
          <ArrowLeft size={15} /> Tiendas
        </Link>
        <div className="tenant-detail-badges">
          <span className={`badge ${tenant.is_active ? 'badge-success' : 'badge-danger'}`}>
            {tenant.is_active ? 'Activa' : 'Suspendida'}
          </span>
          {/* Publicada y activa no son lo mismo: una tienda activa sin publicar
              tiene el panel abierto y el catálogo cerrado (FUN-5). */}
          {tenant.is_active && !tenant.is_published && (
            <span className="badge badge-warning">Catálogo sin publicar</span>
          )}
          <span className="badge badge-neutral">{plan.label}</span>
        </div>
      </div>

      {/* Puerta de emergencia, no un reparto de roles: solo sale cuando de
          verdad no hay ningún admin activo, y el backend lo vuelve a comprobar
          aunque esta condición se equivocara. */}
      {sinAdminActivo && (
        <div className="glass-card tenant-rescue">
          <div className="tenant-rescue-text">
            <AlertTriangle size={18} />
            <div>
              <strong>Esta tienda se quedó sin ningún administrador activo.</strong>
              <span>
                Nadie de su equipo puede entrar a Configuración, Categorías, Páginas ni Equipo, y
                no hay a quién mandarle el enlace de recuperación. Nombra administrador a un
                colaborador activo para que la tienda vuelva a poder gestionarse sola.
              </span>
            </div>
          </div>

          {candidatosRescate.length === 0 ? (
            <p className="tenant-rescue-vacio">
              Tampoco tiene ningún colaborador activo: no hay a quién ascender desde aquí.
            </p>
          ) : (
            <div className="tenant-rescue-actions">
              <select
                className="premium-input"
                value={candidato}
                disabled={rescueMutation.isPending}
                onChange={(e) => setCandidato(e.target.value)}
              >
                <option value="">Elige a quién nombrar administrador…</option>
                {candidatosRescate.map((u) => (
                  <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
                ))}
              </select>
              <button
                type="button"
                className="btn-primary btn-mini"
                disabled={!candidato || rescueMutation.isPending}
                onClick={() => candidato && rescueMutation.mutate(candidato)}
              >
                {rescueMutation.isPending ? <Loader2 size={14} className="spinner" /> : <ShieldCheck size={14} />}
                Nombrar administrador
              </button>
            </div>
          )}
        </div>
      )}

      <header className="glass-card tenant-head">
        <div>
          <h2>{tenant.name}</h2>
          <a href={`/${tenant.slug}`} target="_blank" rel="noreferrer" className="tenant-slug">
            /{tenant.slug} <ExternalLink size={12} />
          </a>
          {tenant.custom_domain && <p className="tenant-domain">{tenant.custom_domain}</p>}
          <p className="tenant-meta">
            Moneda {tenant.currency} · {tenant.products_count} productos · {tenant.orders_count} pedidos
          </p>
        </div>

        <div className="tenant-actions">
          <select
            className="premium-input plan-select"
            value={tenant.plan}
            disabled={updateMutation.isPending}
            onChange={(e) => updateMutation.mutate({ plan: e.target.value })}
          >
            {PLANES.map((p) => (
              <option key={p} value={p}>{p}</option>
            ))}
          </select>

          <button
            type="button"
            className="btn-secondary btn-mini"
            disabled={updateMutation.isPending}
            onClick={() => updateMutation.mutate({ is_active: !tenant.is_active })}
          >
            {tenant.is_active ? <><Ban size={14} /> Suspender</> : <><Play size={14} /> Reactivar</>}
          </button>

          <button
            type="button"
            className="btn-secondary btn-mini"
            disabled={resetMutation.isPending}
            onClick={() => resetMutation.mutate()}
            title="Mandar al dueño el enlace para restablecer su contraseña"
          >
            <KeyRound size={14} /> Rescatar acceso
          </button>

          <button
            type="button"
            className="btn-primary btn-mini"
            disabled={soporteMutation.isPending || !tenant.is_active}
            onClick={() => soporteMutation.mutate()}
            title={
              tenant.is_active
                ? 'Ver su panel en modo soporte: 15 minutos y solo lectura'
                : 'Reactiva la tienda antes de entrar como soporte'
            }
          >
            {soporteMutation.isPending ? <Loader2 size={14} className="spinner" /> : <Eye size={14} />}
            Entrar como soporte
          </button>
        </div>
      </header>

      <section className="tenant-cols">
        <div className="glass-card tenant-card">
          <h3>Plan {plan.label}</h3>
          <ul className="limites">
            {Object.entries(plan.limites).map(([clave, tope]) => {
              const usado = plan.uso[clave];
              const nombre = NOMBRES[clave] ?? clave;

              // Un booleano es una función incluida o no; un número, un tope;
              // `null`, sin tope (ver la cabecera de config/plans.php).
              if (typeof tope === 'boolean') {
                return (
                  <li key={clave}>
                    <span>{nombre}</span>
                    <span className={tope ? 'lim-ok' : 'lim-no'}>{tope ? 'Incluido' : 'No incluido'}</span>
                  </li>
                );
              }

              if (usado === undefined) {
                return (
                  <li key={clave}>
                    <span>{nombre}</span>
                    <span className="lim-muted">{tope === null ? 'Sin tope' : tope}</span>
                  </li>
                );
              }

              // Puede estar POR ENCIMA del tope y no es un error: bajar de plan
              // nunca borra nada, solo impide crear más.
              const pasado = tope !== null && usado >= tope;

              return (
                <li key={clave}>
                  <span>{nombre}</span>
                  <span className={pasado ? 'lim-full' : ''}>
                    {usado} / {tope === null ? '∞' : tope}
                  </span>
                </li>
              );
            })}
          </ul>
        </div>

        <div className="glass-card tenant-card">
          <h3>Equipo ({equipo.length})</h3>
          {equipo.length === 0 ? (
            <p className="tenant-muted">Esta tienda no tiene a nadie en el panel.</p>
          ) : (
            <ul className="equipo">
              {equipo.map((u) => (
                <li key={u.id}>
                  <div>
                    <strong>{u.name}</strong>
                    <span className="equipo-mail">{u.email}</span>
                  </div>
                  <span className="equipo-tags">
                    <span className="badge badge-neutral">{u.role}</span>
                    {!u.is_active && <span className="badge badge-danger">Inactivo</span>}
                    {u.invitation_pending && <span className="badge badge-warning">Sin entrar</span>}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <p className="tenant-note">
            {clientes} cliente(s) registrados en el catálogo. No cuentan para el tope del plan.
          </p>
        </div>
      </section>

      <section className="tenant-cols">
        <div className="glass-card tenant-card">
          <h3>Últimos pedidos</h3>
          {ultimos_pedidos.length === 0 ? (
            <p className="tenant-muted">Todavía no ha recibido ningún pedido.</p>
          ) : (
            <ul className="pedidos">
              {ultimos_pedidos.map((p) => (
                <li key={p.id}>
                  <span>#{p.number} · {p.customer_name}</span>
                  <span className="pedido-meta">
                    <span className="badge badge-neutral">{p.status}</span>
                    <time>{new Date(p.created_at).toLocaleDateString()}</time>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="glass-card tenant-card">
          <h3>Qué se le ha hecho a esta tienda</h3>
          {bitacora.length === 0 ? (
            <p className="tenant-muted">Ningún operador ha tocado esta tienda todavía.</p>
          ) : (
            <ul className="bitacora">
              {bitacora.map((l) => (
                <li key={l.id}>
                  <span>{l.description}</span>
                  <span className="bitacora-meta">
                    {l.actor_email ?? 'operador borrado'} ·{' '}
                    {new Date(l.created_at).toLocaleString()}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </div>
  );
}
