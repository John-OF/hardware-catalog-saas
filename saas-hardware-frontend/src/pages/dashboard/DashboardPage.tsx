import { useEffect, useState } from 'react';
import { Outlet, NavLink, useNavigate, useLocation } from 'react-router-dom';
import { toast } from 'react-hot-toast';
import { 
  Package, 
  FolderTree, 
  LogOut, 
  ExternalLink, 
  Store,
  User as UserIcon,
  Menu,
  X,
  ChevronRight,
  Settings
} from 'lucide-react';
import { getMe, logoutUser } from '../../api/auth';
import { useAuthStore } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';

export default function DashboardPage() {
  const navigate = useNavigate();
  const location = useLocation();
  
  const user = useAuthStore((s) => s.user);
  const token = useAuthStore((s) => s.token);
  const setAuth = useAuthStore((s) => s.setAuth);
  const clearAuth = useAuthStore((s) => s.clearAuth);
  
  const tenant = useTenantStore((s) => s.tenant);
  const setTenant = useTenantStore((s) => s.setTenant);
  const clearTenant = useTenantStore((s) => s.clearTenant);

  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(!user);

  useEffect(() => {
    // Si no hay token, redirigir al login
    if (!token) {
      navigate('/login', { replace: true });
      return;
    }

    // Si ya hay token pero no tenemos los datos del usuario cargados en memoria
    if (!user) {
      setIsLoading(true);
      getMe()
        .then((data) => {
          setAuth(token, data.user);
          setTenant(data.tenant);
        })
        .catch((error) => {
          console.error(error);
          toast.error('Sesión vencida. Vuelve a iniciar sesión.');
          clearAuth();
          clearTenant();
          navigate('/login', { replace: true });
        })
        .finally(() => {
          setIsLoading(false);
        });
    }
  }, [user, token, setAuth, setTenant, clearAuth, clearTenant, navigate]);

  const handleLogout = async () => {
    const logoutPromise = logoutUser()
      .then(() => {
        clearAuth();
        clearTenant();
        navigate('/login', { replace: true });
      })
      .catch((err) => {
        console.error(err);
        // Fallback local por si el token ya expiró en backend
        clearAuth();
        clearTenant();
        navigate('/login', { replace: true });
      });

    toast.promise(logoutPromise, {
      loading: 'Cerrando sesión...',
      success: '¡Sesión cerrada con éxito!',
      error: 'Error al cerrar sesión, limpiando datos...',
    });
  };

  // Determinar título de sección según ruta
  const getSectionTitle = () => {
    if (location.pathname.includes('/dashboard/products')) return 'Productos';
    if (location.pathname.includes('/dashboard/categories')) return 'Categorías';
    if (location.pathname.includes('/dashboard/settings')) return 'Configuración';
    return 'Dashboard';
  };

  if (isLoading) {
    return (
      <div className="loader-container">
        <div className="loader-ring"></div>
        <p>Cargando panel...</p>
        <style>{`
          .loader-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-color: #0b0f19;
            color: var(--text-secondary);
            gap: 1rem;
          }
          .loader-ring {
            width: 48px;
            height: 48px;
            border: 3px solid rgba(37, 99, 235, 0.1);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 0.8s linear infinite;
          }
          @keyframes spin {
            to { transform: rotate(360deg); }
          }
        `}</style>
      </div>
    );
  }

  return (
    <div className="dashboard-layout">
      {/* Sidebar Móvil Toggle */}
      <button 
        className="sidebar-toggle-mobile" 
        onClick={() => setIsSidebarOpen(!isSidebarOpen)}
      >
        {isSidebarOpen ? <X size={20} /> : <Menu size={20} />}
      </button>

      {/* Sidebar */}
      <aside className={`dashboard-sidebar ${isSidebarOpen ? 'open' : ''}`}>
        <div className="sidebar-brand">
          <div className="tenant-logo-placeholder">
            {tenant?.logo_url ? (
              <img src={tenant.logo_url} alt={tenant.name} className="tenant-logo-img" />
            ) : (
              <Store size={22} className="tenant-logo-icon" />
            )}
          </div>
          <div className="tenant-info">
            <h3>{tenant?.name || 'Cargando...'}</h3>
            <span className="plan-badge">{tenant?.plan.toUpperCase()}</span>
          </div>
        </div>

        <nav className="sidebar-nav">
          <NavLink 
            to="/dashboard/products" 
            className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
            onClick={() => setIsSidebarOpen(false)}
          >
            <Package size={20} />
            <span>Productos</span>
            <ChevronRight className="nav-arrow" size={16} />
          </NavLink>

          <NavLink 
            to="/dashboard/categories" 
            className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
            onClick={() => setIsSidebarOpen(false)}
          >
            <FolderTree size={20} />
            <span>Categorías</span>
            <ChevronRight className="nav-arrow" size={16} />
          </NavLink>

          <NavLink
            to="/dashboard/settings"
            className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
            onClick={() => setIsSidebarOpen(false)}
          >
            <Settings size={20} />
            <span>Configuración</span>
            <ChevronRight className="nav-arrow" size={16} />
          </NavLink>
        </nav>

        <div className="sidebar-footer">
          <div className="user-profile">
            <div className="avatar">
              <UserIcon size={18} />
            </div>
            <div className="user-info">
              <h4>{user?.name}</h4>
              <p>{user?.role === 'admin' ? 'Administrador' : 'Colaborador'}</p>
            </div>
          </div>
          <button onClick={handleLogout} className="logout-btn">
            <LogOut size={18} />
            <span>Cerrar Sesión</span>
          </button>
        </div>
      </aside>

      {/* Content Area */}
      <div className="dashboard-main">
        {/* Topbar */}
        <header className="dashboard-topbar glass-card">
          <div className="topbar-left">
            <h2>{getSectionTitle()}</h2>
          </div>
          <div className="topbar-right">
            {tenant?.slug && (
              <a 
                href={`/${tenant.slug}`} 
                target="_blank" 
                rel="noopener noreferrer" 
                className="btn-secondary storefront-btn"
              >
                <ExternalLink size={16} />
                <span>Ver Tienda Pública</span>
              </a>
            )}
          </div>
        </header>

        {/* Pagina Interna */}
        <main className="dashboard-content animate-fade-in">
          <Outlet />
        </main>
      </div>

      <style>{`
        .dashboard-layout {
          display: flex;
          min-height: 100vh;
          background-color: #0b0f19;
          position: relative;
        }

        /* Sidebar Styles */
        .dashboard-sidebar {
          width: 280px;
          background-color: var(--bg-sidebar);
          border-right: 1px solid var(--border);
          display: flex;
          flex-direction: column;
          z-index: 50;
          transition: transform 0.3s ease;
        }

        .sidebar-brand {
          padding: 2rem 1.5rem;
          display: flex;
          align-items: center;
          gap: 1rem;
          border-bottom: 1px solid var(--border);
        }

        .tenant-logo-placeholder {
          width: 44px;
          height: 44px;
          border-radius: 10px;
          background: rgba(255, 255, 255, 0.03);
          border: 1px solid var(--border);
          display: flex;
          align-items: center;
          justify-content: center;
          overflow: hidden;
        }

        .tenant-logo-img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .tenant-logo-icon {
          color: var(--primary);
        }

        .tenant-info h3 {
          font-size: 1rem;
          color: var(--text-primary);
          font-weight: 600;
          line-height: 1.2;
          max-width: 160px;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .plan-badge {
          display: inline-block;
          font-size: 0.65rem;
          font-weight: 700;
          color: var(--primary);
          background: rgba(37, 99, 235, 0.1);
          padding: 0.1rem 0.4rem;
          border-radius: 4px;
          margin-top: 0.25rem;
          letter-spacing: 0.05em;
        }

        .sidebar-nav {
          padding: 2rem 1rem;
          display: flex;
          flex-direction: column;
          gap: 0.5rem;
          flex: 1;
        }

        .nav-link {
          display: flex;
          align-items: center;
          gap: 1rem;
          padding: 0.85rem 1rem;
          color: var(--text-secondary);
          text-decoration: none;
          border-radius: var(--radius-md);
          font-weight: 500;
          font-size: 0.95rem;
          transition: var(--transition);
          position: relative;
        }

        .nav-link:hover {
          color: var(--text-primary);
          background: rgba(255, 255, 255, 0.03);
        }

        .nav-link.active {
          color: white;
          background: var(--primary);
          box-shadow: 0 4px 12px var(--primary-glow);
        }

        .nav-arrow {
          margin-left: auto;
          opacity: 0;
          transition: var(--transition);
        }

        .nav-link:hover .nav-arrow {
          opacity: 0.5;
          transform: translateX(2px);
        }

        .nav-link.active .nav-arrow {
          opacity: 1;
          color: white;
        }

        .sidebar-footer {
          padding: 1.5rem;
          border-top: 1px solid var(--border);
          display: flex;
          flex-direction: column;
          gap: 1.25rem;
        }

        .user-profile {
          display: flex;
          align-items: center;
          gap: 0.85rem;
        }

        .avatar {
          width: 38px;
          height: 38px;
          border-radius: 50%;
          background: rgba(255, 255, 255, 0.05);
          border: 1px solid var(--border);
          display: flex;
          align-items: center;
          justify-content: center;
          color: var(--text-secondary);
        }

        .user-info h4 {
          font-size: 0.9rem;
          font-weight: 600;
          color: var(--text-primary);
        }

        .user-info p {
          font-size: 0.75rem;
          color: var(--text-muted);
        }

        .logout-btn {
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 0.75rem;
          width: 100%;
          padding: 0.75rem;
          background: rgba(239, 68, 68, 0.08);
          border: 1px solid rgba(239, 68, 68, 0.15);
          color: #ef4444;
          border-radius: var(--radius-md);
          font-family: var(--font-sans);
          font-size: 0.9rem;
          font-weight: 500;
          cursor: pointer;
          transition: var(--transition);
        }

        .logout-btn:hover {
          background: #ef4444;
          color: white;
          box-shadow: 0 4px 12px var(--danger-glow);
        }

        /* Main Content area */
        .dashboard-main {
          flex: 1;
          display: flex;
          flex-direction: column;
          min-height: 100vh;
          overflow-y: auto;
        }

        /* Topbar */
        .dashboard-topbar {
          margin: 1.5rem;
          padding: 1rem 2rem;
          display: flex;
          align-items: center;
          justify-content: space-between;
          border-radius: var(--radius-lg);
          z-index: 10;
        }

        .dashboard-topbar h2 {
          font-size: 1.25rem;
          font-family: var(--font-heading);
          background: linear-gradient(135deg, #f8fafc 0%, #cbd5e1 100%);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
        }

        .storefront-btn {
          padding: 0.6rem 1.2rem;
          font-size: 0.85rem;
          border-radius: var(--radius-md);
        }

        .dashboard-content {
          flex: 1;
          padding: 0 1.5rem 1.5rem 1.5rem;
        }

        /* Toggle Button Mobile */
        .sidebar-toggle-mobile {
          display: none;
          position: absolute;
          top: 1.75rem;
          left: 1.5rem;
          z-index: 100;
          background: var(--bg-sidebar);
          border: 1px solid var(--border);
          color: var(--text-primary);
          width: 40px;
          height: 40px;
          border-radius: 8px;
          align-items: center;
          justify-content: center;
          cursor: pointer;
        }

        /* Media Queries */
        @media (max-width: 1024px) {
          .sidebar-toggle-mobile {
            display: flex;
          }

          .dashboard-sidebar {
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            transform: translateX(-100%);
          }

          .dashboard-sidebar.open {
            transform: translateX(0);
          }

          .dashboard-main {
            padding-left: 0;
          }

          .dashboard-topbar {
            padding-left: 5rem; /* Margen para botón hamburguesa */
          }
        }

        @media (max-width: 580px) {
          .dashboard-topbar {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start;
          }
          .storefront-btn {
            width: 100%;
          }
        }
      `}</style>
    </div>
  );
}
