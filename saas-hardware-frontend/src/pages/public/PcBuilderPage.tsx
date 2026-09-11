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
  HelpCircle,
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
import StoreFooter from '../../components/public/StoreFooter';
import StoreHeader from '../../components/public/StoreHeader';
import { getPublicPages } from '../../api/pages';
import type { Tenant, Product, Category, ComponentType, PaginatedResponse, Page } from '../../types';
import { useBloqueoDeScroll } from '../../hooks/useBloqueoDeScroll';

/**
 * Pasos predefinidos para armar la PC.
 *
 * `key` es el `component_type` de la categoria (FUN-8) y tambien el slug de su
 * icono. Hasta aqui cada paso buscaba su categoria por un trozo del NOMBRE
 * ('procesador', 'placa', 'tarjeta'...): una tienda que dijera "CPU" o
 * "Graficas" se quedaba sin armador y sin ningun aviso, con el paso vacio como
 * si no hubiera stock. Ahora lo dice la categoria y el nombre da igual.
 */
const BUILDER_STEPS: { id: number; key: ComponentType; name: string }[] = [
  { id: 1, key: 'cpu', name: 'Procesador (CPU)' },
  { id: 2, key: 'motherboard', name: 'Placa Madre (Motherboard)' },
  { id: 3, key: 'ram', name: 'Memoria RAM' },
  { id: 4, key: 'gpu', name: 'Tarjeta de Video (GPU)' },
  { id: 5, key: 'ssd', name: 'Almacenamiento (SSD/HDD)' },
  { id: 6, key: 'power', name: 'Fuente de Poder' },
  { id: 7, key: 'cooling', name: 'Enfriamiento / Disipadores' },
  { id: 8, key: 'case', name: 'Gabinete / Chasis' },
];

/**
 * Una incidencia del armado.
 *
 * `unknown` es el estado que faltaba y el motivo de este cambio: hasta aqui, si
 * la spec no estaba escrita con el nombre que el codigo espera —"Zoc." en vez de
 * "Socket"—, el chequeo no encontraba el dato, no decia nada, y el producto
 * salia etiquetado como **Compatible**. Un visto verde por falta de informacion,
 * no por comprobacion. Ahora eso se dice: "no podemos comprobarlo".
 *
 * Es un parche honesto, no el arreglo: el arreglo es pedir las specs con nombre
 * fijo (Fase 13 de `mejoras_propuestas.md`, la mitad abierta de FUN-8).
 */
interface CompatibilityIssue {
  type: 'error' | 'warning' | 'unknown';
  message: string;
}

/** Lo que sale de evaluar un armado: qué falla y cuántas comprobaciones se pudieron hacer. */
interface CompatibilityResult {
  issues: CompatibilityIssue[];
  /** Comprobaciones que se ejecutaron de verdad (con sus dos datos delante). */
  comprobadas: number;
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

  // UI-9: con el cajon de componentes abierto, el armado de detras no se mueve.
  useBloqueoDeScroll(activeStepId !== null);
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

  // Para el pie de tienda: los enlaces a las paginas informativas.
  const { data: publicPages = [] } = useQuery<Page[]>({
    queryKey: ['publicPages', resolvedSlug],
    queryFn: () => getPublicPages(resolvedSlug!),
    enabled: !!resolvedSlug,
  });

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

  const activeStep = BUILDER_STEPS.find(s => s.id === activeStepId);

  /**
   * Si la tienda vende esa pieza. No se busca UNA categoria: la tienda puede
   * tener los procesadores partidos en dos ("Intel" y "AMD") y las dos son el
   * mismo paso, asi que el filtro va por tipo y el servidor las junta.
   */
  const tieneComponente = (type: ComponentType) =>
    categories.some(cat => cat.component_type === type);

