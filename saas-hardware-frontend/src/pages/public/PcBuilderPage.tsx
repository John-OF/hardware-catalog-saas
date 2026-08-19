import './PcBuilderPage.css';

import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  Cpu,
  ShoppingBag,
  ArrowLeft,
  Loader2,
  Trash2,
  AlertTriangle,
  CheckCircle,
  MessageCircle,
  Plus,
  Search,
  ShoppingCart,
  ChevronLeft,
  ChevronRight,
  X
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import api from '../../api/axios';
import { getPublicTenant, getPublicProducts, resolveTenantDomain } from '../../api/public';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';
import { formatMoney } from '../../utils/money';
import { useCartStore } from '../../stores/cartStore';
import CategoryIcon from '../../components/ui/CategoryIcon';
import AnnouncementBar from '../../components/public/AnnouncementBar';
import type { Tenant, Product, Category, PaginatedResponse } from '../../types';

// Pasos predefinidos para armar la PC
const BUILDER_STEPS = [
  { id: 1, key: 'cpu', name: 'Procesador (CPU)', iconSlug: 'cpu', searchName: 'procesador' },
  { id: 2, key: 'motherboard', name: 'Placa Madre (Motherboard)', iconSlug: 'motherboard', searchName: 'placa' },
  { id: 3, key: 'ram', name: 'Memoria RAM', iconSlug: 'ram', searchName: 'memoria' },
  { id: 4, key: 'gpu', name: 'Tarjeta de Video (GPU)', iconSlug: 'gpu', searchName: 'tarjeta' },
  { id: 5, key: 'ssd', name: 'Almacenamiento (SSD/HDD)', iconSlug: 'ssd', searchName: 'almacenamiento' },
  { id: 6, key: 'power', name: 'Fuente de Poder', iconSlug: 'power', searchName: 'fuente' },
  { id: 7, key: 'cooling', name: 'Enfriamiento / Disipadores', iconSlug: 'cooling', searchName: 'enfriamiento' },
  { id: 8, key: 'case', name: 'Gabinete / Chasis', iconSlug: 'case', searchName: 'gabinete' },
];

interface CompatibilityIssue {
  type: 'error' | 'warning';
  message: string;
}

