import { useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { 
  ArrowLeft,
  ShoppingBag,
  CheckCircle,
  XCircle,
  Loader2,
  Phone,
  Plus
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { getPublicTenant, getPublicProduct } from '../../api/public';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useCartStore } from '../../stores/cartStore';
import type { Tenant, Product } from '../../types';

export default function ProductDetailPage() {
  const { slug, id } = useParams<{ slug: string; id: string }>();

  // Fetch Tenant Info (to maintain active styling/colors)
  const { data: tenant } = useQuery<Tenant>({
    queryKey: ['publicTenant', slug],
    queryFn: () => getPublicTenant(slug!),
    enabled: !!slug,
  });

  // Apply tenant's primary color dynamically
  useEffect(() => {
    if (tenant?.primary_color) {
      document.documentElement.style.setProperty('--primary', tenant.primary_color);
      const hex = tenant.primary_color.replace('#', '');
      const r = parseInt(hex.substring(0, 2), 16);
      const g = parseInt(hex.substring(2, 4), 16);
      const b = parseInt(hex.substring(4, 6), 16);
      document.documentElement.style.setProperty('--primary-glow', `rgba(${r}, ${g}, ${b}, 0.15)`);
    }
    if (tenant?.theme?.accent_color) {
      document.documentElement.style.setProperty('--accent', tenant.theme.accent_color);
    }

    // Modo claro/oscuro: replicar el comportamiento del catálogo para que el
    // detalle no vuelva a oscuro cuando el tenant está en modo claro.
    const isLight = tenant?.theme?.color_mode === 'light';
    document.body.classList.toggle('light-mode', isLight);
    return () => document.body.classList.remove('light-mode');
  }, [tenant]);

  // Fetch Product Info
  const { data: product, isLoading, isError } = useQuery<Product>({
    queryKey: ['publicProduct', slug, id],
    queryFn: () => getPublicProduct(slug!, id!),
    enabled: !!slug && !!id,
  });

  // Título y favicon de la pestaña: "Producto · Mi Tienda"
  useTenantBranding(tenant, product?.name);

  const addItem = useCartStore((s) => s.addItem);

  const handleAddToCart = () => {
    if (!product || !slug) return;
    addItem(slug, product);
    toast.success(`${product.name} agregado al pedido`);
  };

  // Whatsapp redirect handler
  const handleWhatsappQuery = () => {
    if (!product || !tenant) return;

    const currentUrl = window.location.href;
    const baseMessage = `Hola, estoy interesado en el producto *${product.name}* (Precio: $${parseFloat(product.price.toString()).toFixed(2)}) de tu catálogo virtual. ¿Se encuentra disponible?\n\nEnlace del producto: ${currentUrl}`;
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
        <Link to={`/${slug}`} className="btn-primary" style={{textDecoration: 'none'}}>Volver al Catálogo</Link>
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
    <div className="product-detail-container animate-fade-in">
      {/* Back button */}
      <div className="back-navigation">
        <Link to={`/${slug}`} className="btn-secondary back-btn">
          <ArrowLeft size={16} />
          <span>Volver al catálogo</span>
        </Link>
      </div>

      {/* Main product card */}
      <div className="product-showcase glass-card animate-scale-in">
        
        {/* Product image block */}
        <div className="product-image-block">
          {product.image_url ? (
            <img src={product.image_url} alt={product.name} className="product-main-img" />
          ) : (
            <div className="product-placeholder-img">
              <ShoppingBag size={64} />
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

          {/* Pricing & Stock card */}
          <div className="pricing-stock-card">
            <div className="detail-price-box">
              <span className="price-label">Precio Sugerido</span>
              <span className="detail-price">${parseFloat(product.price.toString()).toFixed(2)}</span>
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
              className="btn-secondary add-cart-btn"
              disabled={product.stock === 0}
            >
              <Plus size={18} />
              <span>{product.stock === 0 ? 'Agotado' : 'Agregar al pedido'}</span>
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
              <p>{product.description}</p>
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
