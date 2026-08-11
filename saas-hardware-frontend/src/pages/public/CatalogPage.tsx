import { useState, useEffect } from 'react';
import { useParams, Link, Navigate } from 'react-router-dom';
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
  Star,
  CheckCircle,
  User,
  Heart
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import api from '../../api/axios';
import { getPublicTenant, getPublicProducts, resolveTenantDomain } from '../../api/public';
import { getPublicPages } from '../../api/pages';
import CategoryIcon from '../../components/ui/CategoryIcon';
import CartDrawer from '../../components/public/CartDrawer';
import CustomerAccountModal from '../../components/public/CustomerAccountModal';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';
import { formatMoney } from '../../utils/money';
import { useCartStore } from '../../stores/cartStore';
import { useCustomerAuthStore } from '../../stores/customerAuthStore';
import type { Tenant, Category, Product, PaginatedResponse, Page } from '../../types';

export default function CatalogPage() {
  const { slug } = useParams<{ slug: string }>();
  const isCustomDomain = !slug;
  const currentDomain = window.location.hostname;

  // States
  // searchInput es lo que se ve en la caja (responde a cada tecla); search es el
  // valor que llega a la query, retrasado 300ms para no pedir una vez por letra.
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('');
  const [inStock, setInStock] = useState(false);
  const [sort, setSort] = useState('');
  const [page, setPage] = useState(1);
  const [cartOpen, setCartOpen] = useState(false);
  const [selectedSpecs, setSelectedSpecs] = useState<Record<string, string>>({});
  const [availableSpecs, setAvailableSpecs] = useState<Record<string, Set<string>>>({});

  // Reset filter when changing category
  useEffect(() => {
    setSelectedSpecs({});
  }, [selectedCategory]);

  // Debounce de la búsqueda: mientras el comprador siga tecleando, el timeout
  // anterior se cancela y no se lanza la petición.
  useEffect(() => {
    if (searchInput === search) return;

    const timeout = setTimeout(() => {
      setSearch(searchInput);
      setPage(1);
    }, 300);

    return () => clearTimeout(timeout);
  }, [searchInput, search]);

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

  // Redireccionar en localhost si falta el slug
  const isSaaSBase = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
  if (isSaaSBase && !slug) {
    return <Navigate to="/login" replace />;
  }

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
  });

  const resolvedSlug = tenant?.slug;
  const money = (n: number | string) => formatMoney(n, tenant?.currency);

  // Fetch Pages públicas
  const { data: publicPages = [] } = useQuery<Page[]>({
    queryKey: ['publicPages', resolvedSlug],
    queryFn: () => getPublicPages(resolvedSlug!),
    enabled: !!resolvedSlug,
  });

  // Título y favicon de la pestaña según el tenant
  useTenantBranding(tenant);
  useTenantTheme(tenant);

  // Fetch active categories (since it's a shared db, we can fetch all, but the API filters by active tenant context based on headers? 
  // Wait, for public endpoints, does the API filter categories? 
  // Oh! CategoryController's index gets categories, but CategoryController is authenticated!
  // Wait, let's check: does PublicCatalogController have a categories endpoint?
  // Let's look at PublicCatalogController.php: it does NOT have a categories endpoint!
  // Wait, then how does the frontend display categories on the public catalog page?
  // Ah! PublicCatalogController@products returns the products with `with('category')` so we can collect unique categories from the products list, 
  // OR we can fetch them. Let's see: in `routes/api.php`:
  // Route::prefix('public/{slug}')->group(function () {
  //     Route::get('/',          [PublicCatalogController::class, 'tenant']);
  //     Route::get('/products',  [PublicCatalogController::class, 'products']);
  //     Route::get('/products/{product}', [PublicCatalogController::class, 'product']);
  // });
  // Indeed, there is no public categories list endpoint.
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

  const products = paginatedData?.data || [];
  const totalPages = paginatedData?.last_page || 1;

  const getPublicPath = (path: string) => {
    if (isCustomDomain) {
      return path;
    }
    return `/${resolvedSlug}${path}`;
  };

  // Extract unique spec values from products list
  useEffect(() => {
    if (Object.keys(selectedSpecs).length === 0 && paginatedData?.data) {
      const specsMap: Record<string, Set<string>> = {};
      paginatedData.data.forEach((prod) => {
        if (prod.specs) {
          Object.entries(prod.specs).forEach(([key, val]) => {
            if (key && val) {
              const valStr = val.toString().trim();
              if (valStr !== '') {
                if (!specsMap[key]) {
                  specsMap[key] = new Set<string>();
                }
                specsMap[key].add(valStr);
              }
            }
          });
        }
      });
      setAvailableSpecs(specsMap);
    }
  }, [paginatedData, selectedSpecs]);

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

  if (isLoadingTenant) {
    return (
      <div className="loader-container">
        <Loader2 className="spinner" size={40} />
        <p>Cargando tienda...</p>
        <style>{`
          .loader-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #0b0f19;
            color: #94a3b8;
            gap: 1rem;
          }
          .spinner { animation: spin 1s linear infinite; }
          @keyframes spin { to { transform: rotate(360deg); } }
        `}</style>
      </div>
    );
  }

  if (isErrorTenant || !tenant) {
    return (
      <div className="error-container">
        <Store size={48} />
        <h2>Tienda no encontrada</h2>
        <p>El catálogo que buscas no existe o se encuentra inactivo actualmente.</p>
        <Link to="/login" className="btn-primary" style={{textDecoration: 'none'}}>Ir al Inicio</Link>
        <style>{`
          .error-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #0b0f19;
            color: #f8fafc;
            gap: 1.5rem;
            text-align: center;
            padding: 2rem;
          }
          .error-container p { color: #94a3b8; max-width: 400px; margin-bottom: 0.5rem; }
        `}</style>
      </div>
    );
  }

  return (
    <div className="public-catalog-container animate-fade-in" style={{ fontFamily: getFontFamily(tenant.theme?.font) }}>
      {/* Header Store */}
      <header className="catalog-header glass-card">
        <div className="header-logo-area">
          <div className="store-logo">
            {tenant.logo_url ? (
              <img src={tenant.logo_url} alt={tenant.name} />
            ) : (
              <Store size={28} />
            )}
          </div>
          <h2>{tenant.name}</h2>
        </div>
        <div className="header-contact">
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
              <span className="cart-badge" style={{ backgroundColor: '#22c55e', width: '8px', height: '8px', minWidth: '8px', padding: 0 }} />
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
        </div>
      </header>

      {/* Renderizado de secciones editables de la portada */}
      {parsedSections
        .filter((sec: any) => sec.enabled)
        .map((sec: any) => {
          if (sec.type === 'hero') {
            return (
              <section
                key={sec.id}
                className="catalog-hero glass-card animate-fade-in"
                style={tenant.theme?.banner_url ? {
                  backgroundImage: `linear-gradient(to right, rgba(11,15,25,0.92), rgba(11,15,25,0.55)), url(${tenant.theme.banner_url})`,
                  backgroundSize: 'cover',
                  backgroundPosition: 'center',
                } : undefined}
              >
                <div className="hero-content">
                  <span
                    className="hero-badge"
                    style={tenant.theme?.accent_color ? { color: tenant.theme.accent_color, borderColor: tenant.theme.accent_color } : undefined}
                  >
                    CATÁLOGO VIRTUAL
                  </span>
                  <h1>{tenant.theme?.hero_title || 'Encuentra las mejores piezas de hardware'}</h1>
                  <p>{tenant.theme?.hero_subtitle || 'Explora nuestro catálogo en tiempo real, filtra por componentes y consulta existencias directamente por WhatsApp.'}</p>
                </div>
                <div className="hero-glow"></div>
              </section>
            );
          }

          if (sec.type === 'featured') {
            return (
              <div key={sec.id} className="catalog-content-layout animate-fade-in">
                {/* Filters Sidebar */}
                <aside className="catalog-sidebar glass-card">
                  <div className="sidebar-section">
                    <h3>Categorías</h3>
                    <div className="category-filters-list">
                      <button 
                        onClick={() => { setSelectedCategory(''); setPage(1); }} 
                        className={`category-filter-btn ${selectedCategory === '' ? 'active' : ''}`}
                      >
                        <span className="category-filter-label"><LayoutGrid size={16} /> Todos</span>
                      </button>
                      {publicCategories.map((cat) => (
                        <button 
                          key={cat.id} 
                          onClick={() => { setSelectedCategory(cat.id); setPage(1); }} 
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
                        onChange={(e) => { setInStock(e.target.checked); setPage(1); }}
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
                            onClick={() => { setSelectedSpecs({}); setPage(1); }}
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
                                      setSelectedSpecs((prev) => {
                                        const copy = { ...prev };
                                        if (isSelected) {
                                          delete copy[specKey];
                                        } else {
                                          copy[specKey] = val;
                                        }
                                        return copy;
                                      });
                                      setPage(1);
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
                        onChange={(e) => { setSort(e.target.value); setPage(1); }}
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
                                      background: 'rgba(15, 23, 42, 0.75)',
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
                                      fill={favoriteIds.includes(product.id) ? '#ef4444' : 'none'} 
                                      style={{ color: favoriteIds.includes(product.id) ? '#ef4444' : 'var(--text-muted)' }} 
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
                                  <img src={product.thumbnail_url} alt={product.name} />
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
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', color: '#fbbf24', fontSize: '0.78rem', fontWeight: 600 }}>
                                      <Star size={11} fill="#fbbf24" style={{ display: 'inline' }} />
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
                            onClick={() => setPage(page - 1)} 
                            disabled={page === 1}
                            className="btn-secondary pag-btn"
                          >
                            <ChevronLeft size={16} />
                          </button>
                          <span className="page-indicator">Página {page} de {totalPages}</span>
                          <button 
                            onClick={() => setPage(page + 1)} 
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
      />

        {/* Barra Flotante de Comparación */}
        {comparedProducts.length > 0 && (
          <div className="floating-compare-bar glass-card animate-slide-up">
            <div className="compare-bar-content">
              <div className="compare-thumbs">
                {comparedProducts.map((p) => (
                  <div key={p.id} className="compare-thumb-wrapper">
                    {p.thumbnail_url ? (
                      <img src={p.thumbnail_url} alt={p.name} />
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
                              <img src={p.thumbnail_url} alt={p.name} className="compare-header-img" />
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

        <footer className="catalog-footer text-muted" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '1.5rem', padding: '3rem 0', borderTop: '1px solid var(--border)', marginTop: '3rem', fontSize: '0.85rem' }}>
          {publicPages.length > 0 && (
            <div className="footer-links" style={{ display: 'flex', gap: '1.5rem', flexWrap: 'wrap', justifyContent: 'center' }}>
              {publicPages.map((page) => (
                <Link 
                  key={page.id} 
                  to={getPublicPath(`/p/${page.slug}`)} 
                  style={{ color: 'var(--text-secondary)', textDecoration: 'none' }}
                  className="hover-underline"
                >
                  {page.title}
                </Link>
              ))}
            </div>
          )}
          <p>&copy; {new Date().getFullYear()} {tenant?.name}. Todos los derechos reservados.</p>
        </footer>

      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;600&family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Outfit:wght@400;600;700;800&display=swap');

        .public-catalog-container {
          max-width: 1280px;
          margin: 0 auto;
          padding: 1.5rem;
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
          width: 100%;
        }

        /* Header */
        .catalog-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 1rem 2rem;
          border-radius: var(--radius-lg);
        }

        .header-logo-area {
          display: flex;
          align-items: center;
          gap: 1rem;
        }

        .store-logo {
          width: 42px;
          height: 42px;
          border-radius: 8px;
          background: rgba(255, 255, 255, 0.03);
          border: 1px solid var(--border);
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 1.25rem;
          overflow: hidden;
        }

        .store-logo img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .header-logo-area h2 {
          font-size: 1.15rem;
          font-family: var(--font-heading);
        }

        .whatsapp-header-btn {
          padding: 0.5rem 1.25rem;
          font-size: 0.85rem;
          background: #10b981;
          box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .whatsapp-header-btn:hover {
          background: #059669;
          box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
        }

        /* Hero */
        .catalog-hero {
          position: relative;
          padding: 3.5rem 2.5rem;
          border-radius: var(--radius-lg);
          overflow: hidden;
          background: linear-gradient(135deg, rgba(17, 24, 39, 0.9) 0%, rgba(15, 23, 42, 0.8) 100%);
        }

        .hero-content {
          position: relative;
          z-index: 5;
          max-width: 650px;
        }

        .hero-badge {
          display: inline-block;
          font-size: 0.7rem;
          font-weight: 700;
          color: var(--primary);
          border: 1px solid var(--primary);
          padding: 0.2rem 0.6rem;
          border-radius: 50px;
          margin-bottom: 1rem;
          letter-spacing: 0.05em;
        }

        .catalog-hero h1 {
          font-size: 2.25rem;
          margin-bottom: 1rem;
          line-height: 1.2;
          background: linear-gradient(135deg, #f8fafc 0%, #cbd5e1 100%);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
        }

        .catalog-hero p {
          color: var(--text-secondary);
          font-size: 1rem;
          line-height: 1.5;
        }

        .hero-glow {
          position: absolute;
          width: 300px;
          height: 300px;
          background: var(--primary);
          border-radius: 50%;
          filter: blur(100px);
          opacity: 0.15;
          top: -50px;
          right: -50px;
          pointer-events: none;
        }

        /* Layout */
        .catalog-content-layout {
          display: flex;
          gap: 1.5rem;
        }

        .catalog-sidebar {
          width: 280px;
          padding: 1.5rem;
          display: flex;
          flex-direction: column;
          gap: 2rem;
          height: fit-content;
        }

        .sidebar-section {
          display: flex;
          flex-direction: column;
          gap: 1rem;
        }

        .sidebar-section h3 {
          font-size: 0.9rem;
          text-transform: uppercase;
          letter-spacing: 0.05em;
          color: var(--text-muted);
          font-weight: 600;
        }

        .category-filters-list {
          display: flex;
          flex-direction: column;
          gap: 0.45rem;
        }

        .category-filter-btn {
          display: flex;
          align-items: center;
          width: 100%;
          padding: 0.65rem 0.85rem;
          background: transparent;
          border: 1px solid transparent;
          border-radius: var(--radius-md);
          color: var(--text-secondary);
          cursor: pointer;
          font-family: var(--font-sans);
          font-size: 0.9rem;
          font-weight: 500;
          text-align: left;
          transition: var(--transition);
        }

        .category-filter-label {
          display: inline-flex;
          align-items: center;
          gap: 0.55rem;
        }

        .category-filter-btn:hover {
          background: rgba(255, 255, 255, 0.02);
          color: var(--text-primary);
        }

        .category-filter-btn.active {
          background: var(--primary-glow);
          border-color: rgba(37, 99, 235, 0.2);
          color: var(--primary);
          font-weight: 600;
        }

        .checkbox-filter-label {
          display: flex;
          align-items: center;
          gap: 0.65rem;
          cursor: pointer;
          color: var(--text-secondary);
          font-size: 0.9rem;
        }

        .custom-checkbox {
          accent-color: var(--primary);
          width: 16px;
          height: 16px;
          cursor: pointer;
        }

        /* Main Area */
        .catalog-main-area {
          flex: 1;
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
        }

        .search-bar-row {
          padding: 0.85rem 1.25rem;
          display: flex;
          align-items: center;
          gap: 1rem;
          flex-wrap: wrap;
        }

        .search-box {
          position: relative;
          display: flex;
          align-items: center;
          flex: 1 1 260px;
        }

        .sort-box {
          display: flex;
          align-items: center;
          gap: 0.5rem;
          flex-shrink: 0;
        }

        .sort-label {
          font-size: 0.8rem;
          color: var(--text-secondary);
          white-space: nowrap;
        }

        .sort-select {
          padding: 0.5rem 0.75rem;
          font-size: 0.85rem;
          cursor: pointer;
        }

        @media (max-width: 640px) {
          .sort-box {
            width: 100%;
          }

          .sort-select {
            flex: 1;
          }
        }

        .search-icon {
          position: absolute;
          left: 1rem;
          color: var(--text-muted);
        }

        .search-box input {
          padding-left: 2.75rem;
        }

        .inner-loader {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          padding: 6rem 0;
          color: var(--text-secondary);
          gap: 1rem;
        }

        .empty-catalog {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          text-align: center;
          padding: 5rem 2rem;
          gap: 0.75rem;
          color: var(--text-secondary);
        }

        .empty-icon {
          color: var(--text-muted);
        }

        .empty-catalog h3 {
          font-size: 1.2rem;
          color: var(--text-primary);
        }

        /* Grid Cards */
        .catalog-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
          gap: 1.5rem;
        }

        /* Compact Layout Variations */
        .catalog-grid.layout-compact {
          grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
          gap: 0.75rem;
        }
        .catalog-grid.layout-compact .product-catalog-card {
          padding: 0.5rem;
        }
        .catalog-grid.layout-compact .card-details {
          padding: 0.5rem;
          gap: 0.35rem;
        }

        /* List Layout Variations */
        .catalog-grid.layout-list {
          display: flex;
          flex-direction: column;
          gap: 1rem;
        }
        .catalog-grid.layout-list .product-catalog-card {
          flex-direction: row;
          align-items: center;
          height: 120px;
          gap: 1.5rem;
          padding: 0.75rem 1.5rem;
        }
        .catalog-grid.layout-list .card-image-wrapper {
          width: 90px;
          height: 90px;
          aspect-ratio: 1/1;
          flex-shrink: 0;
          border-radius: var(--radius-md);
          overflow: hidden;
        }
        .catalog-grid.layout-list .card-details {
          flex: 1;
          display: grid;
          grid-template-columns: 2fr 1fr 1fr;
          align-items: center;
          gap: 1.5rem;
          padding: 0;
          text-align: left;
        }
        .catalog-grid.layout-list .card-brand {
          margin: 0;
        }
        .catalog-grid.layout-list .card-title {
          margin: 0;
          font-size: 1.05rem;
        }
        .catalog-grid.layout-list .card-footer {
          margin: 0;
          justify-content: flex-start;
          gap: 1rem;
        }
        .catalog-grid.layout-list .card-add-btn {
          width: auto;
          margin: 0;
          padding: 0.5rem 1.25rem;
          justify-self: end;
        }
        @media (max-width: 768px) {
          .catalog-grid.layout-list .product-catalog-card {
            flex-direction: column;
            height: auto;
            align-items: stretch;
            padding: 1rem;
            gap: 1rem;
          }
          .catalog-grid.layout-list .card-image-wrapper {
            width: 100%;
            height: auto;
            aspect-ratio: 16/9;
          }
          .catalog-grid.layout-list .card-details {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0.75rem;
          }
          .catalog-grid.layout-list .card-add-btn {
            width: 100%;
          }
        }

        .product-catalog-card {
          display: flex;
          flex-direction: column;
          text-decoration: none;
          overflow: hidden;
          transition: var(--transition);
        }

        .product-catalog-card:hover {
          border-color: var(--primary);
          box-shadow: 0 10px 25px var(--primary-glow);
        }

        .card-image-wrapper {
          position: relative;
          width: 100%;
          aspect-ratio: 1/1;
          background: rgba(255, 255, 255, 0.01);
          border-bottom: 1px solid var(--border);
          display: flex;
          align-items: center;
          justify-content: center;
          overflow: hidden;
        }

        .card-image-wrapper img {
          width: 100%;
          height: 100%;
          object-fit: cover;
          transition: var(--transition);
        }

        .product-catalog-card:hover .card-image-wrapper img {
          transform: scale(1.05);
        }

        .image-placeholder {
          color: var(--text-muted);
        }

        .card-badge {
          position: absolute;
          top: 0.75rem;
          left: 0.75rem;
          font-size: 0.65rem;
          font-weight: 700;
          padding: 0.25rem 0.5rem;
          border-radius: 4px;
          letter-spacing: 0.02em;
        }

        .card-badge.sold-out {
          background: rgba(239, 68, 68, 0.85);
          color: white;
          backdrop-filter: blur(4px);
        }

        .card-badge.low-stock {
          background: rgba(245, 158, 11, 0.85);
          color: white;
          backdrop-filter: blur(4px);
        }

        .card-badge.sale {
          background: var(--success);
          color: white;
          backdrop-filter: blur(4px);
        }

        .card-details {
          padding: 1.25rem;
          display: flex;
          flex-direction: column;
          gap: 0.35rem;
          flex: 1;
        }

        .card-brand {
          font-size: 0.75rem;
          font-weight: 600;
          color: var(--text-muted);
          text-transform: uppercase;
        }

        .card-title {
          font-size: 0.95rem;
          font-weight: 500;
          color: var(--text-primary);
          line-height: 1.3;
          margin-bottom: 0.5rem;
          display: -webkit-box;
          -webkit-line-clamp: 2;
          -webkit-box-orient: vertical;
          overflow: hidden;
          height: 2.6rem;
        }

        .card-footer {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-top: auto;
        }

        .card-price {
          font-size: 1.15rem;
          font-weight: 700;
          color: var(--text-primary);
        }

        .card-add-btn {
          margin-top: 0.85rem;
          width: 100%;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 0.4rem;
          padding: 0.55rem;
          border-radius: var(--radius-md);
          border: 1px solid var(--primary);
          background: var(--primary-glow);
          color: var(--primary);
          font-family: var(--font-sans);
          font-size: 0.85rem;
          font-weight: 600;
          cursor: pointer;
          transition: var(--transition);
        }
        .card-add-btn:hover:not(:disabled) { background: var(--primary); color: #fff; }
        .card-add-btn:disabled { opacity: 0.5; cursor: not-allowed; border-color: var(--border); color: var(--text-muted); background: transparent; }
        .card-add-btn.added { border-color: #10b981; color: #10b981; background: rgba(16, 185, 129, 0.05); }
        .card-add-btn.added:hover:not(:disabled) { background: #10b981; color: #fff; }

        .header-contact {
          display: flex;
          align-items: center;
          gap: 0.65rem;
        }

        .cart-trigger {
          position: relative;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          width: 44px;
          height: 44px;
          border-radius: var(--radius-md);
          border: 1px solid var(--border);
          background: transparent;
          color: var(--text-primary);
          cursor: pointer;
          transition: var(--transition);
        }
        .cart-trigger:hover { border-color: var(--primary); color: var(--primary); }
        .cart-badge {
          position: absolute;
          top: -6px;
          right: -6px;
          min-width: 20px;
          height: 20px;
          padding: 0 5px;
          border-radius: 10px;
          background: var(--primary);
          color: #fff;
          font-size: 0.7rem;
          font-weight: 700;
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .view-detail-link {
          display: flex;
          align-items: center;
          font-size: 0.75rem;
          font-weight: 600;
          color: var(--primary);
          opacity: 0;
          transform: translateX(-4px);
          transition: var(--transition);
        }

        .product-catalog-card:hover .view-detail-link {
          opacity: 1;
          transform: translateX(0);
        }

        /* Pagination style */
        .pagination-bar {
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 1.5rem;
          margin-top: 1rem;
        }

        .pag-btn {
          width: 38px;
          height: 38px;
          padding: 0;
          border-radius: 50%;
        }

        .page-indicator {
          font-size: 0.85rem;
          color: var(--text-secondary);
        }

        /* Comparación */
        .compare-toggle-badge {
          position: absolute;
          top: 0.75rem;
          right: 0.75rem;
          background: rgba(11, 15, 25, 0.7);
          backdrop-filter: blur(4px);
          border: 1px solid var(--border);
          color: var(--text-secondary);
          padding: 0.3rem 0.6rem;
          font-size: 0.7rem;
          border-radius: 4px;
          cursor: pointer;
          transition: var(--transition);
          z-index: 5;
          font-weight: 600;
        }
        .compare-toggle-badge:hover {
          background: var(--primary);
          color: white;
          border-color: var(--primary);
        }
        .compare-toggle-badge.active {
          background: rgba(var(--primary-rgb), 0.2);
          color: var(--primary);
          border-color: var(--primary);
        }

        .floating-compare-bar {
          position: fixed;
          bottom: 2rem;
          left: 50%;
          transform: translateX(-50%);
          background: rgba(13, 18, 32, 0.85);
          backdrop-filter: blur(12px);
          border: 1px solid var(--border);
          border-radius: var(--radius-lg);
          padding: 0.75rem 1.5rem;
          z-index: 500;
          box-shadow: 0 10px 30px rgba(0,0,0,0.5);
          width: calc(100% - 4rem);
          max-width: 600px;
        }
        .compare-bar-content {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 1.5rem;
        }
        .compare-thumbs {
          display: flex;
          gap: 0.5rem;
        }
        .compare-thumb-wrapper {
          position: relative;
          width: 48px;
          height: 48px;
          border-radius: 6px;
          background: rgba(0,0,0,0.2);
          border: 1px solid var(--border);
          overflow: hidden;
        }
        .compare-thumb-wrapper img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }
        .remove-thumb-btn {
          position: absolute;
          top: -2px;
          right: -2px;
          background: var(--danger);
          color: white;
          border: none;
          border-radius: 50%;
          width: 14px;
          height: 14px;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
        }
        .compare-thumb-placeholder {
          width: 48px;
          height: 48px;
          border-radius: 6px;
          border: 1px dashed var(--border);
          display: flex;
          align-items: center;
          justify-content: center;
          color: var(--text-muted);
          font-size: 1.2rem;
        }
        .compare-bar-actions {
          display: flex;
          gap: 0.75rem;
          align-items: center;
        }
        .btn-clear-compare {
          background: transparent;
          border: none;
          color: var(--text-secondary);
          cursor: pointer;
          font-size: 0.85rem;
        }
        .btn-clear-compare:hover {
          color: var(--danger);
        }

        .compare-modal-overlay {
          position: fixed;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          background: rgba(3, 7, 18, 0.75);
          backdrop-filter: blur(6px);
          z-index: 1000;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 2rem;
        }
        .compare-modal-content {
          width: 100%;
          max-width: 860px;
          max-height: 85vh;
          overflow-y: auto;
          border-radius: var(--radius-lg);
          padding: 2rem;
          background: #0d1220;
        }
        .compare-table-wrapper {
          overflow-x: auto;
          margin-top: 1.5rem;
        }
        .compare-table {
          width: 100%;
          border-collapse: collapse;
          text-align: left;
          font-size: 0.9rem;
        }
        .compare-table th, .compare-table td {
          padding: 1rem;
          border-bottom: 1px solid var(--border);
          vertical-align: top;
        }
        .compare-table th {
          background: rgba(255,255,255,0.01);
        }
        .compare-header-item {
          display: flex;
          flex-direction: column;
          align-items: center;
          text-align: center;
          gap: 0.5rem;
        }
        .compare-header-img {
          width: 64px;
          height: 64px;
          border-radius: 6px;
          object-fit: cover;
          background: rgba(0,0,0,0.25);
          border: 1px solid var(--border);
        }
        .compare-header-brand {
          font-size: 0.7rem;
          color: var(--text-muted);
          text-transform: uppercase;
        }
        .compare-header-title {
          font-size: 0.85rem;
          margin: 0;
          font-weight: 600;
          display: -webkit-box;
          -webkit-line-clamp: 2;
          -webkit-box-orient: vertical;
          overflow: hidden;
          min-height: 2.4rem;
        }
        .compare-header-price {
          font-size: 1rem;
          color: var(--primary);
          font-weight: 700;
        }
        .compare-add-btn {
          padding: 0.4rem 0.8rem;
          font-size: 0.75rem;
          width: 100%;
          justify-content: center;
        }
        .text-success { color: #34d399; }
        .text-danger { color: #f87171; }

        /* Responsive */
        @media (max-width: 960px) {
          .catalog-content-layout {
            flex-direction: column;
          }
          .catalog-sidebar {
            width: 100%;
          }
        }
      `}</style>
    </div>
  );
}

const getFontFamily = (fontName?: string | null) => {
  switch (fontName) {
    case 'serif': return "'Merriweather', Georgia, serif";
    case 'mono': return "Consolas, 'Fira Code', Monaco, monospace";
    case 'heading': return "'Outfit', 'Montserrat', sans-serif";
    default: return "'Inter', system-ui, sans-serif";
  }
};