export default function PcBuilderPage() {
  const { slug } = useParams<{ slug: string }>();
  const isCustomDomain = !slug;
  const currentDomain = window.location.hostname;
  const addItem = useCartStore((s) => s.addItem);

  // Selecciones actuales (key de paso -> producto seleccionado)
  const [selections, setSelections] = useState<Record<string, Product>>({});
  
  // Paso actualmente activo para seleccionar (expandido en la lista)
  const [activeStepId, setActiveStepId] = useState<number | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [productPage, setProductPage] = useState(1);

  // Fetch Tenant Info
  const { data: tenant, isLoading: isLoadingTenant } = useQuery<Tenant>({
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

  const getPublicPath = (path: string) => {
    if (isCustomDomain) {
      return path;
    }
    return `/${resolvedSlug}${path}`;
  };

  useTenantBranding(tenant, 'Armador de PC compatible', 'Arma tu computadora ideal paso a paso con compatibilidad de componentes garantizada.');
  useTenantTheme(tenant);

  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency);

  // Fetch Categories to map step keys
  const { data: categories = [] } = useQuery<Category[]>({
    queryKey: ['publicCategories', resolvedSlug],
    queryFn: async () => {
      const res = await api.get<Category[]>(`/public/${resolvedSlug}/categories`);
      return res.data;
    },
    enabled: !!resolvedSlug,
  });

  // Encontrar la categoría real correspondiente al paso activo
  const activeStep = BUILDER_STEPS.find(s => s.id === activeStepId);
  const matchedCategory = activeStep && categories.find(cat => 
    cat.icon === activeStep.iconSlug || 
    cat.name.toLowerCase().includes(activeStep.searchName)
  );

  // Fetch Products de la categoría seleccionada para el paso activo
  const { data: paginatedProducts, isLoading: isLoadingProducts } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['builderProducts', resolvedSlug, matchedCategory?.id, searchQuery, productPage],
    queryFn: () => getPublicProducts(resolvedSlug!, {
      category_id: matchedCategory?.id,
      search: searchQuery || undefined,
      in_stock: true,
      page: productPage,
    }),
    enabled: !!resolvedSlug && !!matchedCategory,
  });

  const availableProducts = paginatedProducts?.data || [];
  const totalProductPages = paginatedProducts?.last_page || 1;

  // Resetear filtros al cambiar de paso
  useEffect(() => {
    setSearchQuery('');
    setProductPage(1);
  }, [activeStepId]);

  // Auxiliares de normalización de specs
  const getSocket = (product: Product): string | null => {
    if (!product.specs) return null;
    const keys = ['socket', 'zócalo', 'zocalo', 'socket cpu', 'socket compatible'];
    for (const key of Object.keys(product.specs)) {
      if (keys.includes(key.toLowerCase())) {
        return product.specs[key]?.toString().trim() || null;
      }
    }
    return null;
  };

  const getRamType = (product: Product): string | null => {
    if (!product.specs) return null;
    const keys = ['tipo de memoria', 'memoria', 'tecnología', 'tecnologia', 'ram compatible', 'tipo ram', 'tipo'];
    for (const key of Object.keys(product.specs)) {
      if (keys.includes(key.toLowerCase())) {
        const val = product.specs[key]?.toString().toLowerCase();
        if (val.includes('ddr5')) return 'ddr5';
        if (val.includes('ddr4')) return 'ddr4';
        if (val.includes('ddr3')) return 'ddr3';
      }
    }
    return null;
  };

  const parseTdp = (specs: Record<string, string | number>): number | null => {
    const keys = ['tdp', 'consumo', 'potencia', 'tdp max', 'tdp (w)'];
    for (const k of Object.keys(specs)) {
      if (keys.some(key => k.toLowerCase().includes(key))) {
        const matches = specs[k].toString().match(/\d+/);
        if (matches) return parseInt(matches[0]);
      }
    }
    return null;
  };

  const parseWatts = (psu: Product): number | null => {
    if (psu.specs) {
      const keys = ['potencia', 'watts', 'capacidad', 'vatios', 'watts reales'];
      for (const k of Object.keys(psu.specs)) {
        if (keys.some(key => k.toLowerCase().includes(key))) {
          const matches = psu.specs[k].toString().match(/\d+/);
          if (matches) return parseInt(matches[0]);
        }
      }
    }
    const nameMatches = psu.name.match(/(\d{3,4})\s*w/i);
    if (nameMatches) return parseInt(nameMatches[1]);
    return null;
  };

  // Cálculo de TDP Estimado
  const cpu = selections['cpu'];
  const gpu = selections['gpu'];

  let cpuTdp = 65;
  let gpuTdp = 120;
  if (cpu?.specs) {
    const val = parseTdp(cpu.specs);
    if (val) cpuTdp = val;
  }
  if (gpu?.specs) {
    const val = parseTdp(gpu.specs);
    if (val) gpuTdp = val;
  }
  const estimatedPower = (cpu ? cpuTdp : 0) + (gpu ? gpuTdp : 0) + (Object.keys(selections).length > 0 ? 100 : 0);
  const recommendedWatts = Math.round(estimatedPower * 1.25 + 50);

  // Chequeo dinámico de compatibilidad
  const checkSelectionCompatibility = (selectionsMap: Record<string, Product>): CompatibilityIssue[] => {
    const issues: CompatibilityIssue[] = [];
    const cpuSel = selectionsMap['cpu'];
    const gpuSel = selectionsMap['gpu'];
    const mbSel = selectionsMap['motherboard'];
    const ramSel = selectionsMap['ram'];
    const psuSel = selectionsMap['power'];

    if (cpuSel && mbSel) {
      const cpuSocket = getSocket(cpuSel);
      const mbSocket = getSocket(mbSel);
      if (cpuSocket && mbSocket && cpuSocket.toLowerCase().replace(/\s+/g, '') !== mbSocket.toLowerCase().replace(/\s+/g, '')) {
        issues.push({
          type: 'error',
          message: `Incompatibilidad de Socket: CPU usa (${cpuSocket}) pero Placa Madre usa (${mbSocket}).`,
        });
      }
    }

    if (ramSel && mbSel) {
      const ramType = getRamType(ramSel);
      const mbRamType = getRamType(mbSel);
      if (ramType && mbRamType && ramType !== mbRamType) {
        issues.push({
          type: 'error',
          message: `Incompatibilidad de RAM: Memoria es ${ramType.toUpperCase()} pero Placa Madre requiere ${mbRamType.toUpperCase()}.`,
        });
      }
    }

    if (psuSel && (cpuSel || gpuSel)) {
      const psuWatts = parseWatts(psuSel);
      if (psuWatts) {
        if (psuWatts < estimatedPower) {
          issues.push({
            type: 'error',
            message: `Insuficiencia de Poder: La fuente de ${psuWatts}W no cubre el consumo mínimo estimado (${estimatedPower}W).`,
          });
        } else if (psuWatts < recommendedWatts) {
          issues.push({
            type: 'warning',
            message: `Fuente al Límite: Se recomienda una fuente de al menos ${recommendedWatts}W (seleccionada: ${psuWatts}W).`,
          });
        }
      }
    }

    return issues;
  };

  const compatibilityIssues = checkSelectionCompatibility(selections);
  const hasErrors = compatibilityIssues.some(i => i.type === 'error');
  const hasWarnings = compatibilityIssues.some(i => i.type === 'warning');

  // Suma total de precios
  const totalPrice = Object.values(selections).reduce((acc, prod) => {
    const price = prod.sale_price !== null ? Number(prod.sale_price) : Number(prod.price);
    return acc + price;
  }, 0);

  const handleSelectProduct = (stepKey: string, product: Product) => {
    // Probar si esta selección generará un error crítico de compatibilidad
    const tempSelections = { ...selections, [stepKey]: product };
    const tempIssues = checkSelectionCompatibility(tempSelections);
    const tempErrors = tempIssues.filter(i => i.type === 'error');

    if (tempErrors.length > 0) {
      if (!window.confirm(`⚠️ Advertencia de Compatibilidad:\n\n${tempErrors.map(e => e.message).join('\n')}\n\n¿Deseas agregar este componente de todas formas?`)) {
        return;
      }
    }

    setSelections(tempSelections);
    setActiveStepId(null);
    toast.success(`${product.name} agregado al armado`);
  };

  const handleRemoveSelection = (stepKey: string) => {
    const temp = { ...selections };
    delete temp[stepKey];
    setSelections(temp);
  };

  const handleAddAllToCart = () => {
    const items = Object.values(selections);
    if (items.length === 0) {
      toast.error('No has seleccionado ningún componente todavía');
      return;
    }
    if (hasErrors) {
      if (!window.confirm('⚠️ Tu armado tiene conflictos críticos de compatibilidad. ¿Seguro que deseas agregarlos todos al carrito?')) {
        return;
      }
    }

    items.forEach((prod) => {
      addItem(slug!, prod, 1);
    });

    toast.success('Todos los componentes fueron agregados a tu pedido');
  };

  const handleWhatsAppOrder = () => {
    const items = Object.entries(selections);
    if (items.length === 0) {
      toast.error('No has seleccionado ningún componente todavía');
      return;
    }

    let message = `*Hola! He armado una PC compatible desde tu catálogo virtual:*\n\n`;
    items.forEach(([key, prod]) => {
      const step = BUILDER_STEPS.find(s => s.key === key);
      const price = prod.sale_price !== null ? prod.sale_price : prod.price;
      message += `• *${step?.name}:* ${prod.name} (${money(price)})\n`;
    });

    message += `\n*Total Estimado:* ${money(totalPrice)}\n`;
    message += `*Consumo del Sistema:* ${estimatedPower}W (Recomendado: ${recommendedWatts}W)\n`;
    message += `\nPor favor, confírmenme stock y disponibilidad. Gracias!`;

    const url = `https://wa.me/${tenant?.whatsapp_number?.replace('+', '') ?? ''}?text=${encodeURIComponent(message)}`;
    window.open(url, '_blank');
  };

  // Ayudante de compatibilidad en la lista para un producto de opción
  const getProductCompatibilityClass = (stepKey: string, product: Product) => {
    const tempSelections = { ...selections, [stepKey]: product };
    const tempIssues = checkSelectionCompatibility(tempSelections);
    if (tempIssues.some(i => i.type === 'error')) return 'badge-danger';
    if (tempIssues.some(i => i.type === 'warning')) return 'badge-warning';
    return 'badge-success';
  };

  const getProductCompatibilityLabel = (stepKey: string, product: Product) => {
    const tempSelections = { ...selections, [stepKey]: product };
    const tempIssues = checkSelectionCompatibility(tempSelections);
    const errors = tempIssues.filter(i => i.type === 'error');
    const warnings = tempIssues.filter(i => i.type === 'warning');

    if (errors.length > 0) return 'Incompatible';
    if (warnings.length > 0) return 'Advertencia';
    return 'Compatible';
  };

  if (isLoadingTenant) {
    return (
      <div className="loader-container page-pc-builder">
        <Loader2 className="spinner" size={40} />
        <p>Cargando armador...</p>
      </div>
    );
  }

  return (
    <div className="pc-builder-page animate-fade-in page-pc-builder">
      <AnnouncementBar theme={tenant?.theme} />

      {/* Top Header */}
      <header className="catalog-header glass-card">
        <Link to={getPublicPath('/')} className="back-catalog-link">
          <ArrowLeft size={16} /> Volver al Catálogo
        </Link>
        <div className="header-title">
          <Cpu size={24} className="builder-primary-icon" />
          <h2>Armador de PC compatible</h2>
        </div>
        <div className="header-contact">
          <button onClick={handleWhatsAppOrder} className="btn-primary whatsapp-header-btn" disabled={Object.keys(selections).length === 0}>
            <MessageCircle size={18} /> Pedir Armado
          </button>
        </div>
      </header>

      <div className="builder-layout">
        {/* Left Side: Step Selectors */}
        <div className="builder-steps-column">
          {BUILDER_STEPS.map((step) => {
            const selectedProduct = selections[step.key];
            const hasCategory = categories.some(cat => 
              cat.icon === step.iconSlug || 
              cat.name.toLowerCase().includes(step.searchName)
            );

            return (
              <div key={step.id} className="builder-step-card glass-card">
                <div className="step-header">
                  <div className="step-num">0{step.id}</div>
                  <div className="step-icon-wrapper">
                    <CategoryIcon slug={step.iconSlug} size={20} />
                  </div>
                  <div className="step-title-box">
                    <h4>{step.name}</h4>
                    {!hasCategory && <span className="no-cat-label">Sin Stock/Categoría</span>}
                  </div>
                </div>

                <div className="step-content">
                  {selectedProduct ? (
                    <div className="selected-product-preview animate-fade-in">
                      <div className="prod-img">
                        {selectedProduct.thumbnail_url ? (
                          <img src={selectedProduct.thumbnail_url} alt={selectedProduct.name} />
                        ) : (
                          <ShoppingBag size={24} />
                        )}
                      </div>
                      <div className="prod-info">
                        <h5>{selectedProduct.name}</h5>
                        <span className="prod-price">
                          {money(selectedProduct.sale_price !== null ? selectedProduct.sale_price : selectedProduct.price)}
                        </span>
                        {selectedProduct.specs && (
                          <div className="prod-mini-specs">
                            {Object.entries(selectedProduct.specs).slice(0, 2).map(([k, v]) => (
                              <span key={k} className="mini-spec-badge">{k}: {v}</span>
                            ))}
                          </div>
                        )}
                      </div>
                      <div className="prod-actions">
                        <button onClick={() => { setActiveStepId(step.id); }} className="btn-change">
                          Cambiar
                        </button>
                        <button onClick={() => handleRemoveSelection(step.key)} className="btn-remove" title="Quitar">
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </div>
                  ) : (
                    <button
                      onClick={() => { if (hasCategory) setActiveStepId(step.id); }}
                      className="btn-select-component"
                      disabled={!hasCategory}
                    >
                      <Plus size={16} />
                      <span>{hasCategory ? `Seleccionar ${step.name.split(' (')[0]}` : 'Componente no disponible'}</span>
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {/* Right Side: Sticky Summary & Validation Panel */}
        <div className="builder-summary-column">
          <div className="builder-summary-card glass-card">
            <h3>Resumen de Armado</h3>

            {/* Price list */}
            <div className="summary-price-breakdown">
              {BUILDER_STEPS.map((step) => {
                const prod = selections[step.key];
                if (!prod) return null;
                const price = prod.sale_price !== null ? prod.sale_price : prod.price;
                return (
                  <div key={step.id} className="breakdown-row animate-fade-in">
                    <span className="breakdown-label">{step.name.split(' (')[0]}</span>
                    <span className="breakdown-price">{money(price)}</span>
                  </div>
                );
              })}
            </div>

            <div className="summary-total-row">
              <span>Total Estimado:</span>
              <span className="total-price">{money(totalPrice)}</span>
            </div>

            {/* Estimación TDP */}
            {Object.keys(selections).length > 0 && (
              <div className="tdp-calculator-row">
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.35rem' }}>
                  <span>Consumo estimado:</span>
                  <span style={{ fontWeight: 600 }}>{estimatedPower} W</span>
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
                  <span>Fuente recomendada:</span>
                  <span>{recommendedWatts} W</span>
                </div>
              </div>
            )}

            {/* Banner Validación de Compatibilidad */}
            <div className="compatibility-status-section">
              {Object.keys(selections).length === 0 ? (
                <div className="status-banner empty">
                  <Cpu size={16} />
                  <span>Agrega componentes para verificar compatibilidad.</span>
                </div>
              ) : hasErrors ? (
                <div className="status-banner error animate-shake">
                  <AlertTriangle size={18} />
                  <div>
                    <strong>Errores de compatibilidad</strong>
                    <p>Revisa los conflictos abajo antes de comprar.</p>
                  </div>
                </div>
              ) : hasWarnings ? (
                <div className="status-banner warning">
                  <AlertTriangle size={18} />
                  <div>
                    <strong>Sugerencias detectadas</strong>
                    <p>Tu armado funcionará, pero hay sugerencias de potencia.</p>
                  </div>
                </div>
              ) : (
                <div className="status-banner success">
                  <CheckCircle size={18} />
                  <span>¡Tu armado es 100% compatible!</span>
                </div>
              )}

              {/* List of issues */}
              {compatibilityIssues.length > 0 && (
                <div className="issues-list">
                  {compatibilityIssues.map((issue, idx) => (
                    <div key={idx} className={`issue-item ${issue.type}`}>
                      <AlertTriangle size={14} style={{ flexShrink: 0, marginTop: '2px' }} />
                      <span>{issue.message}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="summary-actions">
              <button
                onClick={handleAddAllToCart}
                className="btn-primary-glow btn-add-cart"
                disabled={Object.keys(selections).length === 0}
              >
                <ShoppingCart size={18} /> Agregar todo al pedido
              </button>
              <button
                onClick={handleWhatsAppOrder}
                className="btn-secondary btn-order-whatsapp"
                disabled={Object.keys(selections).length === 0}
              >
                <MessageCircle size={18} /> Consultar por WhatsApp
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Component Selection Modal Overlay */}
      {activeStepId !== null && matchedCategory && (
        <div className="modal-overlay" onClick={() => setActiveStepId(null)}>
          <div className="modal-drawer glass-card animate-slide-up" onClick={(e) => e.stopPropagation()}>
            <div className="drawer-header">
              <div>
                <span className="drawer-category-label">Seleccionar para Armado</span>
                <h3>{activeStep?.name}</h3>
              </div>
              <button onClick={() => setActiveStepId(null)} className="drawer-close">
                <X size={20} />
              </button>
            </div>

            <div className="drawer-search-row">
              <div className="search-box">
                <Search size={16} className="search-icon" />
                <input
                  type="text"
                  placeholder={`Buscar ${activeStep?.name.split(' (')[0].toLowerCase()}...`}
                  value={searchQuery}
                  onChange={(e) => { setSearchQuery(e.target.value); setProductPage(1); }}
                  className="premium-input"
                  autoFocus
                />
              </div>
            </div>

            <div className="drawer-products-list">
              {isLoadingProducts ? (
                <div className="inner-loader">
                  <Loader2 className="spinner" size={28} />
                  <p>Buscando stock...</p>
                </div>
              ) : availableProducts.length === 0 ? (
                <div className="empty-drawer-products">
                  <ShoppingBag size={32} />
                  <p>No hay componentes disponibles o en stock.</p>
                </div>
              ) : (
                <div className="drawer-products-grid">
                  {availableProducts.map((product) => {
                    const price = product.sale_price !== null ? product.sale_price : product.price;
                    const compClass = getProductCompatibilityClass(activeStep!.key, product);
                    const compLabel = getProductCompatibilityLabel(activeStep!.key, product);
                    
                    return (
                      <div key={product.id} className="drawer-product-card glass-card">
                        <div className="dp-img">
                          {product.thumbnail_url ? (
                            <img src={product.thumbnail_url} alt={product.name} />
                          ) : (
                            <ShoppingBag size={20} />
                          )}
                        </div>
                        <div className="dp-info">
                          <span className="dp-brand">{product.brand || 'Genérico'}</span>
                          <h5>{product.name}</h5>
                          
                          {/* Indicator badge compatibility */}
                          <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center', marginTop: '0.35rem' }}>
                            <span className={`badge ${compClass}`} style={{ fontSize: '0.65rem', padding: '0.1rem 0.4rem' }}>
                              {compLabel}
                            </span>
                            {product.specs && getSocket(product) && (
                              <span className="mini-spec-badge" style={{ fontSize: '0.65rem' }}>
                                Socket: {getSocket(product)}
                              </span>
                            )}
                          </div>
                        </div>
                        <div className="dp-action-area">
                          <span className="dp-price">{money(price)}</span>
                          <button
                            onClick={() => handleSelectProduct(activeStep!.key, product)}
                            className="btn-select-add"
                          >
                            Seleccionar
                          </button>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Modal Pagination */}
            {totalProductPages > 1 && (
              <div className="pagination-bar" style={{ marginTop: 'auto', paddingTop: '1.5rem' }}>
                <button 
                  onClick={() => setProductPage(productPage - 1)} 
                  disabled={productPage === 1}
                  className="btn-secondary pag-btn"
                >
                  <ChevronLeft size={16} />
                </button>
                <span className="pag-indicator">{productPage} de {totalProductPages}</span>
                <button 
                  onClick={() => setProductPage(productPage + 1)} 
                  disabled={productPage === totalProductPages}
                  className="btn-secondary pag-btn"
                >
                  <ChevronRight size={16} />
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Embedded Styles */}
    </div>
  );
}