  // Fetch Products del tipo de componente del paso activo
  const { data: paginatedProducts, isLoading: isLoadingProducts } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['builderProducts', resolvedSlug, activeStep?.key, searchQuery, productPage],
    queryFn: () => getPublicProducts(resolvedSlug!, {
      component_type: activeStep!.key,
      search: searchQuery || undefined,
      in_stock: true,
      page: productPage,
    }),
    enabled: !!resolvedSlug && !!activeStep,
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
  // Si la pieza no dice su TDP se usa un valor tipico, que es razonable para
  // estimar pero NO es un dato de la tienda. Se marca para poder decirlo en vez
  // de presentar el consumo como si estuviera medido.
  let tdpAsumido = false;
  if (cpu) {
    const val = cpu.specs ? parseTdp(cpu.specs) : null;
    if (val) cpuTdp = val;
    else tdpAsumido = true;
  }
  if (gpu) {
    const val = gpu.specs ? parseTdp(gpu.specs) : null;
    if (val) gpuTdp = val;
    else tdpAsumido = true;
  }
  const estimatedPower = (cpu ? cpuTdp : 0) + (gpu ? gpuTdp : 0) + (Object.keys(selections).length > 0 ? 100 : 0);
  const recommendedWatts = Math.round(estimatedPower * 1.25 + 50);

  /**
   * Evalúa un armado: qué falla, qué no se pudo comprobar y cuántas
   * comprobaciones llegaron a ejecutarse.
   *
   * Las tres reglas necesitan que la spec exista en las DOS piezas. Cuando falta
   * en alguna, antes no pasaba nada —y el silencio se leía como "todo bien"—;
   * ahora sale una incidencia de tipo `unknown` que dice qué falta y en qué
   * producto, para que el comprador sepa que ahí no se comprobó nada.
   */
  const evaluarCompatibilidad = (selectionsMap: Record<string, Product>): CompatibilityResult => {
    const issues: CompatibilityIssue[] = [];
    let comprobadas = 0;

    const cpuSel = selectionsMap['cpu'];
    const gpuSel = selectionsMap['gpu'];
    const mbSel = selectionsMap['motherboard'];
    const ramSel = selectionsMap['ram'];
    const psuSel = selectionsMap['power'];

    const normalizar = (valor: string) => valor.toLowerCase().replace(/\s+/g, '');

    if (cpuSel && mbSel) {
      const cpuSocket = getSocket(cpuSel);
      const mbSocket = getSocket(mbSel);

      if (!cpuSocket || !mbSocket) {
        const faltan = [!cpuSocket ? cpuSel.name : null, !mbSocket ? mbSel.name : null].filter(Boolean);
        issues.push({
          type: 'unknown',
          message: `No podemos comprobar el socket: falta esa especificación en ${faltan.join(' y ')}.`,
        });
      } else {
        comprobadas++;
        if (normalizar(cpuSocket) !== normalizar(mbSocket)) {
          issues.push({
            type: 'error',
            message: `Incompatibilidad de Socket: CPU usa (${cpuSocket}) pero Placa Madre usa (${mbSocket}).`,
          });
        }
      }
    }

    if (ramSel && mbSel) {
      const ramType = getRamType(ramSel);
      const mbRamType = getRamType(mbSel);

      if (!ramType || !mbRamType) {
        const faltan = [!ramType ? ramSel.name : null, !mbRamType ? mbSel.name : null].filter(Boolean);
        issues.push({
          type: 'unknown',
          message: `No podemos comprobar el tipo de memoria: falta esa especificación en ${faltan.join(' y ')}.`,
        });
      } else {
        comprobadas++;
        if (ramType !== mbRamType) {
          issues.push({
            type: 'error',
            message: `Incompatibilidad de RAM: Memoria es ${ramType.toUpperCase()} pero Placa Madre requiere ${mbRamType.toUpperCase()}.`,
          });
        }
      }
    }

    if (psuSel && (cpuSel || gpuSel)) {
      const psuWatts = parseWatts(psuSel);

      if (!psuWatts) {
        issues.push({
          type: 'unknown',
          message: `No podemos comprobar la potencia: ${psuSel.name} no dice cuántos vatios entrega.`,
        });
      } else {
        comprobadas++;

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

        // La comparacion de arriba es contra un consumo estimado; si alguna
        // pieza no dijo su TDP, ese numero lleva dentro un valor supuesto y hay
        // que decirlo, porque de el depende el veredicto de la fuente.
        if (tdpAsumido) {
          issues.push({
            type: 'unknown',
            message: 'El consumo es una estimación: alguna pieza no indica su TDP y se usó un valor típico.',
          });
        }
      }
    }

    return { issues, comprobadas };
  };

