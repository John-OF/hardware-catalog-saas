import './PcBuilderPage.css';

import { useState, useEffect, useMemo } from 'react';
import { useParams, useSearchParams, Link } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
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
  Share2,
  X
} from 'lucide-react';
import Imagen from '../../components/ui/Imagen';
import { toast } from 'react-hot-toast';
import api from '../../api/axios';
import { getCompatibilidad, getPublicTenant, getPublicProducts, resolveTenantDomain } from '../../api/public';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';
import { formatMoney } from '../../utils/money';
import { useCartStore } from '../../stores/cartStore';
import CategoryIcon from '../../components/ui/CategoryIcon';
import AnnouncementBar from '../../components/public/AnnouncementBar';
import StoreFooter from '../../components/public/StoreFooter';
import StoreHeader from '../../components/public/StoreHeader';
import { getPublicPages } from '../../api/pages';
import type { Tenant, Product, Category, ComponentType, PaginatedResponse, Page, ProductVariant } from '../../types';
import { datosDeVenta, nombreConVariante, tieneVariantes } from '../../utils/variants';
import { useBloqueoDeScroll } from '../../hooks/useBloqueoDeScroll';
import { useSpecsFijas } from '../../hooks/useSpecsFijas';
import { specsParaMostrar } from '../../utils/specsFijas';
import { armadoDesdeElEnlace, escribirArmadoEnElEnlace, leerArmadoDelEnlace } from '../../utils/armadoCompartido';

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

const PASOS = BUILDER_STEPS.map((s) => s.key);

