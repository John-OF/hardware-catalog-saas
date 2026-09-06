import './CatalogPage.css';

import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { useParams, Link, Navigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  Search,
  Store,
  ChevronLeft,
  ChevronRight,
  ShoppingBag,
  Loader2,
  FolderOpen,
  MessageCircle,
  LayoutGrid,
  ShoppingCart,
  Plus,
  Cpu,
  X,
  SlidersHorizontal,
  Star,
  CheckCircle,
  User,
  Heart
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import api from '../../api/axios';
import { getPublicFacets, getPublicTenant, getPublicProducts, resolveTenantDomain } from '../../api/public';
import { getPublicPages } from '../../api/pages';
import CategoryIcon from '../../components/ui/CategoryIcon';
import CartDrawer from '../../components/public/CartDrawer';
import CustomerAccountModal from '../../components/public/CustomerAccountModal';
import AnnouncementBar from '../../components/public/AnnouncementBar';
import StoreFooter from '../../components/public/StoreFooter';
import StoreHeader from '../../components/public/StoreHeader';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';
import { formatMoney } from '../../utils/money';
import { heroStyleOf } from '../../utils/hero';
import { useCartStore } from '../../stores/cartStore';
import { useCustomerAuthStore } from '../../stores/customerAuthStore';
import type { Tenant, Category, Product, PaginatedResponse, Page } from '../../types';

export default function CatalogPage() {
  const { slug } = useParams<{ slug: string }>();
  const isCustomDomain = !slug;
  const currentDomain = window.location.hostname;

  // Los filtros viven en la URL, no en estado local (UI-1). Asi un enlace
  // compartido o un marcador reproducen lo que el remitente estaba viendo,
  // recargar no lo pierde y el boton "atras" deshace el ultimo filtro, que es
  // justo lo que un comprador espera al comparar componentes.
  const [searchParams, setSearchParams] = useSearchParams();

  const search = searchParams.get('q') ?? '';
  const selectedCategory = searchParams.get('categoria') ?? '';
  const inStock = searchParams.get('stock') === '1';
  const sort = searchParams.get('orden') ?? '';
  const page = Math.max(1, Number(searchParams.get('pagina')) || 1);
  const selectedSpecs = useMemo(() => {
    const specs: Record<string, string> = {};
    searchParams.forEach((valor, clave) => {
      if (clave.startsWith('esp.')) specs[clave.slice(4)] = valor;
    });
    return specs;
  }, [searchParams]);

  /**
   * Unica puerta de escritura de los filtros.
   *
   * Cualquier cambio vuelve a la pagina 1 salvo que lo que se cambie sea la
   * pagina: con el filtro nuevo el listado tiene otro numero de paginas y
   * quedarse en la 4 puede dejar al comprador mirando un vacio.
   *
   * `reemplazar` evita meter una entrada en el historial por cada pulsacion al
   * teclear; los demas filtros si dejan entrada para que "atras" los deshaga.
   */
  const aplicarFiltros = useCallback((
    cambios: Record<string, string | null>,
    { reemplazar = false } = {}
  ) => {
    setSearchParams((previos) => {
      const params = new URLSearchParams(previos);
      for (const [clave, valor] of Object.entries(cambios)) {
        if (valor === null || valor === '') params.delete(clave);
        else params.set(clave, valor);
      }
      if (!('pagina' in cambios)) params.delete('pagina');
      return params;
    }, { replace: reemplazar });
  }, [setSearchParams]);

  /** Cambiar de categoria descarta las specs: son de la categoria anterior. */
  const cambiarCategoria = (id: string) => {
    setSearchParams((previos) => {
      const params = new URLSearchParams(previos);
      [...params.keys()].filter((k) => k.startsWith('esp.')).forEach((k) => params.delete(k));
      if (id) params.set('categoria', id);
      else params.delete('categoria');
      params.delete('pagina');
      return params;
    });
  };

  const [cartOpen, setCartOpen] = useState(false);
  // Drawer de filtros en móvil (PUB-6). En escritorio la sidebar es fija y este
  // estado no pinta nada.
  const [filtersOpen, setFiltersOpen] = useState(false);

  // searchInput es lo que se ve en la caja y responde a cada tecla; `search`
  // sale de la URL y va 300ms por detras para no pedir una vez por letra.
  const [searchInput, setSearchInput] = useState(search);
  // Lo ultimo que escribimos nosotros en la URL. Sirve para distinguir un
  // cambio propio (el debounce) de uno de fuera (el boton "atras", un enlace
  // pegado): solo en el segundo caso hay que resembrar la caja, y sin esta
  // marca resembrarla borraria lo que el comprador esta tecleando.
  const ultimaBusquedaEscrita = useRef(search);

  useEffect(() => {
    if (search !== ultimaBusquedaEscrita.current) {
      ultimaBusquedaEscrita.current = search;
      setSearchInput(search);
    }
  }, [search]);

  // Debounce de la búsqueda: mientras el comprador siga tecleando, el timeout
  // anterior se cancela y no se lanza la petición.
  useEffect(() => {
    if (searchInput === search) return;

    const timeout = setTimeout(() => {
      ultimaBusquedaEscrita.current = searchInput;
      aplicarFiltros({ q: searchInput || null }, { reemplazar: true });
    }, 300);

    return () => clearTimeout(timeout);
  }, [searchInput, search, aplicarFiltros]);

  // Carrito
  const addItem = useCartStore((s) => s.addItem);
  const cartCount = useCartStore((s) => s.totalItems());
  const cartItems = useCartStore((s) => s.items);

  const handleAddToCart = (e: React.MouseEvent, product: Product) => {
    e.preventDefault();
    e.stopPropagation();
    addItem(resolvedSlug!, product);
    toast.success(`${product.name} agregado al pedido`);
  };

  // Comparador de productos
  const [comparedProducts, setComparedProducts] = useState<Product[]>([]);
  const [isCompareModalOpen, setIsCompareModalOpen] = useState(false);

  // Customer Auth
  const [accountModalOpen, setAccountModalOpen] = useState(false);
  const { isAuthenticated: isCustomerAuthenticated, favoriteIds, setFavoriteIds, toggleFavoriteId } = useCustomerAuthStore();

  const handleToggleCompare = (e: React.MouseEvent, product: Product) => {
    e.preventDefault();
    e.stopPropagation();
    setComparedProducts((prev) => {
      const exists = prev.find((p) => p.id === product.id);
      if (exists) {
        return prev.filter((p) => p.id !== product.id);
      }
      if (prev.length >= 3) {
        toast.error('Puedes comparar un máximo de 3 productos a la vez.');
        return prev;
      }
      return [...prev, product];
    });
  };

  // Redireccionar en localhost si falta el slug.
  //
  // AUD-14: aqui solo se DECIDE; el `<Navigate>` se devuelve mas abajo, despues
  // de todos los hooks. Ver el comentario que acompaña al return.
  const isSaaSBase = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
  const redirigirAlLogin = isSaaSBase && !slug;

  // Fetch Tenant Info
  const { data: tenant, isLoading: isLoadingTenant, isError: isErrorTenant } = useQuery<Tenant>({
    queryKey: ['publicTenant', slug || currentDomain],
    queryFn: async () => {
      if (slug) {
        return getPublicTenant(slug);
      } else {
        return resolveTenantDomain(currentDomain);
      }
    },
    // Mientras se redirige no hay tienda que resolver: sin esto, quitar el
    // return de arriba habria estrenado una peticion a `resolve-domain` con
    // 'localhost' que solo puede fallar. Las demas consultas ya estaban
    // apagadas por su cuenta, porque cuelgan de `resolvedSlug`.
    enabled: !redirigirAlLogin,
  });

  const resolvedSlug = tenant?.slug;
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency);

  // Fetch Pages públicas
  const { data: publicPages = [] } = useQuery<Page[]>({
    queryKey: ['publicPages', resolvedSlug],
    queryFn: () => getPublicPages(resolvedSlug!),
    enabled: !!resolvedSlug,
  });

  // Título y favicon de la pestaña según el tenant
  useTenantBranding(tenant);
  useTenantTheme(tenant);

  // Categorías activas de la tienda para el filtro lateral.
  const { data: publicCategories = [] } = useQuery<Category[]>({
    queryKey: ['publicCategories', resolvedSlug],
    queryFn: async () => {
      const res = await api.get<Category[]>(`/public/${resolvedSlug}/categories`);
      return res.data;
    },
    enabled: !!resolvedSlug,
  });

  // Fetch Public Products
  const { data: paginatedData, isLoading: isLoadingProducts } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['publicProducts', resolvedSlug, search, selectedCategory, inStock, selectedSpecs, sort, page],
    queryFn: () => getPublicProducts(resolvedSlug!, {
      category_id: selectedCategory || undefined,
      search: search || undefined,
      in_stock: inStock || undefined,
      specs: Object.keys(selectedSpecs).length > 0 ? selectedSpecs : undefined,
      sort: sort || undefined,
      page,
    }),
    enabled: !!resolvedSlug,
  });

  // Parsear secciones editables de la portada para CMS
  let parsedSections: any[] = [];
  try {
    parsedSections = typeof tenant?.theme?.sections === 'string'
      ? JSON.parse(tenant.theme.sections)
      : (tenant?.theme?.sections ?? []);
  } catch (e) {}

  if (!parsedSections || parsedSections.length === 0) {
    parsedSections = [
      { id: '1', type: 'hero', enabled: true },
      { id: '2', type: 'featured', enabled: true }
    ];
  }

  // Facetas de specs de TODO el catálogo (PUB-2). Antes se derivaban de los 24
  // productos de la página visible, así que las opciones cambiaban al paginar.
  // La queryKey NO incluye specs ni página a propósito: las opciones solo deben
  // cambiar al cambiar de categoría, o el filtro se movería bajo el cursor.
  const { data: facetsData } = useQuery<{ specs: Record<string, string[]> }>({
    queryKey: ['publicFacets', resolvedSlug, selectedCategory],
    queryFn: () => getPublicFacets(resolvedSlug!, { category_id: selectedCategory || undefined }),
    enabled: !!resolvedSlug,
  });

  const availableSpecs = facetsData?.specs ?? {};

  // Se muestra en el botón "Filtrar" de móvil: con la sidebar escondida, el
  // comprador no tiene otra forma de saber que hay filtros puestos.
  const activeFiltersCount =
    Object.keys(selectedSpecs).length + (selectedCategory ? 1 : 0) + (inStock ? 1 : 0);

  const products = paginatedData?.data || [];
  const totalPages = paginatedData?.last_page || 1;

  const getPublicPath = (path: string) => {
    if (isCustomDomain) {
      return path;
    }
    return `/${resolvedSlug}${path}`;
  };


  // Fetch customer favorites on load if logged in
  useEffect(() => {
    if (isCustomerAuthenticated && resolvedSlug) {
      api.get(`/public/${resolvedSlug}/favorites`)
        .then((res) => {
          setFavoriteIds(res.data.map((p: any) => p.id));
        })
        .catch(() => {
          // Ignore failures silently on load
        });
    }
  }, [isCustomerAuthenticated, resolvedSlug]);

  // AUD-14: este return va DESPUES de todos los hooks a proposito.
  //
  // Estaba arriba del todo, antes de los `useQuery`, que es lo que marca
  // `react-hooks/rules-of-hooks`. Hoy no rompe porque `slug` no cambia dentro de
  // una misma instancia del componente, pero el dia que una navegacion alterne
  // `/:slug` -> `/` con el componente montado, React se encuentra con menos
  // hooks de los que registro la vez anterior ("Rendered fewer hooks than
  // expected") y se cae la tienda entera, no solo esta pantalla.
  if (redirigirAlLogin) {
    return <Navigate to="/login" replace />;
  }

  if (isLoadingTenant) {
    return (
      <div className="loader-container page-catalog">
        <Loader2 className="spinner" size={40} />
        <p>Cargando tienda...</p>
      </div>
    );
  }

  if (isErrorTenant || !tenant) {
    return (
      <div className="error-container page-catalog">
        <Store size={48} />
        <h2>Tienda no encontrada</h2>
        <p>El catálogo que buscas no existe o se encuentra inactivo actualmente.</p>
        <Link to="/login" className="btn-primary" style={{textDecoration: 'none'}}>Ir al Inicio</Link>
      </div>
    );
  }

  return (
    <div className="public-catalog-container animate-fade-in page-catalog">
      {/* Franja de anuncios (10.4): encima del header, no dentro. Sin texto no
          pinta nada. */}
      <AnnouncementBar theme={tenant.theme} />

      {/* Header Store */}
      <StoreHeader tenant={tenant}>
          <Link
            to={getPublicPath('/builder')}
            className="btn-secondary builder-header-btn"
            style={{ textDecoration: 'none', display: 'flex', alignItems: 'center', gap: '0.4rem', padding: '0.6rem 1rem', fontSize: '0.85rem' }}
          >
            <Cpu size={16} /> Armador PC
          </Link>
          <button
            type="button"
            className="cart-trigger"
            onClick={() => setAccountModalOpen(true)}
            aria-label="Mi cuenta"
            style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
          >
            <User size={20} />
            {isCustomerAuthenticated && (
              <span className="cart-badge" style={{ backgroundColor: 'var(--success)', width: '8px', height: '8px', minWidth: '8px', padding: 0 }} />
            )}
          </button>
          <button
            type="button"
            className="cart-trigger"
            onClick={() => setCartOpen(true)}
            aria-label="Ver pedido"
          >
            <ShoppingCart size={20} />
            {cartCount > 0 && <span className="cart-badge">{cartCount}</span>}
          </button>
          <a
            href={`https://wa.me/${tenant.whatsapp_number?.replace('+', '') ?? ''}`}
            target="_blank"
            rel="noopener noreferrer"
            className="btn-primary whatsapp-header-btn"
          >
            <MessageCircle size={18} /> Contactar
          </a>
      </StoreHeader>

      {/* Renderizado de secciones editables de la portada */}
      {parsedSections
        .filter((sec: any) => sec.enabled)
        .map((sec: any) => {
          if (sec.type === 'hero') {
            // PERS-5: el estilo elegido decide la disposición y, sobre todo, qué
            // se hace con el banner. `has-banner` sigue queriendo decir "el
            // texto va ENCIMA de la foto", que es lo que justifica forzarle los
            // colores claros; en «partido» la foto va en su propia columna y el
            // texto se queda sobre la tarjeta, así que ahí no se fuerza nada.
            const hero = heroStyleOf(tenant.theme?.hero_style);
            const banner = tenant.theme?.banner_url || null;
            const bannerDeFondo = Boolean(banner) && hero.banner === 'background';
            const bannerAlLado = Boolean(banner) && hero.banner === 'side';

            return (
              <section
                key={sec.id}
                className={[
                  'catalog-hero',
                  `hero-${hero.value}`,
                  // El mínimo no es una tarjeta: sin `glass-card` no hay fondo,
                  // borde ni sombra que luego haya que apagar a base de reglas.
                  hero.value === 'minimal' ? '' : 'glass-card',
                  'animate-fade-in',
                  bannerDeFondo ? 'has-banner' : '',
                  bannerAlLado ? 'has-figure' : '',
                ].filter(Boolean).join(' ')}
                style={bannerDeFondo ? { backgroundImage: `url(${banner})` } : undefined}
              >
                <div className="hero-content">
                  {hero.value !== 'minimal' && (
                    <span
                      className="hero-badge"
                      style={tenant.theme?.accent_color ? { color: tenant.theme.accent_color, borderColor: tenant.theme.accent_color } : undefined}
                    >
                      CATÁLOGO VIRTUAL
                    </span>
                  )}
                  <h1>{tenant.theme?.hero_title || 'Encuentra las mejores piezas de hardware'}</h1>
                  <p>{tenant.theme?.hero_subtitle || 'Explora nuestro catálogo en tiempo real, filtra por componentes y consulta existencias directamente por WhatsApp.'}</p>
                </div>
                {bannerAlLado && (
                  <div className="hero-figure" style={{ backgroundImage: `url(${banner})` }} />
                )}
                {hero.value !== 'minimal' && <div className="hero-glow"></div>}
              </section>
            );
          }

          if (sec.type === 'categories') {
            // PUB-7: esta sección existía en Configuración pero no se renderizaba
            // en ningún sitio, así que activarla no hacía absolutamente nada.
            if (publicCategories.length === 0) return null;

            return (
              <section key={sec.id} className="featured-categories animate-fade-in">
                <h2 className="featured-categories-title">Categorías destacadas</h2>
                <div className="featured-categories-grid">
                  {publicCategories.map((cat) => (
                    <button
                      key={cat.id}
                      type="button"
                      className={`featured-category-card glass-card ${selectedCategory === cat.id ? 'active' : ''}`}
                      onClick={() => {
                        // Filtra la grilla de abajo, que es justo lo que espera
                        // quien pulsa una categoría destacada.
                        cambiarCategoria(selectedCategory === cat.id ? '' : cat.id);
                      }}
                    >
                      <CategoryIcon slug={cat.icon} size={22} />
                      <span>{cat.name}</span>
                    </button>
                  ))}
                </div>
              </section>
            );
          }

          if (sec.type === 'featured') {
            return (
              <div key={sec.id} className="catalog-content-layout animate-fade-in">
                {/* Fondo oscuro del drawer en móvil: cierra al tocar fuera. */}
                {filtersOpen && (
                  <div className="filters-backdrop" onClick={() => setFiltersOpen(false)} />
                )}

                {/* Filters Sidebar */}
                <aside className={`catalog-sidebar glass-card ${filtersOpen ? 'is-open' : ''}`}>
                  <div className="filters-drawer-head">
                    <span>Filtros</span>
                    <button type="button" onClick={() => setFiltersOpen(false)} aria-label="Cerrar filtros">
                      <X size={18} />
                    </button>
                  </div>
                  <div className="sidebar-section">
                    <h3>Categorías</h3>
                    <div className="category-filters-list">
                      <button 
                        onClick={() => cambiarCategoria('')} 
                        className={`category-filter-btn ${selectedCategory === '' ? 'active' : ''}`}
                      >
                        <span className="category-filter-label"><LayoutGrid size={16} /> Todos</span>
                      </button>
                      {publicCategories.map((cat) => (
                        <button 
                          key={cat.id} 
                          onClick={() => cambiarCategoria(cat.id)} 
                          className={`category-filter-btn ${selectedCategory === cat.id ? 'active' : ''}`}
                        >
                          <span className="category-filter-label"><CategoryIcon slug={cat.icon} size={16} /> {cat.name}</span>
                        </button>
                      ))}
                    </div>
                  </div>

                  <div className="sidebar-section">
                    <h3>Disponibilidad</h3>
                    <label className="checkbox-filter-label">
                      <input
                        type="checkbox"
                        checked={inStock}
                        onChange={(e) => aplicarFiltros({ stock: e.target.checked ? '1' : null })}
                        className="custom-checkbox"
                      />
                      <span>Sólo en stock</span>
                    </label>
                  </div>

                  {/* Dynamic Technical Specs Filters */}
                  {Object.keys(availableSpecs).length > 0 && (
                    <div className="sidebar-section specs-filters-section" style={{ borderTop: '1px solid var(--border)', paddingTop: '1.25rem', marginTop: '1.25rem' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem' }}>
                        <h3 style={{ margin: 0 }}>Especificaciones</h3>
                        {Object.keys(selectedSpecs).length > 0 && (
                          <button 
                            type="button" 
                            onClick={() => aplicarFiltros(Object.fromEntries(Object.keys(selectedSpecs).map((k) => [`esp.${k}`, null])))}
                            style={{ background: 'transparent', border: 'none', color: 'var(--primary)', fontSize: '0.75rem', cursor: 'pointer', fontWeight: 600 }}
                          >
                            Limpiar
                          </button>
                        )}
                      </div>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                        {Object.entries(availableSpecs).map(([specKey, specValues]) => (
                          <div key={specKey} className="spec-filter-group" style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                            <span className="spec-filter-title" style={{ fontSize: '0.8rem', fontWeight: 600, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                              {specKey}
                            </span>
                            <div className="spec-filter-options" style={{ display: 'flex', flexWrap: 'wrap', gap: '0.3rem' }}>
                              {Array.from(specValues).map((val) => {
                                const isSelected = selectedSpecs[specKey] === val;
                                return (
                                  <button
                                    key={val}
                                    type="button"
                                    onClick={() => {
                                      aplicarFiltros({ [`esp.${specKey}`]: isSelected ? null : val });
                                    }}
                                    className={`spec-pill-btn ${isSelected ? 'active' : ''}`}
                                    style={{
                                      fontSize: '0.75rem',
                                      padding: '0.25rem 0.6rem',
                                      borderRadius: '20px',
                                      border: isSelected ? '1px solid var(--primary)' : '1px solid var(--border)',
                                      background: isSelected ? 'rgba(var(--primary-rgb), 0.15)' : 'rgba(255, 255, 255, 0.02)',
                                      color: isSelected ? 'var(--primary)' : 'var(--text-secondary)',
                                      cursor: 'pointer',
                                      transition: 'var(--transition)',
                                      fontWeight: isSelected ? 600 : 'normal'
                                    }}
                                  >
                                    {val}
                                  </button>
                                );
                              })}
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </aside>

                {/* Main Grid Area */}
                <div className="catalog-main-area">
                  {/* Topbar Search */}
                  <div className="search-bar-row glass-card">
                    {/* Solo visible en móvil: ahí la sidebar vive en un drawer. */}
                    <button
                      type="button"
                      className="mobile-filters-btn"
                      onClick={() => setFiltersOpen(true)}
                    >
                      <SlidersHorizontal size={16} />
                      Filtrar
                      {activeFiltersCount > 0 && <span className="filters-count">{activeFiltersCount}</span>}
                    </button>

                    <div className="search-box">
                      <Search size={18} className="search-icon" />
                      <input
                        type="text"
                        placeholder="Buscar por nombre, marca o código..."
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                        className="premium-input"
                      />
                    </div>

                    <div className="sort-box">
                      <label htmlFor="catalog-sort" className="sort-label">Ordenar por</label>
                      <select
                        id="catalog-sort"
                        value={sort}
                        onChange={(e) => aplicarFiltros({ orden: e.target.value || null })}
                        className="premium-input sort-select"
                      >
                        <option value="">Recomendados</option>
                        <option value="price_asc">Precio: menor a mayor</option>
                        <option value="price_desc">Precio: mayor a menor</option>
                        <option value="newest">Más recientes</option>
                        <option value="name">Nombre (A-Z)</option>
                      </select>
                    </div>
                  </div>

                  {/* Products Grid */}
                  {isLoadingProducts ? (
                    <div className="inner-loader">
                      <Loader2 className="spinner" size={32} />
                      <p>Buscando componentes...</p>
                    </div>
                  ) : products.length === 0 ? (
                    <div className="empty-catalog glass-card">
                      <FolderOpen size={44} className="empty-icon" />
                      <h3>No se encontraron componentes</h3>
                      <p>Intenta cambiar los filtros o el texto de búsqueda.</p>
                    </div>
                  ) : (
                    <>
                      <div className={`catalog-grid layout-${tenant.theme?.layout || 'grid'}`}>
                        {products.map((product) => {
                          const cartItem = cartItems.find((item) => item.product.id === product.id);
                          const qtyInCart = cartItem ? cartItem.quantity : 0;

                          return (
                            <Link 
                              key={product.id} 
                              to={getPublicPath(`/product/${product.id}`)} 
                              className="product-catalog-card glass-card"
                            >
                              <div className="card-image-wrapper">
                                {isCustomerAuthenticated && (
                                  <button
                                    type="button"
                                    onClick={async (e) => {
                                      e.preventDefault();
                                      e.stopPropagation();
                                      try {
                                        const res = await api.post(`/public/${resolvedSlug}/favorites/${product.id}`);
                                        toast.success(res.data.message);
                                        toggleFavoriteId(product.id);
                                      } catch {
                                        toast.error('Error al actualizar favoritos');
                                      }
                                    }}
                                    style={{
                                      position: 'absolute',
                                      top: '0.5rem',
                                      right: '0.5rem',
                                      zIndex: 10,
                                      background: 'var(--glass-bg)',
                                      border: '1px solid var(--border)',
                                      borderRadius: '50%',
                                      width: '30px',
                                      height: '30px',
                                      display: 'flex',
                                      alignItems: 'center',
                                      justifyContent: 'center',
                                      cursor: 'pointer',
                                      transition: 'background-color 0.2s',
                                      padding: 0
                                    }}
                                    title={favoriteIds.includes(product.id) ? 'Quitar de favoritos' : 'Agregar a favoritos'}
                                  >
                                    <Heart 
                                      size={14} 
                                      fill={favoriteIds.includes(product.id) ? 'var(--danger)' : 'none'} 
                                      style={{ color: favoriteIds.includes(product.id) ? 'var(--danger)' : 'var(--text-muted)' }} 
                                    />
                                  </button>
                                )}
                                {product.stock > 0 && (
                                  <button
                                    type="button"
                                    onClick={(e) => handleToggleCompare(e, product)}
                                    className={`compare-toggle-badge ${comparedProducts.some(p => p.id === product.id) ? 'active' : ''}`}
                                    title="Comparar especificaciones"
                                  >
                                    {comparedProducts.some(p => p.id === product.id) ? '✓ Comparando' : '+ Comparar'}
                                  </button>
                                )}
                                {product.thumbnail_url ? (
                                  <img loading="lazy" decoding="async" src={product.thumbnail_url} alt={product.name} />
                                ) : (
                                  <div className="image-placeholder">
                                    <ShoppingBag size={32} />
                                  </div>
                                )}
                                {product.stock === 0 && (
                                  <span className="card-badge sold-out">Agotado</span>
                                )}
                                {product.stock > 0 && product.stock < 5 && (
                                  <span className="card-badge low-stock">Pocas Unidades</span>
                                )}
                                {product.stock > 0 && product.sale_price !== null && product.sale_price !== undefined && (
                                  <span className="card-badge sale">Oferta</span>
                                )}
                              </div>

                              <div className="card-details">
                                <div className="card-brand-rating-row" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                  <span className="card-brand">{product.brand || 'Genérico'}</span>
                                  {product.reviews_count && product.reviews_count > 0 ? (
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', color: 'var(--warning)', fontSize: '0.78rem', fontWeight: 600 }}>
                                      <Star size={11} fill="currentColor" style={{ display: 'inline' }} />
                                      <span>{parseFloat(product.reviews_avg_rating!.toString()).toFixed(1)}</span>
                                      <span style={{ color: 'var(--text-muted)', fontWeight: 'normal', fontSize: '0.72rem' }}>({product.reviews_count})</span>
                                    </div>
                                  ) : null}
                                </div>
                                <h3 className="card-title">{product.name}</h3>
                                <div className="card-footer">
                                  <span className="card-price">
                                    {product.sale_price !== null && product.sale_price !== undefined ? (
                                      <>
                                        <span className="strike-price" style={{ textDecoration: 'line-through', marginRight: '0.4rem', opacity: 0.5, fontSize: '0.85em', fontWeight: 'normal' }}>
                                          {money(product.price)}
                                        </span>
                                        <span>{money(product.sale_price)}</span>
                                      </>
                                    ) : (
                                      money(product.price)
                                    )}
                                  </span>
                                  <span className="view-detail-link">
                                    Ver más <ChevronRight size={14} />
                                  </span>
                                </div>
                                <button
                                  type="button"
                                  className={`card-add-btn ${qtyInCart > 0 ? 'added' : ''}`}
                                  onClick={(e) => handleAddToCart(e, product)}
                                  disabled={product.stock === 0}
                                >
                                  {product.stock === 0 ? (
                                    'Agotado'
                                  ) : qtyInCart > 0 ? (
                                    <>
                                      <CheckCircle size={15} /> En carrito ({qtyInCart})
                                    </>
                                  ) : (
                                    <>
                                      <Plus size={15} /> Agregar
                                    </>
                                  )}
                                </button>
                              </div>
                            </Link>
                          );
                        })}
                      </div>

                      {/* Pagination */}
                      {totalPages > 1 && (
                        <div className="pagination-bar">
                          <button 
                            onClick={() => aplicarFiltros({ pagina: String(page - 1) })} 
                            disabled={page === 1}
                            className="btn-secondary pag-btn"
                          >
                            <ChevronLeft size={16} />
                          </button>
                          <span className="page-indicator">Página {page} de {totalPages}</span>
                          <button 
                            onClick={() => aplicarFiltros({ pagina: String(page + 1) })} 
                            disabled={page === totalPages}
                            className="btn-secondary pag-btn"
                          >
                            <ChevronRight size={16} />
                          </button>
                        </div>
                      )}
                    </>
                  )}
                </div>
              </div>
            );
          }

          return null;
        })}

      <CartDrawer open={cartOpen} onClose={() => setCartOpen(false)} slug={resolvedSlug!} tenant={tenant} />
      <CustomerAccountModal 
        isOpen={accountModalOpen} 
        onClose={() => setAccountModalOpen(false)} 
        tenantSlug={resolvedSlug!} 
        currency={tenant?.currency}
        whatsappNumber={tenant?.whatsapp_number}
      />

        {/* Barra Flotante de Comparación */}
        {comparedProducts.length > 0 && (
          <div className="floating-compare-bar glass-card animate-slide-up">
            <div className="compare-bar-content">
              <div className="compare-thumbs">
                {comparedProducts.map((p) => (
                  <div key={p.id} className="compare-thumb-wrapper">
                    {p.thumbnail_url ? (
                      <img loading="lazy" decoding="async" src={p.thumbnail_url} alt={p.name} />
                    ) : (
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', color: 'var(--text-muted)' }}>
                        <ShoppingBag size={16} />
                      </div>
                    )}
                    <button type="button" onClick={(e) => handleToggleCompare(e, p)} className="remove-thumb-btn">
                      <X size={10} />
                    </button>
                  </div>
                ))}
                {Array.from({ length: 3 - comparedProducts.length }).map((_, i) => (
                  <div key={i} className="compare-thumb-placeholder">
                    <span>+</span>
                  </div>
                ))}
              </div>
              <div className="compare-bar-actions">
                <button
                  type="button"
                  onClick={() => setIsCompareModalOpen(true)}
                  className="btn-primary btn-compare-go"
                  disabled={comparedProducts.length < 2}
                  style={{ padding: '0.5rem 1rem', fontSize: '0.85rem' }}
                >
                  Comparar ({comparedProducts.length})
                </button>
                <button type="button" onClick={() => setComparedProducts([])} className="btn-clear-compare">
                  Limpiar
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Modal de Comparación */}
        {isCompareModalOpen && (
          <div className="modal-overlay compare-modal-overlay" onClick={() => setIsCompareModalOpen(false)}>
            <div className="compare-modal-content glass-card animate-slide-up" onClick={(e) => e.stopPropagation()}>
              <div className="drawer-header" style={{ marginBottom: '1.5rem', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h3>Comparación de especificaciones</h3>
                <button onClick={() => setIsCompareModalOpen(false)} className="drawer-close" style={{ background: 'transparent', border: 'none', color: 'var(--text-secondary)', cursor: 'pointer' }}>
                  <X size={20} />
                </button>
              </div>
              
              <div className="compare-table-wrapper">
                <table className="compare-table">
                  <thead>
                    <tr>
                      <th style={{ fontWeight: 700, color: 'var(--text-secondary)' }}>Especificación</th>
                      {comparedProducts.map((p) => (
                        <th key={p.id} style={{ width: `${80 / comparedProducts.length}%` }}>
                          <div className="compare-header-item">
                            {p.thumbnail_url ? (
                              <img loading="lazy" decoding="async" src={p.thumbnail_url} alt={p.name} className="compare-header-img" />
                            ) : (
                              <div className="compare-header-img" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--text-muted)' }}>
                                <ShoppingBag size={24} />
                              </div>
                            )}
                            <span className="compare-header-brand">{p.brand || 'Genérico'}</span>
                            <h4 className="compare-header-title">{p.name}</h4>
                            <span className="compare-header-price">
                              {money(p.sale_price !== null ? p.sale_price : p.price)}
                            </span>
                            <button
                              type="button"
                              onClick={(e) => handleAddToCart(e, p)}
                              disabled={p.stock === 0}
                              className="btn-primary compare-add-btn"
                              style={{ padding: '0.4rem 0.8rem', fontSize: '0.75rem', marginTop: '0.5rem', width: '100%' }}
                            >
                              Agregar
                            </button>
                          </div>
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td><strong>Categoría</strong></td>
                      {comparedProducts.map((p) => (
                        <td key={p.id}>{p.category?.name || 'Sin Categoría'}</td>
                      ))}
                    </tr>
                    <tr>
                      <td><strong>Disponibilidad</strong></td>
                      {comparedProducts.map((p) => (
                        <td key={p.id} className={p.stock > 0 ? 'text-success' : 'text-danger'} style={{ fontWeight: 600 }}>
                          {p.stock > 0 ? `En Stock (${p.stock})` : 'Agotado'}
                        </td>
                      ))}
                    </tr>
                    {(() => {
                      const keysSet = new Set<string>();
                      comparedProducts.forEach((p) => {
                        if (p.specs) {
                          Object.keys(p.specs).forEach((k) => {
                            if (k.trim() !== '') keysSet.add(k);
                          });
                        }
                      });
                      const allKeys = Array.from(keysSet);
                      if (allKeys.length === 0) {
                        return (
                          <tr>
                            <td colSpan={comparedProducts.length + 1} style={{ textAlign: 'center', color: 'var(--text-secondary)', padding: '2rem' }}>
                              No hay especificaciones técnicas adicionales registradas.
                            </td>
                          </tr>
                        );
                      }
                      return allKeys.map((key) => (
                        <tr key={key}>
                          <td><strong>{key}</strong></td>
                          {comparedProducts.map((p) => (
                            <td key={p.id}>
                              {p.specs?.[key] !== undefined && p.specs?.[key] !== '' ? String(p.specs[key]) : '-'}
                            </td>
                          ))}
                        </tr>
                      ));
                    })()}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* El pie sale de un componente desde 10.4: el mismo va en las páginas
            informativas, y duplicado los datos de la tienda se habrían quedado
            solo aquí. */}
        <StoreFooter tenant={tenant} pages={publicPages} buildPath={getPublicPath} />

    </div>
  );
}


