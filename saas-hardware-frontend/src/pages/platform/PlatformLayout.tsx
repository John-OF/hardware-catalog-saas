import './PlatformLayout.css';

import { Navigate, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { LayoutDashboard, ListChecks, LogOut, ShieldCheck, Store } from 'lucide-react';
import { platformLogout } from '../../api/platform';
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
 */
export default function PlatformLayout() {
  const navigate = useNavigate();
  const { isAuthenticated, user, clearPlatformAuth } = usePlatformAuthStore();

  const handleLogout = async () => {
    try {
      await platformLogout();
    } finally {
      clearPlatformAuth();
      navigate('/platform/login', { replace: true });
    }
  };

  if (!isAuthenticated) return <Navigate to="/platform/login" replace />;

  return (
    <div className="platform-shell page-platform">
      <header className="platform-header">
        <div className="platform-title">
          <ShieldCheck size={20} />
          <div>
            <h1>Administración de plataforma</h1>
            <p>{user?.email ?? 'Operador del SaaS'}</p>
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
        </nav>

        <button type="button" className="btn-secondary" onClick={handleLogout}>
          <LogOut size={15} /> Salir
        </button>
      </header>

      <Outlet />
    </div>
  );
}
