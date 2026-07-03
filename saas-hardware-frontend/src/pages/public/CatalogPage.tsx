import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
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
  Plus
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import api from '../../api/axios';
import { getPublicTenant, getPublicProducts } from '../../api/public';
import CategoryIcon from '../../components/ui/CategoryIcon';
import CartDrawer from '../../components/public/CartDrawer';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';
import { useCartStore } from '../../stores/cartStore';
import type { Tenant, Product, Category, PaginatedResponse } from '../../types';

export default function CatalogPage() {
  const { slug } = useParams<{ slug: string }>();

  // States
  const [search, setSearch] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('');
  const [inStock, setInStock] = useState(false);
  const [page, setPage] = useState(1);
  const [cartOpen, setCartOpen] = useState(false);
  const [selectedSpecs, setSelectedSpecs] = useState<Record<string, string>>({});
  const [availableSpecs, setAvailableSpecs] = useState<Record<string, Set<string>>>({});

  // Reset filter when changing category
  useEffect(() => {
    setSelectedSpecs({});
  }, [selectedCategory]);

  // Carrito
  const addItem = useCartStore((s) => s.addItem);
  const cartCount = useCartStore((s) => s.totalItems());

  const handleAddToCart = (e: React.MouseEvent, product: Product) => {
    e.preventDefault();
    e.stopPropagation();
    addItem(slug!, product);
    toast.success(`${product.name} agregado al pedido`);
  };

  // Fetch Tenant Info
  const { data: tenant, isLoading: isLoadingTenant, isError: isErrorTenant } = useQuery<Tenant>({
    queryKey: ['publicTenant', slug],
    queryFn: () => getPublicTenant(slug!),
    enabled: !!slug,
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
  // But wait! If we want to show category filters in the public page, we can list them from products, or we can add a public categories endpoint, 
  // OR we can make a query. Let's check: in `PublicCatalogController.php`, can we get categories?
  // Wait! In `PublicCatalogController.php`, the products are fetched with category:
  // `->with('category:id,name,icon')`
  // Also, we can extract the categories from the products list in the frontend! 
  // That works, but it only displays categories of the current page.
  // Let's check if we can add a public categories route in the backend or if we can query it easily.
  // Wait, can we edit `routes/api.php` and `PublicCatalogController.php` to add a public categories endpoint?
  // Yes, that would be a very nice addition to make filtering 100% robust and clean!
  // Let's check if Category has `tenant_id` and can be retrieved publicly. Yes!
  // Let's add `Route::get('/categories', [PublicCatalogController::class, 'categories']);` to `routes/api.php` under the public prefix!
  // First, let's write the frontend code. I will use the products categories for now, or fetch from the new endpoint. Let's add the endpoint in the backend to make the app flawless.
  // Let's write `PublicCatalogController@categories` and add the route.
  // Wait, let's first check if we can write `CatalogPage.tsx` expecting a `/public/{slug}/categories` endpoint, and then implement it. Yes! That is extremely clean.
  
  const { data: publicCategories = [] } = useQuery<Category[]>({
    queryKey: ['publicCategories', slug],
    queryFn: async () => {
      const res = await api.get<Category[]>(`/public/${slug}/categories`);
      return res.data;
    },
    enabled: !!slug,
  });

  // Fetch Public Products
  const { data: paginatedData, isLoading: isLoadingProducts } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['publicProducts', slug, search, selectedCategory, inStock, selectedSpecs, page],
    queryFn: () => getPublicProducts(slug!, {
      category_id: selectedCategory || undefined,
      search: search || undefined,
      in_stock: inStock || undefined,
      specs: Object.keys(selectedSpecs).length > 0 ? selectedSpecs : undefined,
      page,
    }),
    enabled: !!slug,
  });

  const products = paginatedData?.data || [];
  const totalPages = paginatedData?.last_page || 1;

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
    <div className="public-catalog-container animate-fade-in">
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

      {/* Hero Catalog */}
      <section
        className="catalog-hero glass-card"
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

      {/* Catalog Content Layout */}
      <div className="catalog-content-layout">
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
                placeholder="Buscar componente..."
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                className="premium-input"
              />
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
              <div className="catalog-grid">
                {products.map((product) => (
                  <Link 
                    key={product.id} 
                    to={`/${slug}/product/${product.id}`} 
                    className="product-catalog-card glass-card"
                  >
                    <div className="card-image-wrapper">
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
                      <span className="card-brand">{product.brand || 'Genérico'}</span>
                      <h3 className="card-title">{product.name}</h3>
                      <div className="card-footer">
                        <span className="card-price">
                          {product.sale_price !== null && product.sale_price !== undefined ? (
                            <>
                              <span className="strike-price" style={{ textDecoration: 'line-through', marginRight: '0.4rem', opacity: 0.5, fontSize: '0.85em', fontWeight: 'normal' }}>
                                ${parseFloat(product.price.toString()).toFixed(2)}
                              </span>
                              <span>${parseFloat(product.sale_price.toString()).toFixed(2)}</span>
                            </>
                          ) : (
                            `$${parseFloat(product.price.toString()).toFixed(2)}`
                          )}
                        </span>
                        <span className="view-detail-link">
                          Ver más <ChevronRight size={14} />
                        </span>
                      </div>
                      <button
                        type="button"
                        className="card-add-btn"
                        onClick={(e) => handleAddToCart(e, product)}
                        disabled={product.stock === 0}
                      >
                        <Plus size={15} /> {product.stock === 0 ? 'Agotado' : 'Agregar'}
                      </button>
                    </div>
                  </Link>
                ))}
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

        <CartDrawer open={cartOpen} onClose={() => setCartOpen(false)} slug={slug!} tenant={tenant} />
      </div>

      <style>{`
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
        }

        .search-box {
          position: relative;
          display: flex;
          align-items: center;
          width: 100%;
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

