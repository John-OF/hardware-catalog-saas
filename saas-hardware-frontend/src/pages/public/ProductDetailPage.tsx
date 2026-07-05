import { useState, useEffect, useRef } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { 
  ArrowLeft,
  ShoppingBag,
  CheckCircle,
  XCircle,
  Loader2,
  Phone,
  Plus,
  X,
  ChevronLeft,
  ChevronRight,
  Star
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { getPublicTenant, getPublicProduct, resolveTenantDomain, createPublicReview } from '../../api/public';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';
import { useCartStore } from '../../stores/cartStore';
import { useAuthStore } from '../../stores/authStore';
import type { Tenant, Product } from '../../types';

export default function ProductDetailPage() {
  const { slug, id } = useParams<{ slug: string; id: string }>();
  const isCustomDomain = !slug;
  const currentDomain = window.location.hostname;
  const navigate = useNavigate();
  const [activeImage, setActiveImage] = useState<string | null>(null);

  // Auth info
  const token = useAuthStore((s) => s.token);
  const isLoggedIn = !!token;

  // Cart info
  const cartItems = useCartStore((s) => s.items);

  // Review form state
  const [isReviewFormOpen, setIsReviewFormOpen] = useState(false);
  const [formName, setFormName] = useState('');
  const [formEmail, setFormEmail] = useState('');
  const [formPhone, setFormPhone] = useState('');
  const [countryCode, setCountryCode] = useState('+593');
  const [formRating, setFormRating] = useState(5);
  const [formComment, setFormComment] = useState('');
  const [turnstileToken, setTurnstileToken] = useState<string | null>(null);

  const queryClient = useQueryClient();

  const turnstileContainerRef = useRef<HTMLDivElement>(null);
  const widgetIdRef = useRef<string | null>(null);

  // Generate visitor_id if it doesn't exist
  useEffect(() => {
    let visitorId = localStorage.getItem('visitor_id');
    if (!visitorId) {
      visitorId = crypto.randomUUID?.() || 'v_' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
      localStorage.setItem('visitor_id', visitorId);
    }
    const expires = new Date();
    expires.setTime(expires.getTime() + (365 * 24 * 60 * 60 * 1000));
    document.cookie = `visitor_id=${visitorId};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
  }, []);

  // Load Turnstile script dynamically
  useEffect(() => {
    const scriptId = 'cloudflare-turnstile-script';
    let script = document.getElementById(scriptId) as HTMLScriptElement;
    if (!script) {
      script = document.createElement('script');
      script.id = scriptId;
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
      script.async = true;
      script.defer = true;
      document.body.appendChild(script);
    }
  }, []);

  // Render Turnstile widget
  useEffect(() => {
    if (isReviewFormOpen && turnstileContainerRef.current) {
      const checkTurnstile = setInterval(() => {
        if ((window as any).turnstile) {
          clearInterval(checkTurnstile);
          
          if (widgetIdRef.current) {
            try {
              (window as any).turnstile.remove(widgetIdRef.current);
            } catch (e) {
              console.error(e);
            }
          }
          
          widgetIdRef.current = (window as any).turnstile.render(turnstileContainerRef.current, {
            sitekey: import.meta.env.VITE_TURNSTILE_SITEKEY || '1x00000000000000000000AA',
            callback: (token: string) => {
              setTurnstileToken(token);
            },
            'error-callback': () => {
              toast.error('Error al cargar la validación anti-bot.');
            }
          });
        }
      }, 100);

      return () => {
        clearInterval(checkTurnstile);
        if (widgetIdRef.current && (window as any).turnstile) {
          try {
            (window as any).turnstile.remove(widgetIdRef.current);
          } catch (e) {
            console.error(e);
          }
        }
      };
    }
  }, [isReviewFormOpen]);

  // Fetch Tenant Info (to maintain active styling/colors)
  const { data: tenant } = useQuery<Tenant>({
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

  const reviewMutation = useMutation({
    mutationFn: (payload: { customer_name: string; customer_email?: string; customer_phone?: string; rating: number; comment?: string; visitor_id?: string; turnstile_token: string }) =>
      createPublicReview(resolvedSlug!, id!, payload),
    onSuccess: (data: any) => {
      toast.success(data.message || '¡Gracias! Tu reseña ha sido guardada.');
      queryClient.invalidateQueries({ queryKey: ['publicProduct', resolvedSlug, id] });
      setFormName('');
      setFormEmail('');
      setFormPhone('');
      setFormRating(5);
      setFormComment('');
      setTurnstileToken(null);
      setIsReviewFormOpen(false);
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al enviar la reseña. Inténtalo de nuevo.';
      toast.error(msg);
      if (widgetIdRef.current && (window as any).turnstile) {
        (window as any).turnstile.reset(widgetIdRef.current);
      }
      setTurnstileToken(null);
    }
  });

  const handleReviewSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!formName.trim()) {
      toast.error('Por favor, ingresa tu nombre.');
      return;
    }
    if (!turnstileToken) {
      toast.error('Por favor, completa la verificación anti-bot.');
      return;
    }
    
    // Si no está logueado y provee un teléfono, combinamos código de país y número
    const submittedPhone = (!isLoggedIn && formPhone.trim()) 
      ? (countryCode + formPhone.trim()) 
      : undefined;

    reviewMutation.mutate({
      customer_name: formName,
      customer_email: formEmail || undefined,
      customer_phone: submittedPhone,
      rating: formRating,
      comment: formComment || undefined,
      visitor_id: localStorage.getItem('visitor_id') || undefined,
      turnstile_token: turnstileToken
    });
  };

  useTenantTheme(tenant);

  // Fetch Product Info
  const { data: product, isLoading, isError } = useQuery<Product>({
    queryKey: ['publicProduct', resolvedSlug, id],
    queryFn: () => getPublicProduct(resolvedSlug!, id!),
    enabled: !!resolvedSlug && !!id,
  });

  // Título y favicon de la pestaña: "Producto · Mi Tienda"
  useTenantBranding(tenant, product?.name);

  useEffect(() => {
    if (product) {
      setActiveImage(product.image_url);
    }
  }, [product]);

  const addItem = useCartStore((s) => s.addItem);

  const handleAddToCart = () => {
    if (!product || !resolvedSlug) return;
    addItem(resolvedSlug, product);
    toast.success(`${product.name} agregado al pedido`);
  };

  const [isLightboxOpen, setIsLightboxOpen] = useState(false);

  // Collect all images including main and gallery
  const allImages = product
    ? [product.image_url, ...(product.images || []).map((img) => img.image_url)].filter(Boolean) as string[]
    : [];

  const currentImageIndex = allImages.indexOf(activeImage || '');

  const handlePrevImage = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (currentImageIndex > 0) {
      setActiveImage(allImages[currentImageIndex - 1]);
    } else {
      setActiveImage(allImages[allImages.length - 1]);
    }
  };

  const handleNextImage = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (currentImageIndex < allImages.length - 1) {
      setActiveImage(allImages[currentImageIndex + 1]);
    } else {
      setActiveImage(allImages[0]);
    }
  };

  // Escuchar tecla Escape para cerrar lightbox o regresar al catálogo
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        if (isLightboxOpen) {
          setIsLightboxOpen(false);
        } else {
          navigate(isCustomDomain ? '/' : `/${resolvedSlug}`);
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isLightboxOpen, slug, resolvedSlug, isCustomDomain, navigate]);

  // Whatsapp redirect handler
  const handleWhatsappQuery = () => {
    if (!product || !tenant) return;

    const currentUrl = window.location.href;
    const finalPrice = product.sale_price !== null && product.sale_price !== undefined ? product.sale_price : product.price;
    const baseMessage = `Hola, estoy interesado en el producto *${product.name}* (Precio: $${parseFloat(finalPrice.toString()).toFixed(2)}) de tu catálogo virtual. ¿Se encuentra disponible?\n\nEnlace del producto: ${currentUrl}`;
    const encodedMessage = encodeURIComponent(baseMessage);
    
    // Quitar cualquier carácter que no sea numérico del teléfono
    const cleanPhone = tenant.whatsapp_number.replace(/[^0-9]/g, '');
    
    window.open(`https://wa.me/${cleanPhone}?text=${encodedMessage}`, '_blank');
  };

  if (isLoading) {
    return (
      <div className="loader-container">
        <Loader2 className="spinner" size={40} />
        <p>Cargando detalles del producto...</p>
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

  if (isError || !product) {
    return (
      <div className="error-container">
        <ShoppingBag size={48} />
        <h2>Producto no encontrado</h2>
        <p>El producto que buscas no existe en este catálogo o ha sido desactivado.</p>
        <Link to={isCustomDomain ? '/' : `/${resolvedSlug}`} className="btn-primary" style={{textDecoration: 'none'}}>Volver al Catálogo</Link>
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

  const mainCartItem = cartItems.find((item) => item.product.id === product?.id);
  const mainQtyInCart = mainCartItem ? mainCartItem.quantity : 0;

  return (
    <div className="product-detail-container animate-fade-in">
      {/* Back button */}
      <div className="back-navigation">
        <Link to={isCustomDomain ? '/' : `/${resolvedSlug}`} className="btn-secondary back-btn">
          <ArrowLeft size={16} />
          <span>Volver al catálogo</span>
        </Link>
      </div>

      {/* Main product card */}
      <div className="product-showcase glass-card animate-scale-in">
        
        {/* Product image block */}
        <div className="product-image-block">
          <div 
            className="product-main-image-container" 
            onClick={() => setIsLightboxOpen(true)}
            style={{ 
              display: 'flex', 
              justifyContent: 'center', 
              alignItems: 'center', 
              width: '100%', 
              height: '350px', 
              background: 'rgba(255,255,255,0.01)', 
              borderRadius: 'var(--radius-lg)', 
              border: '1px solid var(--border)', 
              overflow: 'hidden',
              cursor: 'zoom-in'
            }}
          >
            {activeImage ? (
              <img src={activeImage} alt={product.name} className="product-main-img" style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' }} />
            ) : (
              <div className="product-placeholder-img" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--text-muted)' }}>
                <ShoppingBag size={64} />
              </div>
            )}
          </div>

          {/* Gallery thumbnails */}
          {product.images && product.images.length > 0 && (
            <div className="product-gallery-thumbnails" style={{ display: 'flex', gap: '0.5rem', marginTop: '0.75rem', overflowX: 'auto', paddingBottom: '0.25rem' }}>
              {/* Main image thumbnail */}
              {product.image_url && (
                <button
                  type="button"
                  className={`gallery-thumb-btn ${activeImage === product.image_url ? 'active' : ''}`}
                  onClick={() => setActiveImage(product.image_url)}
                  style={{
                    width: '60px',
                    height: '60px',
                    borderRadius: 'var(--radius-sm)',
                    border: activeImage === product.image_url ? '2px solid var(--primary)' : '1px solid var(--border)',
                    background: 'rgba(255,255,255,0.02)',
                    padding: '2px',
                    cursor: 'pointer',
                    overflow: 'hidden',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    transition: 'var(--transition)',
                  }}
                >
                  <img src={product.thumbnail_url || product.image_url} alt="Main thumbnail" style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: '2px' }} />
                </button>
              )}
              {/* Gallery images thumbnails */}
              {product.images.map((img) => (
                <button
                  key={img.id}
                  type="button"
                  className={`gallery-thumb-btn ${activeImage === img.image_url ? 'active' : ''}`}
                  onClick={() => setActiveImage(img.image_url)}
                  style={{
                    width: '60px',
                    height: '60px',
                    borderRadius: 'var(--radius-sm)',
                    border: activeImage === img.image_url ? '2px solid var(--primary)' : '1px solid var(--border)',
                    background: 'rgba(255,255,255,0.02)',
                    padding: '2px',
                    cursor: 'pointer',
                    overflow: 'hidden',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    transition: 'var(--transition)',
                  }}
                >
                  <img src={img.thumbnail_url || img.image_url} alt="Gallery thumbnail" style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: '2px' }} />
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Product details block */}
        <div className="product-details-block">
          <div className="brand-category-row">
            <span className="detail-brand">{product.brand || 'Genérico'}</span>
            {product.category && (
              <span className="detail-category">{product.category.name}</span>
            )}
          </div>

          <h1 className="detail-title">{product.name}</h1>
          {product.reviews_count && product.reviews_count > 0 ? (
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginTop: '-0.75rem', marginBottom: '0.25rem' }}>
              <div style={{ display: 'flex', color: '#fbbf24' }}>
                {Array.from({ length: 5 }).map((_, i) => (
                  <Star
                    key={i}
                    size={14}
                    fill={i < Math.round(Number(product.reviews_avg_rating || 0)) ? '#fbbf24' : 'none'}
                  />
                ))}
              </div>
              <span style={{ fontSize: '0.85rem', color: 'var(--text-secondary)', fontWeight: 600 }}>
                {parseFloat(product.reviews_avg_rating!.toString()).toFixed(1)}
              </span>
              <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>
                ({product.reviews_count} {product.reviews_count === 1 ? 'calificación' : 'calificaciones'})
              </span>
            </div>
          ) : null}

          {/* Pricing & Stock card */}
          <div className="pricing-stock-card">
            <div className="detail-price-box">
              <span className="price-label">
                {product.sale_price !== null && product.sale_price !== undefined ? 'Precio de Oferta' : 'Precio Sugerido'}
              </span>
              <span className="detail-price">
                {product.sale_price !== null && product.sale_price !== undefined ? (
                  <>
                    <span className="strike-price" style={{ textDecoration: 'line-through', marginRight: '0.75rem', opacity: 0.5, fontSize: '0.7em', fontWeight: 'normal' }}>
                      ${parseFloat(product.price.toString()).toFixed(2)}
                    </span>
                    <span>${parseFloat(product.sale_price.toString()).toFixed(2)}</span>
                  </>
                ) : (
                  `$${parseFloat(product.price.toString()).toFixed(2)}`
                )}
              </span>
            </div>

            <div className="detail-stock-box">
              {product.stock > 0 ? (
                <div className="stock-indicator instock">
                  <CheckCircle size={18} />
                  <div>
                    <span className="stock-state">En Stock</span>
                    <span className="stock-count">{product.stock} unidades disponibles</span>
                  </div>
                </div>
              ) : (
                <div className="stock-indicator outofstock">
                  <XCircle size={18} />
                  <div>
                    <span className="stock-state">Agotado</span>
                    <span className="stock-count">Sin existencias actuales</span>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Call to action */}
          <div className="detail-cta-row">
            <button
              onClick={handleAddToCart}
              className={`btn-secondary add-cart-btn ${mainQtyInCart > 0 ? 'added' : ''}`}
              disabled={product.stock === 0}
            >
              {product.stock === 0 ? (
                <>
                  <Plus size={18} />
                  <span>Agotado</span>
                </>
              ) : mainQtyInCart > 0 ? (
                <>
                  <CheckCircle size={18} style={{ color: '#10b981' }} />
                  <span>En el carrito ({mainQtyInCart})</span>
                </>
              ) : (
                <>
                  <Plus size={18} />
                  <span>Agregar al pedido</span>
                </>
              )}
            </button>
            <button
              onClick={handleWhatsappQuery}
              className="btn-primary whatsapp-buy-btn"
              disabled={product.stock === 0}
            >
              <Phone size={18} />
              <span>Consultar</span>
            </button>
          </div>

          {/* Description */}
          {product.description && (
            <div className="detail-description-section">
              <h3>Descripción</h3>
              <div 
                className="rich-description-html" 
                dangerouslySetInnerHTML={{ __html: product.description }} 
                style={{ fontSize: '0.95rem', lineHeight: '1.6', color: 'var(--text-secondary)' }}
              />
            </div>
          )}

          {/* Specifications table */}
          {product.specs && Object.keys(product.specs).length > 0 && (
            <div className="detail-specs-section">
              <h3>Especificaciones Técnicas</h3>
              <div className="specs-table-wrapper">
                <table className="specs-table">
                  <tbody>
                    {Object.entries(product.specs).map(([key, val]) => (
                      <tr key={key}>
                        <td className="spec-name">{key}</td>
                        <td className="spec-value">{val}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

        </div>
      </div>

      {/* Related Products / Cross-selling Section */}
      {product.related_products && product.related_products.length > 0 && (
        <div className="related-products-section glass-card animate-scale-in" style={{ padding: '2rem', borderRadius: 'var(--radius-xl)', display: 'flex', flexDirection: 'column', gap: '1.5rem', background: 'rgba(255, 255, 255, 0.01)', border: '1px solid var(--border)', marginBottom: '1.5rem' }}>
          <div>
            <h2 style={{ fontSize: '1.5rem', fontFamily: 'var(--font-heading)', color: 'var(--text-primary)', margin: 0 }}>
              Productos Compatibles y Recomendados
            </h2>
            <p style={{ margin: '0.25rem 0 0 0', fontSize: '0.85rem', color: 'var(--text-muted)' }}>
              Sugerencias para armar tu configuración ideal o alternativas recomendadas.
            </p>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '1.25rem' }}>
            {product.related_products.map((p) => {
              const hasSale = p.sale_price !== null && p.sale_price !== undefined;
              const price = hasSale ? p.sale_price : p.price;
              const displayImageUrl = p.images?.[0]?.image_url || p.image_url;

              return (
                <div 
                  key={p.id} 
                  className="related-product-card"
                  style={{
                    background: 'rgba(255, 255, 255, 0.015)',
                    border: '1px solid var(--border)',
                    borderRadius: 'var(--radius-lg)',
                    padding: '1rem',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    gap: '0.75rem',
                    transition: 'transform 0.2s ease, border-color 0.2s ease',
                    cursor: 'pointer'
                  }}
                  onClick={() => {
                    const path = resolvedSlug ? `/${resolvedSlug}/product/${p.id}` : `/product/${p.id}`;
                    navigate(path);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                  }}
                >
                  <div style={{ position: 'relative', width: '100%', height: '140px', background: 'rgba(0,0,0,0.15)', borderRadius: 'var(--radius-md)', overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    {displayImageUrl ? (
                      <img 
                        src={displayImageUrl} 
                        alt={p.name} 
                        style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' }}
                      />
                    ) : (
                      <ShoppingBag size={32} style={{ color: 'var(--text-muted)' }} />
                    )}
                    {p.stock === 0 && (
                      <span className="card-badge sold-out" style={{ position: 'absolute', top: '0.5rem', left: '0.5rem', background: 'var(--danger)', color: 'white', fontSize: '0.7rem', padding: '0.15rem 0.4rem', borderRadius: '4px', fontWeight: 600 }}>
                        Agotado
                      </span>
                    )}
                    {hasSale && p.stock > 0 && (
                      <span className="card-badge sale" style={{ position: 'absolute', top: '0.5rem', left: '0.5rem', background: 'var(--primary)', color: 'white', fontSize: '0.7rem', padding: '0.15rem 0.4rem', borderRadius: '4px', fontWeight: 600 }}>
                        Oferta
                      </span>
                    )}
                  </div>

                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem', flex: 1 }}>
                    <span style={{ fontSize: '0.75rem', color: 'var(--primary)', fontWeight: 600, textTransform: 'uppercase' }}>
                      {p.category?.name}
                    </span>
                    <h4 style={{ margin: 0, fontSize: '0.88rem', color: 'var(--text-primary)', fontWeight: 600, lineClamp: 2, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden', height: '2.5rem', lineHeight: '1.25' }}>
                      {p.name}
                    </h4>
                  </div>

                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: '0.25rem' }}>
                    <div style={{ display: 'flex', flexDirection: 'column' }}>
                      {hasSale && (
                        <span style={{ fontSize: '0.75rem', textDecoration: 'line-through', color: 'var(--text-muted)' }}>
                          ${Number(p.price).toFixed(2)}
                        </span>
                      )}
                      <span style={{ fontSize: '0.98rem', fontWeight: 700, color: 'white' }}>
                        ${Number(price).toFixed(2)}
                      </span>
                    </div>

                    {(() => {
                      const relCartItem = cartItems.find((item) => item.product.id === p.id);
                      const relQty = relCartItem ? relCartItem.quantity : 0;

                      return (
                        <button 
                          type="button"
                          disabled={p.stock === 0}
                          className="btn-icon"
                          style={{ 
                            padding: '0.35rem 0.6rem', 
                            borderRadius: '20px', 
                            background: relQty > 0 ? 'rgba(16, 185, 129, 0.1)' : 'rgba(255,255,255,0.05)', 
                            color: p.stock === 0 ? 'var(--text-muted)' : relQty > 0 ? '#10b981' : 'var(--primary)',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '0.2rem',
                            fontSize: '0.8rem',
                            border: relQty > 0 ? '1px solid rgba(16, 185, 129, 0.2)' : 'none'
                          }}
                          onClick={(e) => {
                            e.stopPropagation();
                            if (p.stock > 0) {
                              addItem(resolvedSlug || '', p);
                              toast.success('Producto agregado al pedido.');
                            }
                          }}
                        >
                          {relQty > 0 ? (
                            <>
                              <CheckCircle size={14} />
                              <span>({relQty})</span>
                            </>
                          ) : (
                            <Plus size={14} />
                          )}
                        </button>
                      );
                    })()}
                  </div>
                </div>
              );
            })}
          </div>

          <style>{`
            .related-product-card:hover {
              transform: translateY(-4px);
              border-color: var(--primary) !important;
              box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            }
          `}</style>
        </div>
      )}

      {/* Reviews Section */}
      <div className="reviews-section glass-card animate-scale-in" style={{ padding: '2rem', borderRadius: 'var(--radius-xl)', display: 'flex', flexDirection: 'column', gap: '1.5rem', background: 'rgba(255, 255, 255, 0.01)', border: '1px solid var(--border)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem', flexWrap: 'wrap', gap: '1rem' }}>
          <div>
            <h2 style={{ fontSize: '1.5rem', fontFamily: 'var(--font-heading)', color: 'var(--text-primary)', margin: 0 }}>
              Calificaciones y Reseñas
            </h2>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginTop: '0.5rem' }}>
              <div style={{ display: 'flex', color: '#fbbf24' }}>
                {Array.from({ length: 5 }).map((_, i) => (
                  <Star
                    key={i}
                    size={16}
                    fill={i < Math.round(Number(product.reviews_avg_rating || 0)) ? '#fbbf24' : 'none'}
                  />
                ))}
              </div>
              <span style={{ fontSize: '0.95rem', color: 'var(--text-secondary)', fontWeight: 600 }}>
                {product.reviews_avg_rating ? parseFloat(product.reviews_avg_rating.toString()).toFixed(1) : '0.0'} de 5
              </span>
              <span style={{ fontSize: '0.95rem', color: 'var(--text-muted)' }}>
                ({product.reviews_count || 0} {product.reviews_count === 1 ? 'reseña' : 'reseñas'})
              </span>
            </div>
          </div>

          {(product as any)?.user_review ? (
            <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)', background: 'rgba(255,255,255,0.03)', padding: '0.5rem 1rem', borderRadius: 'var(--radius-md)', border: '1px solid var(--border)' }}>
              Ya calificaste este producto
            </span>
          ) : (
            <button
              onClick={() => setIsReviewFormOpen(!isReviewFormOpen)}
              className="btn-secondary"
              style={{ padding: '0.6rem 1.2rem', fontSize: '0.85rem' }}
            >
              {isReviewFormOpen ? 'Cancelar' : 'Escribir Reseña'}
            </button>
          )}
        </div>

        {/* User's existing review (even if pending approval) */}
        {(product as any)?.user_review && (
          <div style={{ background: 'rgba(255,255,255,0.015)', padding: '1.25rem', border: '1px dashed var(--border)', borderRadius: 'var(--radius-lg)', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span style={{ fontWeight: 600, fontSize: '0.95rem', color: 'var(--text-primary)' }}>Tu Calificación</span>
              <span className={`badge ${(product as any).user_review.is_approved ? 'badge-success' : 'badge-warning'}`} style={{ fontSize: '0.75rem', padding: '0.2rem 0.5rem', borderRadius: '4px', background: (product as any).user_review.is_approved ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)', color: (product as any).user_review.is_approved ? '#10b981' : '#f59e0b', fontWeight: 600 }}>
                {(product as any).user_review.is_approved ? 'Publicada' : 'Pendiente de aprobación'}
              </span>
            </div>
            <div style={{ display: 'flex', color: '#fbbf24', gap: '0.1rem' }}>
              {Array.from({ length: 5 }).map((_, i) => (
                <Star key={i} size={12} fill={i < (product as any).user_review.rating ? '#fbbf24' : 'none'} />
              ))}
            </div>
            {(product as any).user_review.comment && (
              <p style={{ margin: 0, fontSize: '0.9rem', color: 'var(--text-secondary)', lineHeight: '1.5' }}>{(product as any).user_review.comment}</p>
            )}
          </div>
        )}

        {/* Review Form */}
        {isReviewFormOpen && !(product as any)?.user_review && (
          <form onSubmit={handleReviewSubmit} className="review-form" style={{ background: 'rgba(255,255,255,0.015)', border: '1px solid var(--border)', borderRadius: 'var(--radius-lg)', padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
            <h3 style={{ fontSize: '1.1rem', color: 'var(--text-primary)', marginBottom: '0.25rem', marginTop: 0 }}>Nueva Calificación</h3>
            
            <div style={{ display: 'grid', gridTemplateColumns: isLoggedIn ? '1fr 1fr' : '1fr 1fr 1fr', gap: '1rem' }}>
              <div>
                <label style={{ display: 'block', fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '0.35rem', fontWeight: 500 }}>
                  Nombre <span style={{ color: 'var(--danger)' }}>*</span>
                </label>
                <input
                  type="text"
                  required
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                  placeholder="Tu nombre"
                  style={{ width: '100%', padding: '0.6rem', background: 'rgba(0,0,0,0.2)', border: '1px solid var(--border)', borderRadius: '6px', color: 'white', fontSize: '0.9rem' }}
                />
              </div>

              {!isLoggedIn && (
                <div>
                  <label style={{ display: 'block', fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '0.35rem', fontWeight: 500 }}>
                    Teléfono / WhatsApp
                  </label>
                  <div style={{ display: 'flex', gap: '0.25rem' }}>
                    <select
                      value={countryCode}
                      onChange={(e) => setCountryCode(e.target.value)}
                      style={{ padding: '0.6rem', background: 'rgba(0,0,0,0.2)', border: '1px solid var(--border)', borderRadius: '6px', color: 'white', fontSize: '0.9rem', width: '90px', outline: 'none' }}
                    >
                      <option value="+593">EC +593</option>
                      <option value="+51">PE +51</option>
                      <option value="+57">CO +57</option>
                      <option value="+52">MX +52</option>
                      <option value="+34">ES +34</option>
                      <option value="">Otro</option>
                    </select>
                    <input
                      type="text"
                      value={formPhone}
                      onChange={(e) => setFormPhone(e.target.value.replace(/[^0-9]/g, ''))}
                      placeholder="Ej. 999888777"
                      style={{ flex: 1, padding: '0.6rem', background: 'rgba(0,0,0,0.2)', border: '1px solid var(--border)', borderRadius: '6px', color: 'white', fontSize: '0.9rem' }}
                    />
                  </div>
                  <span style={{ display: 'block', fontSize: '0.72rem', color: 'var(--text-muted)', marginTop: '0.25rem', lineHeight: '1.25' }}>
                    Opcional. Si deseas la insignia dorada de Compra Verificada, ingresa el teléfono con el que realizaste tu pedido (este dato nunca será visible públicamente).
                  </span>
                </div>
              )}

              <div>
                <label style={{ display: 'block', fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '0.35rem', fontWeight: 500 }}>
                  Correo electrónico (no se publicará)
                </label>
                <input
                  type="email"
                  value={formEmail}
                  onChange={(e) => setFormEmail(e.target.value)}
                  placeholder="tu@correo.com"
                  style={{ width: '100%', padding: '0.6rem', background: 'rgba(0,0,0,0.2)', border: '1px solid var(--border)', borderRadius: '6px', color: 'white', fontSize: '0.9rem' }}
                />
              </div>
            </div>

            <div>
              <label style={{ display: 'block', fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '0.35rem', fontWeight: 500 }}>
                Calificación <span style={{ color: 'var(--danger)' }}>*</span>
              </label>
              <div style={{ display: 'flex', gap: '0.25rem' }}>
                {[1, 2, 3, 4, 5].map((star) => (
                  <button
                    key={star}
                    type="button"
                    onClick={() => setFormRating(star)}
                    style={{ background: 'none', border: 'none', cursor: 'pointer', padding: '0.2rem', color: '#fbbf24' }}
                  >
                    <Star
                      size={24}
                      fill={star <= formRating ? '#fbbf24' : 'none'}
                    />
                  </button>
                ))}
              </div>
            </div>

            <div>
              <label style={{ display: 'block', fontSize: '0.8rem', color: 'var(--text-muted)', marginBottom: '0.35rem', fontWeight: 500 }}>
                Comentario
              </label>
              <textarea
                value={formComment}
                onChange={(e) => setFormComment(e.target.value)}
                placeholder="Escribe tu opinión sobre el producto..."
                rows={3}
                style={{ width: '100%', padding: '0.6rem', background: 'rgba(0,0,0,0.2)', border: '1px solid var(--border)', borderRadius: '6px', color: 'white', fontSize: '0.9rem', resize: 'vertical' }}
              />
            </div>

            {/* Cloudflare Turnstile container */}
            <div 
              ref={turnstileContainerRef} 
              className="cf-turnstile-wrapper" 
              style={{ marginTop: '0.5rem', marginBottom: '0.5rem' }}
            />

            <button
              type="submit"
              disabled={reviewMutation.isPending}
              className="btn-primary"
              style={{ padding: '0.6rem 1.5rem', width: 'fit-content', display: 'flex', alignItems: 'center', gap: '0.5rem' }}
            >
              {reviewMutation.isPending && <Loader2 size={16} className="spinner" style={{ animation: 'spin 1s linear infinite' }} />}
              <span>Enviar Reseña</span>
            </button>
          </form>
        )}

        {/* Reviews List */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
          {product.reviews && product.reviews.length > 0 ? (
            product.reviews.map((review) => (
              <div
                key={review.id}
                style={{
                  borderBottom: '1px solid var(--border)',
                  paddingBottom: '1.25rem',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: '0.5rem',
                }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: '0.5rem' }}>
                  <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                      <h4 style={{ margin: 0, fontSize: '1rem', color: 'var(--text-primary)', fontWeight: 600 }}>
                        {review.customer_name}
                      </h4>
                      {review.verified_purchase && (
                        <span style={{ fontSize: '0.68rem', color: '#fbbf24', background: 'rgba(251, 191, 36, 0.08)', border: '1px solid rgba(251, 191, 36, 0.2)', padding: '0.1rem 0.4rem', borderRadius: '4px', fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: '0.15rem' }}>
                          Compra Verificada
                        </span>
                      )}
                    </div>
                    <div style={{ display: 'flex', color: '#fbbf24', marginTop: '0.2rem' }}>
                      {Array.from({ length: 5 }).map((_, i) => (
                        <Star
                          key={i}
                          size={14}
                          fill={i < review.rating ? '#fbbf24' : 'none'}
                        />
                      ))}
                    </div>
                  </div>
                  <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                    {new Date(review.created_at).toLocaleDateString()}
                  </span>
                </div>
                {review.comment && (
                  <p style={{ margin: 0, fontSize: '0.92rem', color: 'var(--text-secondary)', lineHeight: '1.5' }}>
                    {review.comment}
                  </p>
                )}
              </div>
            ))
          ) : (
            <div style={{ textAlign: 'center', padding: '2rem 0', color: 'var(--text-muted)' }}>
              <p style={{ margin: 0, fontSize: '0.95rem' }}>Nadie ha calificado este producto todavía. ¡Sé el primero!</p>
            </div>
          )}
        </div>
      </div>

      {isLightboxOpen && activeImage && (
        <div 
          className="lightbox-overlay" 
          onClick={() => setIsLightboxOpen(false)}
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0, 0, 0, 0.9)',
            zIndex: 1000,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            backdropFilter: 'blur(10px)',
            padding: '2rem',
            cursor: 'zoom-out'
          }}
        >
          <button 
            type="button" 
            className="lightbox-close" 
            onClick={() => setIsLightboxOpen(false)}
            style={{
              position: 'absolute',
              top: '1.5rem',
              right: '1.5rem',
              background: 'rgba(255, 255, 255, 0.08)',
              border: 'none',
              borderRadius: '50%',
              color: 'white',
              width: '40px',
              height: '40px',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              cursor: 'pointer',
              transition: 'var(--transition)'
            }}
          >
            <X size={20} />
          </button>
          
          {allImages.length > 1 && (
            <>
              <button 
                type="button" 
                onClick={handlePrevImage}
                style={{
                  position: 'absolute',
                  left: '1.5rem',
                  background: 'rgba(255, 255, 255, 0.08)',
                  border: 'none',
                  borderRadius: '50%',
                  color: 'white',
                  width: '44px',
                  height: '44px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  cursor: 'pointer',
                  transition: 'var(--transition)'
                }}
              >
                <ChevronLeft size={24} />
              </button>
              <button 
                type="button" 
                onClick={handleNextImage}
                style={{
                  position: 'absolute',
                  right: '1.5rem',
                  background: 'rgba(255, 255, 255, 0.08)',
                  border: 'none',
                  borderRadius: '50%',
                  color: 'white',
                  width: '44px',
                  height: '44px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  cursor: 'pointer',
                  transition: 'var(--transition)'
                }}
              >
                <ChevronRight size={24} />
              </button>
            </>
          )}

          <img 
            src={activeImage} 
            alt={product.name} 
            onClick={(e) => e.stopPropagation()}
            style={{
              maxWidth: '90vw',
              maxHeight: '90vh',
              objectFit: 'contain',
              borderRadius: 'var(--radius-md)',
              boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.5)'
            }} 
          />
        </div>
      )}

      <style>{`
        .product-detail-container {
          max-width: 1100px;
          margin: 0 auto;
          padding: 2rem 1.5rem;
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
          width: 100%;
        }

        .back-navigation {
          display: flex;
        }

        .back-btn {
          padding: 0.6rem 1.2rem;
          font-size: 0.85rem;
          border-radius: var(--radius-md);
        }

        .product-showcase {
          display: grid;
          grid-template-columns: 1.1fr 1.2fr;
          gap: 3rem;
          padding: 3rem;
          border-radius: var(--radius-xl);
        }

        /* Image box */
        .product-image-block {
          width: 100%;
          aspect-ratio: 1/1;
          border-radius: var(--radius-lg);
          border: 1px solid var(--border);
          background: rgba(255, 255, 255, 0.015);
          display: flex;
          align-items: center;
          justify-content: center;
          overflow: hidden;
        }

        .product-main-img {
          width: 100%;
          height: 100%;
          object-fit: cover;
          transition: transform 0.4s ease;
        }

        .product-image-block:hover .product-main-img {
          transform: scale(1.03);
        }

        .product-placeholder-img {
          color: var(--text-muted);
        }

        /* Details */
        .product-details-block {
          display: flex;
          flex-direction: column;
          gap: 1.75rem;
        }

        .brand-category-row {
          display: flex;
          align-items: center;
          gap: 1rem;
        }

        .detail-brand {
          font-size: 0.8rem;
          font-weight: 700;
          color: var(--primary);
          text-transform: uppercase;
          letter-spacing: 0.05em;
        }

        .detail-category {
          font-size: 0.75rem;
          font-weight: 500;
          background: rgba(255, 255, 255, 0.04);
          border: 1px solid var(--border);
          color: var(--text-secondary);
          padding: 0.2rem 0.6rem;
          border-radius: 6px;
        }

        .detail-title {
          font-size: 2rem;
          line-height: 1.2;
          font-family: var(--font-heading);
          background: linear-gradient(135deg, #f8fafc 0%, #cbd5e1 100%);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
        }

        .light-mode .detail-title {
          background: none;
          -webkit-text-fill-color: initial;
          color: var(--text-primary);
        }

        /* Pricing Card */
        .pricing-stock-card {
          background: rgba(255, 255, 255, 0.01);
          border: 1px solid var(--border);
          border-radius: var(--radius-lg);
          padding: 1.5rem;
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 1.5rem;
        }

        .detail-price-box {
          display: flex;
          flex-direction: column;
        }

        .price-label {
          font-size: 0.75rem;
          color: var(--text-muted);
          text-transform: uppercase;
          letter-spacing: 0.02em;
        }

        .detail-price {
          font-size: 2.25rem;
          font-weight: 800;
          color: var(--text-primary);
          line-height: 1.1;
        }

        .stock-indicator {
          display: flex;
          align-items: center;
          gap: 0.75rem;
        }

        .stock-indicator.instock {
          color: var(--success);
        }

        .stock-indicator.outofstock {
          color: var(--danger);
        }

        .stock-state {
          display: block;
          font-weight: 700;
          font-size: 0.95rem;
        }

        .stock-count {
          display: block;
          font-size: 0.75rem;
          color: var(--text-muted);
        }

        /* Buy button */
        .detail-cta-row {
          display: flex;
          gap: 0.75rem;
        }

        .add-cart-btn {
          flex: 1;
          padding: 1rem;
          font-size: 1rem;
          font-weight: 600;
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 0.5rem;
        }
        .add-cart-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .add-cart-btn.added { border-color: #10b981; color: #10b981; background: rgba(16, 185, 129, 0.05); }
        .add-cart-btn.added:hover:not(:disabled) { background: #10b981; color: #fff; }

        .whatsapp-buy-btn {
          flex: 1;
          padding: 1rem;
          font-size: 1.05rem;
          font-weight: 600;
          background: #10b981;
          box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 0.65rem;
        }

        .whatsapp-buy-btn:hover {
          background: #059669;
          box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45);
        }

        /* Description Section */
        .detail-description-section {
          display: flex;
          flex-direction: column;
          gap: 0.5rem;
        }

        .detail-description-section h3 {
          font-size: 1.1rem;
          font-family: var(--font-heading);
          color: var(--text-primary);
        }

        .detail-description-section p {
          font-size: 0.95rem;
          color: var(--text-secondary);
          line-height: 1.6;
        }

        /* Specs Section */
        .detail-specs-section {
          display: flex;
          flex-direction: column;
          gap: 0.75rem;
        }

        .detail-specs-section h3 {
          font-size: 1.1rem;
          font-family: var(--font-heading);
          color: var(--text-primary);
        }

        .specs-table-wrapper {
          border: 1px solid var(--border);
          border-radius: var(--radius-md);
          overflow: hidden;
        }

        .specs-table {
          width: 100%;
          border-collapse: collapse;
          font-size: 0.9rem;
        }

        .specs-table td {
          padding: 0.75rem 1rem;
          border-bottom: 1px solid var(--border);
        }

        .specs-table tr:last-child td {
          border-bottom: none;
        }

        .spec-name {
          color: var(--text-secondary);
          font-weight: 500;
          width: 40%;
          background: rgba(255, 255, 255, 0.01);
        }

        .spec-value {
          color: var(--text-primary);
          width: 60%;
        }

        /* Responsive */
        @media (max-width: 860px) {
          .product-showcase {
            grid-template-columns: 1fr;
            gap: 2rem;
            padding: 1.75rem;
          }
          .detail-title {
            font-size: 1.6rem;
          }
        }
      `}</style>
    </div>
  );
}
