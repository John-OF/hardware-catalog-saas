import './PlatformLayout.css';

import { Navigate, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { KeyRound, LayoutDashboard, ListChecks, LogOut, ShieldAlert, ShieldCheck, Store } from 'lucide-react';
import { getPlatformMe, platformLogout, RUTA_DE_SEGURIDAD } from '../../api/platform';
import { usePlatformAuthStore } from '../../stores/platformAuthStore';

/**
 * Marco del panel del operador del SaaS (INF-2).
 *
 * Antes `/platform` era una pantalla suelta: el listado de tiendas con su
 * cabecera y su botón de salir dentro. Con tres secciones eso ya no vale, y
 * repetir cabecera y guarda de sesión en cada una es la forma segura de que la
 * tercera nazca sin alguna de las dos.
 *
 * La comprobación de sesión vive AQUÍ y no en cada página por el mismo motivo:
 * una ruta nueva colgada de este layout está protegida sin que nadie se acuerde.
 * No es la barrera de verdad —esa es el token, que el backend exige en cada
 * petición—, solo evita pintar un panel que no va a cargar nada.
 *
 * Igual con la verificación en dos pasos (SEC-9): en producción, sin ella el
 * backend responde 403 a todo salvo a activarla, así que aquí se manda directo
 * a esa pantalla en vez de pintar secciones que no van a cargar.
 */
export default function PlatformLayout() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const { isAuthenticated, user, clearPlatformAuth } = usePlatformAuthStore();

  // Antes del `return` temprano: un hook detrás de él rompe la pantalla (AUD-14).
  const { data: me } = useQuery({
    queryKey: ['platformMe'],
    queryFn: getPlatformMe,
    enabled: isAuthenticated,
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

  const sinDosPasos = me !== undefined && !me.seguridad.dos_pasos;

  if (sinDosPasos && me.seguridad.obligatoria && pathname !== RUTA_DE_SEGURIDAD) {
    return <Navigate to={RUTA_DE_SEGURIDAD} replace />;
  }

  return (
    <div className="platform-shell page-platform">
      <header className="platform-header">
        <div className="platform-title">
          <ShieldCheck size={20} />
          <div>
            <h1>Administración de plataforma</h1>
            <p>{user?.email ?? me?.user.email ?? 'Operador del SaaS'}</p>
          </div>
        </div>

        <nav className="platform-nav">
          {/* `end` solo en el resumen: sin él, /platform quedaría marcado
              también estando en /platform/tenants, porque es su prefijo. */}
          <NavLink to="/platform" end className="platform-nav-link">
            <LayoutDashboard size={15} /> Resumen
          </NavLink>
          <NavLink to="/platform/tenants" className="platform-nav-link">
            <Store size={15} /> Tiendas
          </NavLink>
          <NavLink to="/platform/logs" className="platform-nav-link">
            <ListChecks size={15} /> Bitácora
          </NavLink>
          {/* SEC-9: con aviso mientras la verificación en dos pasos no esté activa. */}
          <NavLink
            to={RUTA_DE_SEGURIDAD}
            className="platform-nav-link"
            title={sinDosPasos ? 'La verificación en dos pasos no está activada' : undefined}
          >
            {sinDosPasos ? <ShieldAlert size={15} className="platform-nav-alerta" /> : <KeyRound size={15} />} Seguridad
          </NavLink>
        </nav>

        <button type="button" className="btn-secondary" onClick={handleLogout}>
          <LogOut size={15} /> Salir
        </button>
      </header>

      <Outlet />
    </div>
  );
}