export default function PcBuilderPage() {
  const { slug } = useParams<{ slug: string }>();
  const isCustomDomain = !slug;
  const currentDomain = window.location.hostname;
  const addItem = useCartStore((s) => s.addItem);

  // Selecciones actuales (key de paso -> producto seleccionado)
  const [selections, setSelections] = useState<Record<string, Product>>({});

  // MOD-5: la variante elegida de cada paso (null si el producto no tiene). Va
  // aparte de `selections` a propósito: la compatibilidad se evalúa con las specs
  // de la ficha, que son las mismas para todas sus variantes.
  const [variantesElegidas, setVariantesElegidas] = useState<Record<string, ProductVariant | null>>({});

  // MOD-20: el armado vive también en la URL, para poder compartirlo. Lo que
  // trae el enlace se lee una sola vez al entrar; las piezas se piden después
  // al catálogo por id, con su precio y su stock de hoy.
  const [searchParams, setSearchParams] = useSearchParams();
  const [delEnlace] = useState(() => leerArmadoDelEnlace(searchParams, PASOS));
  const idsDelEnlace = Object.values(delEnlace).map((pieza) => pieza.id);
  const [cargandoEnlace, setCargandoEnlace] = useState(idsDelEnlace.length > 0);
  /** Piezas del enlace que ya no están en el catálogo: se dicen, no se callan. */
  const [faltanDelEnlace, setFaltanDelEnlace] = useState(0);

  // Y la que está marcada en el desplegable de cada producto del cajón, antes de
  // pulsar "Seleccionar".
  const [varianteEnCajon, setVarianteEnCajon] = useState<Record<string, string>>({});

  /** La variante de un producto del cajón: la marcada, o la primera con stock. */
  const varianteDelCajon = (product: Product): ProductVariant | null => {
    if (!tieneVariantes(product)) return null;
    const variantes = product.variants!;
    return variantes.find((v) => v.id === varianteEnCajon[product.id])
      ?? variantes.find((v) => v.stock > 0)
      ?? variantes[0];
  };
  
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

  // FUN-8: con qué etiqueta y unidad se pintan las specs de nombre fijo.
  const { data: definicionDeSpecs } = useSpecsFijas();

  // MOD-20: las piezas del enlace, con la misma forma que las del cajón.
  const { data: piezasDelEnlace, isError: errorDelEnlace } = useQuery({
    queryKey: ['armado-compartido', resolvedSlug, idsDelEnlace],
    queryFn: () => getPublicProducts(resolvedSlug!, { ids: idsDelEnlace }),
    enabled: !!resolvedSlug && cargandoEnlace,
  });

  // Al llegar, se eligen. Durante el render y no en un efecto: es el patrón de
  // React para "estado que se ajusta cuando llega otro", y no pinta un
  // fotograma con el armado vacío.
  if (cargandoEnlace && (piezasDelEnlace || errorDelEnlace)) {
    const { selecciones, variantes, faltan } = armadoDesdeElEnlace(delEnlace, piezasDelEnlace?.data ?? []);
    setCargandoEnlace(false);
    setSelections(selecciones);
    setVariantesElegidas(variantes);
    setFaltanDelEnlace(faltan);
  }

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

  // FUN-8, 13.5: las reglas de compatibilidad viven en el servidor
  // (`App\Support\Compatibilidad`). Aquí antes había una copia que leía las
  // specs por aproximación ('socket', 'zócalo', 'tdp'...) y que el servidor
  // nunca comprobaba; ahora el navegador pregunta y pinta.
  const seleccionIds = useMemo(
    () => Object.fromEntries(Object.entries(selections).map(([paso, producto]) => [paso, producto.id])),
    [selections],
  );
  const haySeleccion = Object.keys(seleccionIds).length > 0;

  const {
    data: veredicto,
    isError: errorAlComprobar,
    isPlaceholderData: veredictoViejo,
  } = useQuery({
    queryKey: ['compatibilidad', resolvedSlug, seleccionIds],
    queryFn: () => getCompatibilidad(resolvedSlug!, { seleccion: seleccionIds }),
    enabled: !!resolvedSlug && haySeleccion,
    // Mientras llega el veredicto del armado nuevo se queda el anterior: sin
    // esto, el resumen parpadearía en cada pieza elegida.
    placeholderData: keepPreviousData,
  });

  // Sin piezas no hay nada que enseñar, aunque quede un veredicto en caché.
  const compatibilityIssues = haySeleccion ? veredicto?.armado.incidencias ?? [] : [];
  const comprobadas = haySeleccion ? veredicto?.armado.comprobadas ?? 0 : 0;
  const consumo = haySeleccion ? veredicto?.consumo ?? null : null;
  const noDisponibles = haySeleccion ? veredicto?.no_disponibles ?? [] : [];
  const hasErrors = compatibilityIssues.some(i => i.tipo === 'error');
  const hasWarnings = compatibilityIssues.some(i => i.tipo === 'warning');
  const hasUnknowns = compatibilityIssues.some(i => i.tipo === 'unknown');

  /**
   * Si el veredicto que hay es el del armado que se ve. Justo después de elegir
   * una pieza queda un momento el anterior: pedir con él sería comprobar otro
   * armado. Si la comprobación falló del todo, no se bloquea nada: el armador
   * avisa, no prohíbe.
   */
  const veredictoAlDia = !haySeleccion || errorAlComprobar || (!!veredicto && !veredictoViejo);

  // El cajón abierto: la etiqueta de cada candidato de la página que se ve. Sin
  // memo: la clave de la consulta se compara por valor, no por referencia.
  const candidatoIds = availableProducts.map(p => p.id);
  const { data: deLosCandidatos } = useQuery({
    queryKey: ['compatibilidad-candidatos', resolvedSlug, seleccionIds, activeStep?.key, candidatoIds],
    queryFn: () => getCompatibilidad(resolvedSlug!, {
      seleccion: seleccionIds,
      paso: activeStep!.key,
      candidatos: candidatoIds,
    }),
    enabled: !!resolvedSlug && !!activeStep && candidatoIds.length > 0,
  });

  // Suma total de precios
  const totalPrice = Object.entries(selections).reduce(
    (acc, [key, prod]) => acc + datosDeVenta(prod, variantesElegidas[key]).precio,
    0,
  );

  const handleSelectProduct = (stepKey: string, product: Product, variante: ProductVariant | null = null) => {
    // Solo los errores que APORTA esta pieza: preguntar por un conflicto que ya
    // existia entre otras dos seria echarle la culpa a quien no la tiene. Eso
    // lo decide el servidor al calcular la etiqueta del candidato.
    const errores = (deLosCandidatos?.candidatos[product.id]?.incidencias ?? []).filter(i => i.tipo === 'error');

    if (errores.length > 0) {
      if (!window.confirm(`⚠️ Advertencia de Compatibilidad:\n\n${errores.map(e => e.mensaje).join('\n')}\n\n¿Deseas agregar este componente de todas formas?`)) {
        return;
      }
    }

    const selecciones = { ...selections, [stepKey]: product };
    const variantes = { ...variantesElegidas, [stepKey]: variante };
    setSelections(selecciones);
    setVariantesElegidas(variantes);
    guardarEnElEnlace(selecciones, variantes);
    setActiveStepId(null);
    toast.success(`${nombreConVariante(product.name, variante?.nombre)} agregado al armado`);
  };

  const handleRemoveSelection = (stepKey: string) => {
    const temp = { ...selections };
    delete temp[stepKey];
    setSelections(temp);
    const variantes = { ...variantesElegidas };
    delete variantes[stepKey];
    setVariantesElegidas(variantes);
    guardarEnElEnlace(temp, variantes);
  };

  /**
   * MOD-20: la URL sigue al armado, sin crear una entrada de historial por
   * cada pieza (el botón "atrás" saca del armador, no deshace una elección).
   * Lo que faltaba del enlace deja de importar en cuanto se toca el armado.
   */
  const guardarEnElEnlace = (selecciones: Record<string, Product>, variantes: Record<string, ProductVariant | null>) => {
    setSearchParams((actuales) => escribirArmadoEnElEnlace(actuales, PASOS, selecciones, variantes), { replace: true });
    setFaltanDelEnlace(0);
  };

  const copiarEnlace = async () => {
    try {
      await navigator.clipboard.writeText(window.location.href);
      toast.success('Enlace del armado copiado');
    } catch {
      toast.error('No se pudo copiar: copia la dirección de la barra del navegador.');
    }
  };

  /**
   * La última palabra antes de pedir, con el veredicto "al pedir": ahí, un
   * procesador sin gráficos y sin tarjeta ya no es un aviso sino un error. Es
   * síncrona a propósito —el veredicto ya llegó con el resumen— para que abrir
   * WhatsApp siga siendo la respuesta directa al clic.
   */
  const confirmarAlPedir = (): boolean => {
    if (errorAlComprobar || !veredicto) {
      return window.confirm('No pudimos comprobar la compatibilidad del armado. ¿Seguir de todas formas?');
    }

    const errores = veredicto.al_pedir.incidencias.filter(i => i.tipo === 'error');
    if (errores.length === 0) return true;

    return window.confirm(`⚠️ Tu armado tiene problemas de compatibilidad:\n\n${errores.map(e => e.mensaje).join('\n')}\n\n¿Seguir de todas formas?`);
  };

  const handleAddAllToCart = () => {
    const items = Object.entries(selections);
    if (items.length === 0) {
      toast.error('No has seleccionado ningún componente todavía');
      return;
    }
    if (!confirmarAlPedir()) return;

    items.forEach(([key, prod]) => {
      addItem(slug!, prod, 1, variantesElegidas[key]);
    });

    toast.success('Todos los componentes fueron agregados a tu pedido');
  };

  const handleWhatsAppOrder = () => {
    const items = Object.entries(selections);
    if (items.length === 0) {
      toast.error('No has seleccionado ningún componente todavía');
      return;
    }
    if (!confirmarAlPedir()) return;

    let message = `*Hola! He armado una PC desde tu catálogo virtual:*\n\n`;
    items.forEach(([key, prod]) => {
      const step = BUILDER_STEPS.find(s => s.key === key);
      const variante = variantesElegidas[key];
      message += `• *${step?.name}:* ${nombreConVariante(prod.name, variante?.nombre)} (${money(datosDeVenta(prod, variante).precio)})\n`;
    });

    message += `\n*Total Estimado:* ${money(totalPrice)}\n`;
    if (consumo) {
      message += `*Consumo del Sistema:* ${consumo.estimado}W (Recomendado: ${consumo.recomendado}W)\n`;
    }
    // MOD-20: con el enlace, la tienda abre exactamente este armado.
    message += `*Ver el armado:* ${window.location.href}\n`;
    message += `\nPor favor, confírmenme stock y disponibilidad. Gracias!`;

    const url = `https://wa.me/${tenant?.whatsapp_number?.replace('+', '') ?? ''}?text=${encodeURIComponent(message)}`;
    window.open(url, '_blank');
  };

  /**
   * La etiqueta de un producto del cajón, como la decide el servidor. Cinco
   * estados y no tres: "Sin datos" (falta un dato para comprobarlo) y "Sin
   * comprobar" (no hay con qué compararlo todavía) no son "Compatible".
   */
  const estadoDelCandidato = (product: Product): { clase: string; etiqueta: string } => {
    switch (deLosCandidatos?.candidatos[product.id]?.etiqueta) {
      case 'incompatible': return { clase: 'badge-danger', etiqueta: 'Incompatible' };
      case 'advertencia': return { clase: 'badge-warning', etiqueta: 'Advertencia' };
      case 'sin_datos': return { clase: 'badge-unknown', etiqueta: 'Sin datos' };
      case 'compatible': return { clase: 'badge-success', etiqueta: 'Compatible' };
      case 'sin_comprobar': return { clase: 'badge-unknown', etiqueta: 'Sin comprobar' };
      default: return { clase: 'badge-unknown', etiqueta: 'Comprobando…' };
    }
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
        <button onClick={handleWhatsAppOrder} className="btn-primary whatsapp-header-btn" disabled={!haySeleccion || !veredictoAlDia}>
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
                        <Imagen
                          loading="lazy" decoding="async" src={selectedProduct.thumbnail_url} alt={selectedProduct.name}
                          respaldo={<ShoppingBag size={24} />}
                        />
                      </div>
                      <div className="prod-info">
                        <h5>{selectedProduct.name}</h5>
                        {variantesElegidas[step.key] && (
                          <span className="prod-variant">{variantesElegidas[step.key]!.nombre}</span>
                        )}
                        <span className="prod-price">
                          {money(datosDeVenta(selectedProduct, variantesElegidas[step.key]).precio)}
                        </span>
                        {selectedProduct.specs && (
                          <div className="prod-mini-specs">
                            {specsParaMostrar(selectedProduct.specs, definicionDeSpecs).slice(0, 2).map((spec) => (
                              <span key={spec.clave} className="mini-spec-badge">{spec.etiqueta}: {spec.valor}</span>
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
                const price = datosDeVenta(prod, variantesElegidas[step.key]).precio;
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
            {consumo && (
              <div className="tdp-calculator-row">
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.35rem' }}>
                  <span>Consumo estimado:</span>
                  <span style={{ fontWeight: 600 }}>{consumo.estimado} W</span>
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
                  <span>Fuente recomendada:</span>
                  <span>{consumo.recomendado} W</span>
                </div>
                {consumo.asumido && (
                  <p className="tdp-nota">
                    Alguna pieza no indica su consumo: se usó un valor típico para estimarlo.
                  </p>
                )}
              </div>
            )}

            {/* Banner Validación de Compatibilidad */}
            <div className="compatibility-status-section">
              {cargandoEnlace ? (
                <div className="status-banner empty">
                  <Loader2 className="spinner" size={16} />
                  <span>Cargando el armado del enlace…</span>
                </div>
              ) : Object.keys(selections).length === 0 ? (
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
                    <p>Tu armado funcionará, pero hay cosas que conviene revisar abajo.</p>
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
              {(compatibilityIssues.length > 0 || noDisponibles.length > 0 || faltanDelEnlace > 0) && (
                <div className="issues-list">
                  {compatibilityIssues.map((issue, idx) => (
                    <div key={`${issue.regla}-${idx}`} className={`issue-item ${issue.tipo}`}>
                      {issue.tipo === 'unknown'
                        ? <HelpCircle size={14} style={{ flexShrink: 0, marginTop: '2px' }} />
                        : <AlertTriangle size={14} style={{ flexShrink: 0, marginTop: '2px' }} />}
                      <span>{issue.mensaje}</span>
                    </div>
                  ))}
                  {faltanDelEnlace > 0 && (
                    <div className="issue-item unknown">
                      <HelpCircle size={14} style={{ flexShrink: 0, marginTop: '2px' }} />
                      <span>
                        {faltanDelEnlace === 1
                          ? 'Una pieza del enlace ya no está en el catálogo: elige otra en su paso.'
                          : `${faltanDelEnlace} piezas del enlace ya no están en el catálogo: elige otras en sus pasos.`}
                      </span>
                    </div>
                  )}
                  {noDisponibles.length > 0 && (
                    <div className="issue-item unknown">
                      <HelpCircle size={14} style={{ flexShrink: 0, marginTop: '2px' }} />
                      <span>
                        {noDisponibles.length === 1
                          ? 'Una pieza elegida ya no está en el catálogo y no se comprobó: quítala y elige otra.'
                          : `${noDisponibles.length} piezas elegidas ya no están en el catálogo y no se comprobaron: quítalas y elige otras.`}
                      </span>
                    </div>
                  )}
                </div>
              )}
            </div>

            <div className="summary-actions">
              <button
                onClick={handleAddAllToCart}
                className="btn-primary btn-add-cart"
                disabled={!haySeleccion || !veredictoAlDia}
              >
                <ShoppingCart size={18} /> Agregar todo al pedido
              </button>
              <button
                onClick={handleWhatsAppOrder}
                className="btn-secondary btn-order-whatsapp"
                disabled={!haySeleccion || !veredictoAlDia}
              >
                <MessageCircle size={18} /> Consultar por WhatsApp
              </button>
              <button
                onClick={copiarEnlace}
                className="btn-secondary btn-share-build"
                disabled={!haySeleccion}
              >
                <Share2 size={18} /> Copiar enlace del armado
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
                    const variante = varianteDelCajon(product);
                    const price = datosDeVenta(product, variante).precio;
                    const { clase: compClass, etiqueta: compLabel } = estadoDelCandidato(product);
                    const principal = specsParaMostrar(product.specs, definicionDeSpecs)[0];
                    
                    return (
                      <div key={product.id} className="drawer-product-card glass-card">
                        <div className="dp-img">
                          <Imagen
                            loading="lazy" decoding="async" src={product.thumbnail_url} alt={product.name}
                            respaldo={<ShoppingBag size={20} />}
                          />
                        </div>
                        <div className="dp-info">
                          <span className="dp-brand">{product.brand || 'Genérico'}</span>
                          <h5>{product.name}</h5>
                          
                          {/* Indicator badge compatibility */}
                          <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center', marginTop: '0.35rem' }}>
                            <span className={`badge ${compClass}`} style={{ fontSize: '0.65rem', padding: '0.1rem 0.4rem' }}>
                              {compLabel}
                            </span>
                            {principal && (
                              <span className="mini-spec-badge" style={{ fontSize: '0.65rem' }}>
                                {principal.etiqueta}: {principal.valor}
                              </span>
                            )}
                          </div>
                        </div>
                        <div className="dp-action-area">
                          {variante && (
                            <select
                              className="dp-variant-select"
                              value={variante.id}
                              onChange={(e) => setVarianteEnCajon({ ...varianteEnCajon, [product.id]: e.target.value })}
                              aria-label={`Opción de ${product.name}`}
                            >
                              {product.variants!.map((v) => (
                                <option key={v.id} value={v.id} disabled={v.stock <= 0}>
                                  {v.nombre}{v.stock <= 0 ? ' (agotado)' : ''}
                                </option>
                              ))}
                            </select>
                          )}
                          <span className="dp-price">{money(price)}</span>
                          <button
                            onClick={() => handleSelectProduct(activeStep!.key, product, variante)}
                            className="btn-select-add"
                            disabled={variante !== null && variante.stock <= 0}
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
