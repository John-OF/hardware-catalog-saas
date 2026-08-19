import './DashboardPage.css';

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
  Settings,
  ShoppingCart,
  FileText,
  BarChart3,
  MessageSquare,
  Sun,
  Moon
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
  const [theme, setTheme] = useState<'light' | 'dark'>(
    () => (localStorage.getItem('admin-theme') as 'light' | 'dark') || 'light'
  );

  useEffect(() => {
    localStorage.setItem('admin-theme', theme);
  }, [theme]);

  const toggleTheme = () => setTheme((t) => (t === 'dark' ? 'light' : 'dark'));

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
    if (location.pathname.endsWith('/dashboard') || location.pathname.endsWith('/dashboard/')) return 'Resumen';
    if (location.pathname.includes('/dashboard/products')) return 'Productos';
    if (location.pathname.includes('/dashboard/categories')) return 'Categorías';
    if (location.pathname.includes('/dashboard/settings')) return 'Configuración';
    if (location.pathname.includes('/dashboard/orders')) return 'Pedidos';
    if (location.pathname.includes('/dashboard/pages')) return 'Páginas';
    if (location.pathname.includes('/dashboard/reviews')) return 'Reseñas';
    return 'Dashboard';
  };

  if (isLoading) {
    return (
      <div className="loader-container page-dashboard">
        <div className="loader-ring"></div>
        <p>Cargando panel...</p>
      </div>
    );
  }

  return (
    <div className={`dashboard-layout page-dashboard ${theme === 'dark' ? 'theme-dark' : ''}`}>
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
            <span className="plan-badge">{(tenant?.plan ?? 'free').toUpperCase()}</span>
          </div>
        </div>

        <nav className="sidebar-nav">
          <NavLink 
            to="/dashboard" 
            end
            className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
            onClick={() => setIsSidebarOpen(false)}
          >
            <BarChart3 size={20} />
            <span>Resumen</span>
            <ChevronRight className="nav-arrow" size={16} />
          </NavLink>

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
            to="/dashboard/orders" 
            className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
            onClick={() => setIsSidebarOpen(false)}
          >
            <ShoppingCart size={20} />
            <span>Pedidos</span>
            <ChevronRight className="nav-arrow" size={16} />
          </NavLink>

          <NavLink 
            to="/dashboard/pages" 
            className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
            onClick={() => setIsSidebarOpen(false)}
          >
            <FileText size={20} />
            <span>Páginas</span>
            <ChevronRight className="nav-arrow" size={16} />
          </NavLink>

          <NavLink 
            to="/dashboard/reviews" 
            className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
            onClick={() => setIsSidebarOpen(false)}
          >
            <MessageSquare size={20} />
            <span>Reseñas</span>
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
            <button
              type="button"
              onClick={toggleTheme}
              className="theme-toggle-btn"
              title={theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
              aria-label="Cambiar tema"
            >
              {theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}
            </button>
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

    </div>
  );
}