  const { issues: compatibilityIssues, comprobadas } = evaluarCompatibilidad(selections);
  const hasErrors = compatibilityIssues.some(i => i.type === 'error');
  const hasWarnings = compatibilityIssues.some(i => i.type === 'warning');
  const hasUnknowns = compatibilityIssues.some(i => i.type === 'unknown');

  /**
   * Lo que aporta ESTE producto al armado, sin heredar lo que ya fallaba.
   *
   * Se compara contra el armado sin la pieza de ese paso: si la CPU y la placa
   * ya se llevaban mal, antes TODOS los candidatos de todos los pasos salian
   * marcados como "Incompatible" —la etiqueta describia el armado entero, no al
   * producto que estabas mirando— y el comprador se quedaba sin saber cual
   * elegir.
   */
  const incidenciasDelCandidato = (stepKey: string, product: Product): CompatibilityIssue[] => {
    const sinEstePaso = { ...selections };
    delete sinEstePaso[stepKey];

    const previas = evaluarCompatibilidad(sinEstePaso).issues.map(i => i.message);
    const conProducto = evaluarCompatibilidad({ ...selections, [stepKey]: product }).issues;

    return conProducto.filter(i => !previas.includes(i.message));
  };

  // Suma total de precios
  const totalPrice = Object.values(selections).reduce((acc, prod) => {
    const price = prod.sale_price !== null ? Number(prod.sale_price) : Number(prod.price);
    return acc + price;
  }, 0);

  const handleSelectProduct = (stepKey: string, product: Product) => {
    const tempSelections = { ...selections, [stepKey]: product };
    // Solo los errores que APORTA esta pieza: preguntar por un conflicto que ya
    // existia entre otras dos seria echarle la culpa a quien no la tiene.
    // Las incidencias de tipo `unknown` no preguntan nada: avisan, no estorban.
    const tempErrors = incidenciasDelCandidato(stepKey, product).filter(i => i.type === 'error');

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

  /**
   * La etiqueta de un producto del cajón.
   *
   * Cuatro estados, no tres. "Sin datos" es el que faltaba: antes, un producto
   * cuya spec no se encontraba salia como **Compatible** —verde por ignorancia—
   * y ese es justo el aviso que no sirve. Solo se dice "Compatible" cuando algo
   * se comprobo de verdad.
   */
  const estadoDelCandidato = (stepKey: string, product: Product): { clase: string; etiqueta: string } => {
    const nuevas = incidenciasDelCandidato(stepKey, product);

    if (nuevas.some(i => i.type === 'error')) return { clase: 'badge-danger', etiqueta: 'Incompatible' };
    if (nuevas.some(i => i.type === 'warning')) return { clase: 'badge-warning', etiqueta: 'Advertencia' };
    if (nuevas.some(i => i.type === 'unknown')) return { clase: 'badge-unknown', etiqueta: 'Sin datos' };

    // Sin incidencias puede significar dos cosas distintas: que se comprobo y
    // salio bien, o que no habia nada que comprobar todavia (esta es la primera
    // pieza del armado). Decir "Compatible" en el segundo caso es prometer una
    // revision que no ha ocurrido.
    const conProducto = evaluarCompatibilidad({ ...selections, [stepKey]: product });

    return conProducto.comprobadas > 0
      ? { clase: 'badge-success', etiqueta: 'Compatible' }
      : { clase: 'badge-unknown', etiqueta: 'Sin comprobar' };
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
      <StoreHeader
        start={(
          <>
            <Link to={getPublicPath('/')} className="back-catalog-link">
              <ArrowLeft size={16} /> Volver al Catálogo
            </Link>
            <div className="header-title">
              <Cpu size={24} className="builder-primary-icon" />
              <h2>Armador de PC compatible</h2>
            </div>
          </>
        )}
      >
        <button onClick={handleWhatsAppOrder} className="btn-primary whatsapp-header-btn" disabled={Object.keys(selections).length === 0}>
          <MessageCircle size={18} /> Pedir Armado
        </button>
      </StoreHeader>

      <div className="builder-layout">
        {/* Left Side: Step Selectors */}
        <div className="builder-steps-column">
          {BUILDER_STEPS.map((step) => {
            const selectedProduct = selections[step.key];
            const hasCategory = tieneComponente(step.key);

            return (
              <div key={step.id} className="builder-step-card glass-card">
                <div className="step-header">
                  <div className="step-num">0{step.id}</div>
                  <div className="step-icon-wrapper">
                    <CategoryIcon slug={step.key} size={20} />
                  </div>
                  <div className="step-title-box">
                    <h4>{step.name}</h4>
                    {!hasCategory && (
                      <span
                        className="no-cat-label"
                        title="Esta tienda todavía no tiene ninguna categoría marcada como este tipo de componente."
                      >
                        No disponible en esta tienda
                      </span>
                    )}
                  </div>
                </div>

                <div className="step-content">
                  {selectedProduct ? (
                    <div className="selected-product-preview animate-fade-in">
                      <div className="prod-img">
                        {selectedProduct.thumbnail_url ? (
                          <img loading="lazy" decoding="async" src={selectedProduct.thumbnail_url} alt={selectedProduct.name} />
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
                {tdpAsumido && (
                  <p className="tdp-nota">
                    Alguna pieza no indica su consumo: se usó un valor típico para estimarlo.
                  </p>
                )}
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
              ) : hasUnknowns ? (
                /* El aviso que faltaba. Antes, no encontrar el dato se pintaba
                   igual que haberlo comprobado: verde y "100% compatible". */
                <div className="status-banner unknown">
                  <HelpCircle size={18} />
                  <div>
                    <strong>No podemos comprobarlo todo</strong>
                    <p>Faltan datos en algunos productos. Abajo dice cuáles.</p>
                  </div>
                </div>
              ) : comprobadas === 0 ? (
                <div className="status-banner empty">
                  <HelpCircle size={16} />
                  <span>Todavía no hay nada que comprobar: elige piezas que se relacionen entre sí.</span>
                </div>
              ) : (
                <div className="status-banner success">
                  <CheckCircle size={18} />
                  <span>
                    {comprobadas === 1
                      ? 'La comprobación que se pudo hacer salió bien.'
                      : `Las ${comprobadas} comprobaciones que se pudieron hacer salieron bien.`}
                  </span>
                </div>
              )}

              {/* List of issues */}
              {compatibilityIssues.length > 0 && (
                <div className="issues-list">
                  {compatibilityIssues.map((issue, idx) => (
                    <div key={idx} className={`issue-item ${issue.type}`}>
                      {issue.type === 'unknown'
                        ? <HelpCircle size={14} style={{ flexShrink: 0, marginTop: '2px' }} />
                        : <AlertTriangle size={14} style={{ flexShrink: 0, marginTop: '2px' }} />}
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
      {activeStepId !== null && activeStep && (
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
                    const { clase: compClass, etiqueta: compLabel } = estadoDelCandidato(activeStep!.key, product);
                    
                    return (
                      <div key={product.id} className="drawer-product-card glass-card">
                        <div className="dp-img">
                          {product.thumbnail_url ? (
                            <img loading="lazy" decoding="async" src={product.thumbnail_url} alt={product.name} />
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

      {/* Mismo pie que el catalogo (UI-3): quien esta armando un equipo es
          quien mas necesita saber donde esta la tienda y como contactarla. */}
      {tenant && <StoreFooter tenant={tenant} pages={publicPages} buildPath={getPublicPath} />}
    </div>
  );
}
