import { useEffect, useState } from 'react';
import { useTenantStore } from '../../stores/tenantStore';
import { updateTenant } from '../../api/tenant';
import type { UpdateTenantPayload } from '../../api/tenant';
import { 
  Store, 
  Palette, 
  Sparkles, 
  Globe, 
  Loader2, 
  Save,
  Moon,
  Sun,
  Layout,
  ArrowUp,
  ArrowDown,
  WandSparkles,
  Check,
  Frame,
  Megaphone
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import ImageSourceField from '../../components/ui/ImageSourceField';
import { CURRENCIES, DEFAULT_CURRENCY, formatMoney } from '../../utils/money';
import { DEFAULT_NEUTRAL, NEUTRALS, neutralClass } from '../../utils/neutrals';
import {
  DEFAULT_HERO_STYLE,
  HERO_STYLES,
} from '../../utils/hero';
import {
  CARD_STYLES,
  DEFAULT_CARD_STYLE,
  DEFAULT_DENSITY,
  DEFAULT_RADIUS,
  DENSITIES,
  RADII,
  cardStyleClass,
  radiusClass,
} from '../../utils/shape';
import {
  DEFAULT_FONT_BODY,
  DEFAULT_FONT_HEADING,
  FONT_FAMILIES,
  familyOf,
  loadGoogleFonts,
  resolveFonts,
} from '../../utils/fonts';
import {
  ANNOUNCEMENT_STYLES,
  DEFAULT_ANNOUNCEMENT_STYLE,
  SOCIAL_NETWORKS,
} from '../../utils/branding';
import type { SocialKey } from '../../utils/branding';
import { THEME_PRESETS, matchingPreset } from '../../utils/themePresets';
import type { ThemePreset } from '../../utils/themePresets';
import type {
  TenantAnnouncementStyle,
  TenantCardStyle,
  TenantColorMode,
  TenantDensity,
  TenantFontFamily,
  TenantHeroStyle,
  TenantLayout,
  TenantNeutral,
  TenantRadius,
} from '../../types';

/** Nombre corto de cada plantilla para la ficha de preset. El selector de abajo
 *  usa textos largos ("Boutique (Tarjetas Grandes)"), que aquí no caben. */
const LAYOUT_LABELS: Record<TenantLayout, string> = {
  grid: 'Tarjetas',
  compact: 'Compacta',
  list: 'Lista',
};

/**
 * Id del `<link>` con el que el panel se descarga el catálogo tipográfico
 * entero (10.3).
 *
 * Es distinto del de la tienda pública (`tenant-font`, en useTenantTheme) a
 * propósito: allí se cargan las dos familias que usa la tienda y aquí hacen
 * falta las ocho a la vez, porque las fichas de tema y los dos selectores
 * prometen una letra concreta y tienen que enseñarla de verdad. Al salir de
 * Configuración se quita.
 */
const FONT_PREVIEW_LINK_ID = 'settings-font-preview';

/** Las redes vacías, sin repetir las claves: salen de SOCIAL_NETWORKS. */
const emptySocials = () =>
  Object.fromEntries(SOCIAL_NETWORKS.map((red) => [red.key, ''])) as Record<SocialKey, string>;

/** «Playfair Display + Lora», o un solo nombre si títulos y texto van iguales. */
const pairLabel = (heading: TenantFontFamily, body: TenantFontFamily) => {
  const arriba = familyOf(heading)?.label ?? heading;
  const abajo  = familyOf(body)?.label ?? body;

  return arriba === abajo ? arriba : `${arriba} + ${abajo}`;
};

export default function SettingsPage() {
  const { tenant, setTenant } = useTenantStore();

  // Estados locales para el formulario
  const [name, setName] = useState('');
  const [whatsapp, setWhatsapp] = useState('');
  const [logoUrl, setLogoUrl] = useState('');
  const [primaryColor, setPrimaryColor] = useState('#2563eb');
  const [currency, setCurrency] = useState(DEFAULT_CURRENCY);
  
  // Estados para el tema (colores, portada, etc.)
  const [accentColor, setAccentColor] = useState('#06b6d4');
  const [colorMode, setColorMode] = useState<TenantColorMode>('dark');
  const [neutral, setNeutral] = useState<TenantNeutral>(DEFAULT_NEUTRAL);
  const [radius, setRadius] = useState<TenantRadius>(DEFAULT_RADIUS);
  const [cardStyle, setCardStyle] = useState<TenantCardStyle>(DEFAULT_CARD_STYLE);
  const [density, setDensity] = useState<TenantDensity>(DEFAULT_DENSITY);
  const [heroStyle, setHeroStyle] = useState<TenantHeroStyle>(DEFAULT_HERO_STYLE);
  const [heroTitle, setHeroTitle] = useState('');
  const [heroSubtitle, setHeroSubtitle] = useState('');
  const [bannerUrl, setBannerUrl] = useState('');
  const [pageTitle, setPageTitle] = useState('');
  const [faviconUrl, setFaviconUrl] = useState('');
  
  // Fase 4: Nuevos Estados
  const [customDomain, setCustomDomain] = useState('');
  const [layout, setLayout] = useState<TenantLayout>('grid');
  const [fontHeading, setFontHeading] = useState<TenantFontFamily>(DEFAULT_FONT_HEADING);
  const [fontBody, setFontBody] = useState<TenantFontFamily>(DEFAULT_FONT_BODY);
  const [sections, setSections] = useState<{ id: string; type: string; title: string; enabled: boolean }[]>([]);

  // Elementos de marca (10.4): franja de anuncios y datos del pie.
  const [announcement, setAnnouncement] = useState('');
  const [announcementStyle, setAnnouncementStyle] = useState<TenantAnnouncementStyle>(DEFAULT_ANNOUNCEMENT_STYLE);
  const [footerAddress, setFooterAddress] = useState('');
  const [footerHours, setFooterHours] = useState('');
  const [footerTaxId, setFooterTaxId] = useState('');
  const [socials, setSocials] = useState<Record<SocialKey, string>>(emptySocials);

  const [isSaving, setIsSaving] = useState(false);

  // Archivos subidos (alternativa a la URL) para logo, banner y favicon.
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [bannerFile, setBannerFile] = useState<File | null>(null);
  const [faviconFile, setFaviconFile] = useState<File | null>(null);

  // Cargar los valores actuales del tenant cuando esté disponible en el store
  useEffect(() => {
    if (!tenant) return;
    setName(tenant.name ?? '');
    setWhatsapp(tenant.whatsapp_number ?? '');
    setLogoUrl(tenant.logo_url ?? '');
    setPrimaryColor(tenant.primary_color ?? '#2563eb');
    setCustomDomain(tenant.custom_domain ?? '');
    setCurrency(tenant.currency ?? DEFAULT_CURRENCY);
    
    const th = tenant.theme ?? {};
    setAccentColor(th.accent_color ?? '#06b6d4');
    setColorMode(th.color_mode ?? 'dark');
    setNeutral(th.neutral ?? DEFAULT_NEUTRAL);
    setRadius(th.radius ?? DEFAULT_RADIUS);
    setCardStyle(th.card_style ?? DEFAULT_CARD_STYLE);
    setDensity(th.density ?? DEFAULT_DENSITY);
    setHeroStyle(th.hero_style ?? DEFAULT_HERO_STYLE);
    setHeroTitle(th.hero_title ?? '');
    setHeroSubtitle(th.hero_subtitle ?? '');
    setBannerUrl(th.banner_url ?? '');
    setPageTitle(th.page_title ?? '');
    setFaviconUrl(th.favicon_url ?? '');

    setAnnouncement(th.announcement ?? '');
    setAnnouncementStyle(th.announcement_style ?? DEFAULT_ANNOUNCEMENT_STYLE);
    setFooterAddress(th.footer_address ?? '');
    setFooterHours(th.footer_hours ?? '');
    setFooterTaxId(th.footer_tax_id ?? '');
    setSocials(
      Object.fromEntries(SOCIAL_NETWORKS.map((red) => [red.key, th[red.key] ?? ''])) as Record<SocialKey, string>,
    );
    
    setLayout(th.layout ?? 'grid');

    // Las dos familias salen de resolveFonts y no del theme a pelo: una tienda
    // anterior a 10.3 solo tiene la pareja vieja (`font: 'serif'`), y así el
    // selector abre marcando lo que esa tienda ya se ve, no el valor por
    // defecto. Si abriera en Inter, guardar cualquier otra cosa le cambiaría la
    // letra sin que nadie lo pidiera.
    const fuentes = resolveFonts(th);
    setFontHeading(fuentes.heading.value);
    setFontBody(fuentes.body.value);

    // Parsear secciones editables de la portada
    let parsedSections = [];
    try {
      parsedSections = typeof th.sections === 'string' ? JSON.parse(th.sections) : (th.sections ?? []);
    } catch (e) {
      console.error(e);
    }
    if (!parsedSections || parsedSections.length === 0) {
      parsedSections = [
        { id: '1', type: 'hero', title: 'Banner Principal (Hero)', enabled: true },
        { id: '2', type: 'categories', title: 'Categorías Destacadas', enabled: true },
        { id: '3', type: 'featured', title: 'Vitrina de Productos', enabled: true }
      ];
    }
    setSections(parsedSections);
  }, [tenant]);

  /**
   * Trae de Google las familias del catálogo mientras se está en Configuración.
   *
   * Sin esto los selectores y las fichas de tema saldrían en la letra del panel
   * y elegir tipografía sería elegir a ciegas: el navegador no tiene ninguna de
   * las seis opcionales cargadas aquí, así que caerían todas a su fallback y
   * "Playfair" se vería igual que "Lora". Solo se paga al entrar en esta
   * pantalla, y se deshace al salir.
   */
  useEffect(() => {
    loadGoogleFonts(FONT_PREVIEW_LINK_ID, FONT_FAMILIES.map((f) => f.google));

    return () => document.getElementById(FONT_PREVIEW_LINK_ID)?.remove();
  }, []);

  /**
   * Preset aplicado ahora mismo, o null si el dueño retocó algo después.
   *
   * Se recalcula en cada render en vez de guardarse en estado: si fuera estado
   * habría que acordarse de limpiarlo en los seis `setX` que puede tocar el
   * dueño a mano, y el primero que se olvide deja la ficha marcada mintiendo.
   */
  const activePreset = matchingPreset({
    primary_color: primaryColor,
    accent_color: accentColor,
    neutral,
    color_mode: colorMode,
    font_heading: fontHeading,
    font_body: fontBody,
    layout,
    radius,
    card_style: cardStyle,
    hero_style: heroStyle,
  });

  /**
   * Vuelca el preset en el formulario. NO guarda: el dueño puede seguir
   * ajustando y pulsa "Guardar cambios" cuando le convence.
   *
   * Solo toca las perillas de estilo. El nombre, el WhatsApp, el hero, las
   * páginas y las secciones de portada son contenido de la tienda, no aspecto:
   * un preset que los pisara borraría trabajo del dueño. La densidad tampoco se
   * toca: es preferencia de uso, no identidad (ver themePresets.ts).
   */
  /**
   * Piel de una miniatura de estilo.
   *
   * Lleva la paleta y los colores de ESTA tienda, no los del panel: dentro del
   * dashboard `--glass-bg` es blanco opaco, asi que "cristal" y "solida" se
   * verian identicas y el selector no diria nada. Con el tono de la tienda
   * detras (y los dos manchones de color del CSS) la diferencia se ve.
   */
  const previewSkin = (extra: string, vars?: React.CSSProperties) => ({
    className: `shape-preview ${neutralClass(neutral)}${colorMode === 'light' ? ' light-mode' : ''} ${extra}`,
    style: { '--primary': primaryColor, '--accent': accentColor, ...vars } as React.CSSProperties,
  });

  /** Miniatura de forma: el radio y el estilo de tarjeta que se previsualizan. */
  const shapePreviewProps = (r: TenantRadius, c: TenantCardStyle) =>
    previewSkin(`${radiusClass(r)} ${cardStyleClass(c)}`);

  const applyPreset = (preset: ThemePreset) => {
    setPrimaryColor(preset.primary_color);
    setAccentColor(preset.accent_color);
    setNeutral(preset.neutral);
    setColorMode(preset.color_mode);
    setFontHeading(preset.font_heading);
    setFontBody(preset.font_body);
    setLayout(preset.layout);
    setRadius(preset.radius);
    setCardStyle(preset.card_style);
    setHeroStyle(preset.hero_style);
    toast.success(`Tema "${preset.name}" aplicado. Guarda para publicarlo.`);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);

    const payload: UpdateTenantPayload = {
      name,
      whatsapp_number: whatsapp,
      primary_color: primaryColor,
      logo_url: logoUrl || null,
      custom_domain: customDomain || null,
      currency,
      theme: {
        hero_style: heroStyle,
        hero_title: heroTitle || null,
        hero_subtitle: heroSubtitle || null,
        banner_url: bannerUrl || null,
        accent_color: accentColor || null,
        color_mode: colorMode,
        neutral,
        radius,
        card_style: cardStyle,
        density,
        page_title: pageTitle || null,
        favicon_url: faviconUrl || null,
        announcement: announcement.trim() || null,
        announcement_style: announcementStyle,
        footer_address: footerAddress.trim() || null,
        footer_hours: footerHours.trim() || null,
        footer_tax_id: footerTaxId.trim() || null,
        // Las redes se vuelcan por la lista, no una a una: el formulario y el
        // payload no pueden desincronizarse al añadir una.
        ...Object.fromEntries(SOCIAL_NETWORKS.map((red) => [red.key, socials[red.key].trim() || null])),
        layout,
        // La pareja vieja (`font`) ya no se manda: el backend hace merge del
        // theme, así que la clave sigue ahí para quien no haya guardado desde
        // 10.3, pero estas dos mandan sobre ella y dejan de leerla.
        font_heading: fontHeading,
        font_body: fontBody,
        sections: JSON.stringify(sections) // Serializamos para enviarlo en FormData
      },
      logoFile,
      bannerFile,
      faviconFile,
    };

    try {
      const updated = await updateTenant(payload);
      setTenant(updated);
      // Limpiar archivos: las URLs ya volvieron resueltas en el tenant.
      setLogoFile(null);
      setBannerFile(null);
      setFaviconFile(null);
      toast.success('Configuración de diseño guardada con éxito');
    } catch (err: any) {
      console.error(err);
      const msg = err?.response?.data?.message ?? 'No se pudo guardar la configuración.';
      toast.error(msg);
    } finally {
      setIsSaving(false);
    }
  };

  if (!tenant) {
    return (
      <div className="settings-loading">
        <Loader2 className="spinner" size={28} />
        <p>Cargando configuración...</p>
      </div>
    );
  }

  return (
    <form className="settings-page" onSubmit={handleSubmit}>
      {/* Identidad de la tienda */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Store size={18} />
          <div>
            <h3>Identidad de la tienda</h3>
            <p>Nombre, contacto y logo que verán tus clientes.</p>
          </div>
        </div>
        <div className="settings-grid">
          <div className="form-group">
            <label>Nombre de la tienda</label>
            <input className="premium-input" value={name} onChange={(e) => setName(e.target.value)} maxLength={200} required />
          </div>
          <div className="form-group">
            <label>WhatsApp (con código de país)</label>
            <input className="premium-input" value={whatsapp} onChange={(e) => setWhatsapp(e.target.value)} placeholder="+51999888777" maxLength={20} required />
          </div>
          <div className="form-group">
            <label htmlFor="tenant-currency">Moneda</label>
            <select
              id="tenant-currency"
              className="premium-input"
              value={currency}
              onChange={(e) => setCurrency(e.target.value)}
            >
              {Object.entries(CURRENCIES).map(([code, { label }]) => (
                <option key={code} value={code}>{label}</option>
              ))}
            </select>
            <span className="helper-text">
              Ejemplo: {formatMoney(1234.5, currency)}. Se aplica a todos los precios del catálogo,
              el carrito y los pedidos.
            </span>
          </div>
          <div className="form-group full">
            <ImageSourceField
              label="Logo de la tienda (opcional)"
              url={logoUrl}
              onUrlChange={setLogoUrl}
              file={logoFile}
              onFileChange={setLogoFile}
              accept="image/jpeg,image/png,image/webp"
            />
          </div>
        </div>
      </section>

      {/* Dominio Personalizado */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Globe size={18} />
          <div>
            <h3>Dominio personalizado</h3>
            <p>Configura tu propio dominio en producción (ej. tienda.misitio.com).</p>
          </div>
        </div>
        <div className="settings-grid">
          <div className="form-group full">
            <label>Dominio Propio</label>
            <input 
              className="premium-input" 
              value={customDomain} 
              onChange={(e) => setCustomDomain(e.target.value.toLowerCase().trim())} 
              placeholder="ejemplo.com o tienda.componentespc.com" 
              maxLength={255} 
            />
            <span className="helper-text">
              Deja en blanco para usar la URL por defecto: <code>http://localhost:5173/{tenant.slug}</code>
            </span>
          </div>
        </div>
      </section>

      {/* Temas prediseñados (9.3) */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <WandSparkles size={18} />
          <div>
            <h3>Temas prediseñados</h3>
            <p>
              Aplica un estilo completo de un clic y ajusta después lo que quieras.
              No se publica hasta que pulses «Guardar cambios».
            </p>
          </div>
        </div>

        <div className="preset-gallery">
          {THEME_PRESETS.map((preset) => {
            const isActive = activePreset?.id === preset.id;
            return (
              <button
                key={preset.id}
                type="button"
                className={`preset-card${isActive ? ' active' : ''}`}
                onClick={() => applyPreset(preset)}
                aria-pressed={isActive}
              >
                {/* La miniatura usa la clase de paleta real del tono y le mete
                    encima el primario/acento del preset como variables inline,
                    así que muestra los colores de verdad y no una copia. */}
                <span
                  className={`preset-preview ${neutralClass(preset.neutral)}${preset.color_mode === 'light' ? ' light-mode' : ''}`}
                  style={{
                    '--primary': preset.primary_color,
                    '--accent': preset.accent_color,
                    fontFamily: familyOf(preset.font_heading)?.stack,
                  } as React.CSSProperties}
                >
                  <span className="pp-hero">
                    <span className="pp-chip" />
                    <span className="pp-title">Aa</span>
                  </span>
                  <span className="pp-grid">
                    <span className="pp-item">
                      <span className="pp-img" />
                      <span className="pp-price" />
                    </span>
                    <span className="pp-item">
                      <span className="pp-img" />
                      <span className="pp-price" />
                    </span>
                  </span>
                </span>

                <span className="preset-name">
                  {preset.name}
                  {isActive && <Check size={13} />}
                </span>
                <span className="preset-hint">{preset.hint}</span>
                <span className="preset-meta">
                  {preset.color_mode === 'light' ? 'Claro' : 'Oscuro'}
                  {' · '}{pairLabel(preset.font_heading, preset.font_body)}
                  {' · '}{LAYOUT_LABELS[preset.layout]}
                </span>
              </button>
            );
          })}
        </div>

        <span className="helper-text preset-foot">
          {activePreset
            ? `Estás usando «${activePreset.name}». Cualquier cambio que hagas abajo lo deja como tema propio.`
            : 'Tu tienda usa una combinación propia. Aplicar un tema reemplaza colores, tono, modo, tipografías, plantilla, forma y estilo de portada; no toca la densidad ni tus textos.'}
        </span>
      </section>

      {/* Estructura y Estilos */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Layout size={18} />
          <div>
            <h3>Estructura y Tipografía</h3>
            <p>Elige la grilla de productos y las dos tipografías de la tienda: una para los títulos y otra para el texto.</p>
          </div>
        </div>
        <div className="settings-grid">
          <div className="form-group">
            <label>Plantilla del Catálogo (Layout)</label>
            <select 
              value={layout} 
              onChange={(e) => setLayout(e.target.value as TenantLayout)}
              className="premium-input"
              style={{ height: '42px', border: '1px solid var(--border)', padding: '0 0.5rem', borderRadius: 'var(--radius-md)' }}
            >
              <option value="grid">Boutique (Tarjetas Grandes)</option>
              <option value="compact">Mayorista (Grilla Compacta)</option>
              <option value="list">Industrial (Fila Densa / Lista)</option>
            </select>
          </div>
          
          {/* Tipografía (10.3): dos familias sueltas en vez de la pareja
              cerrada de antes. La misma lista en los dos selectores a
              propósito: restringir cuál puede ir arriba y cuál abajo se
              convierte en "por qué no me deja", y una serif de cuerpo con una
              sans de título es una combinación legítima. */}
          <div className="form-group">
            <label>Fuente de los títulos</label>
            <select
              value={fontHeading}
              onChange={(e) => setFontHeading(e.target.value as TenantFontFamily)}
              className="premium-input"
              style={{ height: '42px', border: '1px solid var(--border)', padding: '0 0.5rem', borderRadius: 'var(--radius-md)' }}
            >
              {FONT_FAMILIES.map((f) => (
                <option key={f.value} value={f.value} style={{ fontFamily: f.stack }}>
                  {f.label} — {f.hint}
                </option>
              ))}
            </select>
          </div>

          <div className="form-group">
            <label>Fuente del texto</label>
            <select
              value={fontBody}
              onChange={(e) => setFontBody(e.target.value as TenantFontFamily)}
              className="premium-input"
              style={{ height: '42px', border: '1px solid var(--border)', padding: '0 0.5rem', borderRadius: 'var(--radius-md)' }}
            >
              {FONT_FAMILIES.map((f) => (
                <option key={f.value} value={f.value} style={{ fontFamily: f.stack }}>
                  {f.label} — {f.hint}
                </option>
              ))}
            </select>
          </div>

          {/* Muestra viva. Un nombre de familia no dice nada: lo que decide al
              dueño es ver un título y un párrafo suyos con esas dos letras
              juntas, que es donde se nota si la combinación pega o no. */}
          <div className="form-group full">
            <label>Cómo se lee</label>
            <span
              {...previewSkin(
                `${radiusClass(radius)} ${cardStyleClass(cardStyle)} font-sample`,
                // Las mismas dos variables que inyecta useTenantTheme en la
                // tienda, pero acotadas a esta caja: la muestra se pinta por el
                // mismo camino que el catálogo, no imitándolo.
                {
                  '--font-heading': familyOf(fontHeading)?.stack,
                  '--font-sans': familyOf(fontBody)?.stack,
                } as React.CSSProperties,
              )}
            >
              <span className="glass-card fs-card">
                <span className="fs-title">Tarjeta gráfica RTX 4070 Super</span>
                <span className="fs-body">
                  Ideal para jugar en 1440p a 144 Hz. Garantía de 12 meses y stock disponible
                  para entrega inmediata.
                </span>
                <span className="fs-price">{formatMoney(2499, currency)}</span>
              </span>
            </span>
            <span className="helper-text">
              Los títulos, el nombre de la tienda y los precios usan la primera; las
              descripciones y los botones, la segunda. Puedes poner la misma en los dos.
            </span>
          </div>
        </div>
      </section>

      {/* Colores y tema */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Palette size={18} />
          <div>
            <h3>Colores y tema</h3>
            <p>Define la paleta y el modo visual del catálogo.</p>
          </div>
        </div>
        <div className="settings-grid">
          <div className="form-group">
            <label>Color principal</label>
            <div className="color-row">
              <input type="color" value={primaryColor} onChange={(e) => setPrimaryColor(e.target.value)} />
              <span>{primaryColor.toUpperCase()}</span>
            </div>
          </div>
          <div className="form-group">
            <label>Color de acento</label>
            <div className="color-row">
              <input type="color" value={accentColor} onChange={(e) => setAccentColor(e.target.value)} />
              <span>{accentColor.toUpperCase()}</span>
            </div>
          </div>
          <div className="form-group full">
            <label>Modo de color</label>
            <div className="segment">
              <button type="button" className={colorMode === 'dark' ? 'active' : ''} onClick={() => setColorMode('dark')}>
                <Moon size={16} /> Oscuro
              </button>
              <button type="button" className={colorMode === 'light' ? 'active' : ''} onClick={() => setColorMode('light')}>
                <Sun size={16} /> Claro
              </button>
            </div>
          </div>

          <div className="form-group full">
            <label>Tono neutral</label>
            <div className="neutral-picker">
              {NEUTRALS.map(({ value, label, hint }) => (
                <button
                  key={value}
                  type="button"
                  className={`neutral-option${neutral === value ? ' active' : ''}`}
                  onClick={() => setNeutral(value)}
                  aria-pressed={neutral === value}
                  title={`${label} — ${hint}`}
                >
                  {/* La miniatura lleva las MISMAS clases de paleta que el body
                      del catálogo público, así que se pinta sola con los colores
                      reales de index.css y no puede quedar desfasada. */}
                  <span className={`neutral-preview ${neutralClass(value)}${colorMode === 'light' ? ' light-mode' : ''}`}>
                    <span className="np-card">
                      <span className="np-line" />
                      <span className="np-line short" />
                    </span>
                  </span>
                  <span className="neutral-name">{label}</span>
                  <span className="neutral-hint">{hint}</span>
                </button>
              ))}
            </div>
            <span className="helper-text">
              Es la base de fondos, bordes y textos del catálogo. El color principal y el de
              acento no cambian: dos tiendas con el mismo color se ven distintas según el tono.
            </span>
          </div>
        </div>
      </section>

      {/* Forma y densidad (10.1) */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Frame size={18} />
          <div>
            <h3>Forma y densidad</h3>
            <p>La personalidad de la tienda más allá del color: esquinas, tarjetas y aire.</p>
          </div>
        </div>
        <div className="settings-grid">
          <div className="form-group full">
            <label>Bordes</label>
            <div className="shape-picker">
              {RADII.map(({ value, label, hint }) => (
                <button
                  key={value}
                  type="button"
                  className={`shape-option${radius === value ? ' active' : ''}`}
                  onClick={() => setRadius(value)}
                  aria-pressed={radius === value}
                >
                  {/* La miniatura es un .glass-card de verdad bajo las mismas
                      clases de forma que lleva el catálogo, así que muestra el
                      radio y el estilo exactos, no una imitación. */}
                  <span {...shapePreviewProps(value, cardStyle)}>
                    <span className="glass-card sp-card" />
                  </span>
                  <span className="shape-name">{label}</span>
                  <span className="shape-hint">{hint}</span>
                </button>
              ))}
            </div>
          </div>

          <div className="form-group full">
            <label>Tarjetas de producto</label>
            <div className="shape-picker">
              {CARD_STYLES.map(({ value, label, hint }) => (
                <button
                  key={value}
                  type="button"
                  className={`shape-option${cardStyle === value ? ' active' : ''}`}
                  onClick={() => setCardStyle(value)}
                  aria-pressed={cardStyle === value}
                >
                  <span {...shapePreviewProps(radius, value)}>
                    <span className="glass-card sp-card" />
                  </span>
                  <span className="shape-name">{label}</span>
                  <span className="shape-hint">{hint}</span>
                </button>
              ))}
            </div>
          </div>

          <div className="form-group full">
            <label>Densidad del catálogo</label>
            <div className="segment">
              {DENSITIES.map(({ value, label }) => (
                <button
                  key={value}
                  type="button"
                  className={density === value ? 'active' : ''}
                  onClick={() => setDensity(value)}
                >
                  {label}
                </button>
              ))}
            </div>
            <span className="helper-text">
              Escala los espacios de la grilla sin cambiar de plantilla: «Compacta» mete más
              productos en pantalla, «Amplia» les da aire. Los temas prediseñados no la tocan.
            </span>
          </div>
        </div>
      </section>

      {/* Secciones de la Portada */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Sparkles size={18} />
          <div>
            <h3>Secciones de la Portada</h3>
            <p>Reordena y activa/desactiva los bloques de la página de inicio del catálogo público.</p>
          </div>
        </div>

        <div className="sections-list" style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem', marginTop: '1rem' }}>
          {sections.map((sec, idx) => (
            <div key={sec.id} className="section-item-row" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0.75rem 1rem', background: 'rgba(var(--overlay-mix),0.015)', border: '1px solid var(--border)', borderRadius: 'var(--radius-md)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
                <input 
                  type="checkbox" 
                  checked={sec.enabled} 
                  onChange={(e) => {
                    const copy = [...sections];
                    copy[idx].enabled = e.target.checked;
                    setSections(copy);
                  }}
                  style={{ cursor: 'pointer' }}
                />
                <div>
                  <span style={{ fontSize: '0.9rem', fontWeight: 600, color: sec.enabled ? 'var(--text-primary)' : 'var(--text-muted)' }}>{sec.title}</span>
                  <span style={{ display: 'block', fontSize: '0.75rem', color: 'var(--text-secondary)' }}>
                    Bloque: <code>{sec.type}</code>
                  </span>
                </div>
              </div>
              
              <div style={{ display: 'flex', gap: '0.25rem' }}>
                <button
                  type="button"
                  disabled={idx === 0}
                  onClick={() => {
                    const copy = [...sections];
                    const temp = copy[idx];
                    copy[idx] = copy[idx - 1];
                    copy[idx - 1] = temp;
                    setSections(copy);
                  }}
                  className="btn-icon"
                  style={{ padding: '4px', opacity: idx === 0 ? 0.3 : 1 }}
                >
                  <ArrowUp size={16} />
                </button>
                <button
                  type="button"
                  disabled={idx === sections.length - 1}
                  onClick={() => {
                    const copy = [...sections];
                    const temp = copy[idx];
                    copy[idx] = copy[idx + 1];
                    copy[idx + 1] = temp;
                    setSections(copy);
                  }}
                  className="btn-icon"
                  style={{ padding: '4px', opacity: idx === sections.length - 1 ? 0.3 : 1 }}
                >
                  <ArrowDown size={16} />
                </button>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Portada / Hero */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Sparkles size={18} />
          <div>
            <h3>Contenido del Hero (Banner Principal)</h3>
            <p>Textos e imagen principal que aparecerán en la sección Hero de la portada.</p>
          </div>
        </div>
        <div className="settings-grid">
          {/* Estilo de portada (10.2). Va lo primero de la tarjeta porque decide
              cómo se muestran los campos que vienen debajo. */}
          <div className="form-group full">
            <label>Estilo de portada</label>
            <div className="hero-picker">
              {HERO_STYLES.map(({ value, label, hint }) => (
                <button
                  key={value}
                  type="button"
                  className={`shape-option${heroStyle === value ? ' active' : ''}`}
                  onClick={() => setHeroStyle(value)}
                  aria-pressed={heroStyle === value}
                >
                  {/* La miniatura hereda el radio y el estilo de tarjeta de
                      arriba, así que enseña la portada con la forma real de la
                      tienda y no con una genérica. */}
                  <span {...previewSkin(`${radiusClass(radius)} ${cardStyleClass(cardStyle)} hp-${value}`)}>
                    <span className="glass-card hp-hero">
                      <span className="hp-text">
                        <span className="hp-line" />
                        <span className="hp-line short" />
                      </span>
                      {value === 'split' && <span className="hp-img" />}
                    </span>
                  </span>
                  <span className="shape-name">{label}</span>
                  <span className="shape-hint">{hint}</span>
                </button>
              ))}
            </div>
            <span className="helper-text">
              Cada estilo usa la imagen de portada a su manera: «Clásico» y «Centrado» la ponen
              de fondo con un velo oscuro, «Partido» la deja al lado del texto y «Mínimo» no la
              muestra. La imagen se guarda igual, así que puedes volver a otro estilo y sigue ahí.
            </span>
          </div>
          <div className="form-group full">
            <label>Título del hero</label>
            <input className="premium-input" value={heroTitle} onChange={(e) => setHeroTitle(e.target.value)} placeholder="Encuentra las mejores piezas de hardware" maxLength={120} />
          </div>
          <div className="form-group full">
            <label>Subtítulo del hero</label>
            <textarea className="premium-input" value={heroSubtitle} onChange={(e) => setHeroSubtitle(e.target.value)} rows={2} maxLength={240} placeholder="Explora nuestro catálogo en tiempo real..." />
          </div>
          <div className="form-group full">
            <ImageSourceField
              label="Imagen de portada (opcional)"
              url={bannerUrl}
              onUrlChange={setBannerUrl}
              file={bannerFile}
              onFileChange={setBannerFile}
              accept="image/jpeg,image/png,image/webp"
            />
          </div>
        </div>
      </section>

      {/* Marca y contacto (10.4) */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Megaphone size={18} />
          <div>
            <h3>Marca y contacto</h3>
            <p>Una franja de anuncios sobre el catálogo y los datos de tu tienda en el pie de página.</p>
          </div>
        </div>
        <div className="settings-grid">
          <div className="form-group full">
            <label>Mensaje de la franja <span className="optional">(opcional)</span></label>
            <input
              className="premium-input"
              value={announcement}
              onChange={(e) => setAnnouncement(e.target.value)}
              maxLength={120}
              placeholder="Envío gratis en Lima por compras desde S/ 300"
            />
            <span className="helper-text">
              Sale arriba del todo, en el catálogo y en tus páginas. Se muestra mientras haya
              texto: para quitarla, borra el mensaje.
            </span>
          </div>

          {announcement.trim() !== '' && (
            <div className="form-group full">
              <label>Color de la franja</label>
              <div className="shape-picker">
                {ANNOUNCEMENT_STYLES.map((estilo) => (
                  <button
                    key={estilo.value}
                    type="button"
                    className={`shape-option${announcementStyle === estilo.value ? ' active' : ''}`}
                    onClick={() => setAnnouncementStyle(estilo.value)}
                    aria-pressed={announcementStyle === estilo.value}
                  >
                    {/* La muestra lleva el mensaje de verdad y los colores de
                        esta tienda: los tres estilos salen del mismo sitio que
                        la franja pública, así que no pueden discrepar. */}
                    <span {...previewSkin('ab-preview')}>
                      <span
                        className="ab-sample"
                        style={{ background: estilo.bg, color: estilo.fg, borderColor: estilo.border }}
                      >
                        {announcement.trim()}
                      </span>
                    </span>
                    <span className="shape-name">{estilo.label}</span>
                    <span className="shape-hint">{estilo.hint}</span>
                  </button>
                ))}
              </div>
            </div>
          )}

          <div className="form-group">
            <label>Dirección <span className="optional">(opcional)</span></label>
            <input
              className="premium-input"
              value={footerAddress}
              onChange={(e) => setFooterAddress(e.target.value)}
              maxLength={160}
              placeholder="Av. Garcilaso de la Vega 1234, Lima"
            />
          </div>

          <div className="form-group">
            <label>Horario de atención <span className="optional">(opcional)</span></label>
            <input
              className="premium-input"
              value={footerHours}
              onChange={(e) => setFooterHours(e.target.value)}
              maxLength={120}
              placeholder="Lun a Sáb de 10:00 a 20:00"
            />
          </div>

          <div className="form-group">
            <label>Identificación fiscal <span className="optional">(opcional)</span></label>
            <input
              className="premium-input"
              value={footerTaxId}
              onChange={(e) => setFooterTaxId(e.target.value)}
              maxLength={40}
              placeholder="RUC 20512345678"
            />
            <span className="helper-text">Se muestra tal cual lo escribas, incluido el prefijo.</span>
          </div>

          {/* Las redes salen de SOCIAL_NETWORKS: añadir una es tocar esa lista
              (y la whitelist del backend), no este formulario. */}
          {SOCIAL_NETWORKS.map((red) => (
            <div className="form-group" key={red.key}>
              <label>{red.label} <span className="optional">(opcional)</span></label>
              <input
                className="premium-input"
                value={socials[red.key]}
                onChange={(e) => setSocials((prev) => ({ ...prev, [red.key]: e.target.value }))}
                maxLength={200}
                placeholder={red.placeholder}
              />
            </div>
          ))}

          <div className="form-group full">
            <span className="helper-text">
              Pega el enlace completo de cada red, tal como lo ves en tu navegador
              (empezando por https://). Las que dejes vacías no aparecen en el pie.
            </span>
          </div>
        </div>
      </section>

      {/* Pestaña del navegador */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Globe size={18} />
          <div>
            <h3>Pestaña del navegador</h3>
            <p>Título e ícono (favicon) que se muestran en la pestaña al abrir tu catálogo.</p>
          </div>
        </div>
        <div className="settings-grid">
          <div className="form-group full">
            <label>Título de la pestaña <span className="optional">(por defecto, el nombre de la tienda)</span></label>
            <input className="premium-input" value={pageTitle} onChange={(e) => setPageTitle(e.target.value)} placeholder={name || 'Mi Tienda'} maxLength={60} />
          </div>
          <div className="form-group full">
            <ImageSourceField
              label="Favicon (opcional)"
              hint="Imagen cuadrada pequeña en .png o .ico. Máx 512 KB."
              url={faviconUrl}
              onUrlChange={setFaviconUrl}
              file={faviconFile}
              onFileChange={setFaviconFile}
              accept="image/png,image/x-icon"
            />
          </div>
        </div>
      </section>

      <div className="settings-actions">
        <button type="submit" className="btn-primary" disabled={isSaving}>
          {isSaving ? <Loader2 className="spinner" size={18} /> : <Save size={18} />}
          {isSaving ? 'Guardando...' : 'Guardar cambios'}
        </button>
      </div>

      <style>{`
        .settings-page { max-width: 820px; display: flex; flex-direction: column; gap: 1.5rem; }
        .settings-loading { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; padding: 4rem; color: var(--text-secondary); }
        .settings-card { padding: 1.75rem; border-radius: var(--radius-lg); }
        .settings-card-head { display: flex; align-items: flex-start; gap: 0.85rem; margin-bottom: 1.5rem; color: var(--primary); }
        .settings-card-head h3 { font-family: var(--font-heading); font-size: 1.05rem; color: var(--text-primary); }
        .settings-card-head p { font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.15rem; }
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); }
        .form-group .optional { font-weight: 400; color: var(--text-muted); }
        textarea.premium-input { resize: vertical; font-family: var(--font-sans); }
        .color-row { display: flex; align-items: center; gap: 0.75rem; }
        .color-row input[type="color"] { width: 48px; height: 40px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: transparent; cursor: pointer; padding: 2px; }
        .color-row span { font-family: monospace; font-size: 0.85rem; color: var(--text-secondary); }
        .segment { display: inline-flex; border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; width: fit-content; }
        .segment button { display: flex; align-items: center; gap: 0.45rem; padding: 0.6rem 1.1rem; background: transparent; border: none; color: var(--text-secondary); font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: var(--transition); }
        .segment button.active { background: var(--primary); color: #fff; }
        .preset-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 0.85rem; }
        .preset-card { display: flex; flex-direction: column; gap: 0.15rem; padding: 0.6rem; background: transparent; border: 1px solid var(--border); border-radius: var(--radius-md); cursor: pointer; text-align: left; transition: var(--transition); }
        .preset-card:hover { border-color: var(--text-muted); transform: translateY(-1px); }
        .preset-card.active { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
        /* Miniatura: hereda del preset las variables de paleta (por la clase de
           tono) y el primario/acento (inline), asi que se pinta sola. */
        .preset-preview { display: flex; flex-direction: column; gap: 5px; height: 84px; padding: 6px; margin-bottom: 0.55rem; border-radius: var(--radius-sm); background: var(--bg-app); overflow: hidden; }
        .pp-hero { display: flex; align-items: center; gap: 5px; padding: 5px 6px; border-radius: 4px; background: var(--glass-bg); border: 1px solid var(--glass-border); }
        .pp-chip { width: 14px; height: 6px; border-radius: 3px; background: var(--accent); flex: none; }
        .pp-title { font-size: 11px; font-weight: 700; line-height: 1; color: var(--text-primary); }
        .pp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; flex: 1; }
        .pp-item { display: flex; flex-direction: column; gap: 4px; padding: 4px; border-radius: 4px; background: var(--glass-bg); border: 1px solid var(--glass-border); }
        .pp-img { flex: 1; border-radius: 2px; background: var(--bg-card-hover); }
        .pp-price { height: 5px; width: 62%; border-radius: 3px; background: var(--primary); }
        .preset-name { display: flex; align-items: center; gap: 0.3rem; font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
        .preset-name svg { color: var(--primary); }
        .preset-hint { font-size: 0.72rem; line-height: 1.35; color: var(--text-secondary); }
        .preset-meta { margin-top: 0.2rem; font-size: 0.68rem; color: var(--text-muted); }
        .preset-foot { display: block; margin-top: 1rem; }
        .neutral-picker { display: grid; grid-template-columns: repeat(auto-fit, minmax(92px, 1fr)); gap: 0.6rem; }
        .neutral-option { display: flex; flex-direction: column; gap: 0.15rem; padding: 0.5rem; background: transparent; border: 1px solid var(--border); border-radius: var(--radius-md); cursor: pointer; text-align: left; transition: var(--transition); }
        .neutral-option:hover { border-color: var(--text-muted); }
        .neutral-option.active { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
        .neutral-preview { display: block; height: 52px; padding: 0.45rem; margin-bottom: 0.4rem; border-radius: var(--radius-sm); background: var(--bg-app); overflow: hidden; }
        .np-card { display: block; height: 100%; padding: 0.4rem; border-radius: 4px; background: var(--glass-bg); border: 1px solid var(--glass-border); }
        .np-line { display: block; height: 5px; border-radius: 3px; background: var(--text-primary); }
        .np-line.short { width: 55%; margin-top: 6px; background: var(--text-secondary); }
        .neutral-name { font-size: 0.8rem; font-weight: 600; color: var(--text-primary); }
        .neutral-hint { font-size: 0.7rem; color: var(--text-muted); }
        .shape-picker { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.6rem; }
        .shape-option { display: flex; flex-direction: column; gap: 0.1rem; padding: 0.5rem; background: transparent; border: 1px solid var(--border); border-radius: var(--radius-md); cursor: pointer; text-align: left; transition: var(--transition); }
        .shape-option:hover { border-color: var(--text-muted); }
        .shape-option.active { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
        /* El radio del marco va en literal a proposito: la miniatura lleva la
           clase .radius-* que se esta previsualizando, asi que cualquier token
           lo secuestraria y el propio marco cambiaria de forma. Los dos
           manchones de color son lo que hace visible si la tarjeta deja pasar
           el fondo o no. */
        .shape-preview { display: block; padding: 10px; margin-bottom: 0.4rem; border-radius: 8px; overflow: hidden;
          background:
            radial-gradient(circle at 14% 18%, color-mix(in srgb, var(--primary) 45%, transparent) 0%, transparent 38%),
            radial-gradient(circle at 86% 84%, color-mix(in srgb, var(--accent) 35%, transparent) 0%, transparent 38%),
            var(--bg-app); }
        .sp-card { display: block; height: 52px; }
        /* Sin esto la miniatura de "Cristal" sale translucida pero sin
           desenfoque, que es la mitad de lo que promete su etiqueta. No basta
           con reponer --glass-blur: el panel apaga la propiedad directamente
           en ".dashboard-layout .glass-card", asi que hay que ganarle en
           especificidad y volver a pedirla. */
        .shape-preview.cards-glass .glass-card {
          backdrop-filter: blur(12px);
          -webkit-backdrop-filter: blur(12px);
        }
        /* La miniatura es estatica: el hover del .glass-card real la levantaria
           dentro del boton, que ya se mueve solo. */
        .shape-preview .glass-card:hover { transform: none; }
        /* Tipografia (10.3): la muestra reutiliza el marco de miniatura, que ya
           trae la paleta y la forma de la tienda, pero con texto de verdad. Dos
           familias solo se juzgan viendolas juntas y a su tamanio real: en una
           lista de nombres, "Lora" y "Merriweather" son la misma palabra.
           --font-heading y --font-sans las inyecta el componente en el span. */
        .font-sample .fs-card { display: flex; flex-direction: column; gap: 0.4rem; padding: 0.9rem 1rem; }
        .fs-title { font-family: var(--font-heading); font-size: 1.1rem; font-weight: 700; line-height: 1.25; color: var(--text-primary); }
        .fs-body { font-family: var(--font-sans); font-size: 0.83rem; line-height: 1.5; color: var(--text-secondary); }
        .fs-price { font-family: var(--font-heading); font-size: 1rem; font-weight: 700; color: var(--primary); }
        /* Marca (10.4): la muestra de la franja. El marco es el de siempre
           (.shape-preview, que ya trae la paleta de la tienda) y la banda de
           dentro copia las medidas de .announcement-bar; los colores vienen de
           branding.ts en linea, que es lo unico que no puede divergir. */
        .ab-preview { display: flex; align-items: center; min-height: 46px; }
        .ab-sample {
          display: block; width: 100%; padding: 0.45rem 0.6rem;
          border: 1px solid transparent; border-radius: var(--radius-md);
          font-size: 0.68rem; font-weight: 600; line-height: 1.3; text-align: center;
          overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        /* Portada (10.2): mismo chrome de opcion que la forma (.shape-option) y
           el mismo marco de miniatura (.shape-preview), que ya trae la paleta de
           la tienda; lo unico propio es el esquema de dentro. */
        .hero-picker { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.6rem; }
        .hp-hero { display: flex; align-items: center; gap: 6px; height: 52px; padding: 8px; }
        .hp-text { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 0; }
        .hp-line { display: block; width: 72%; height: 6px; border-radius: 3px; background: var(--text-primary); }
        .hp-line.short { width: 45%; height: 4px; background: var(--text-secondary); }
        .hp-centered .hp-text { align-items: center; }
        .hp-centered .hp-line { width: 80%; }
        .hp-centered .hp-line.short { width: 52%; }
        .hp-split .hp-line { width: 100%; }
        .hp-split .hp-line.short { width: 62%; }
        /* El degradado de marca hace que se lea como una foto y no como otro
           bloque de texto. */
        .hp-img { flex: none; width: 38%; align-self: stretch; border-radius: 4px;
          background: linear-gradient(135deg, color-mix(in srgb, var(--primary) 55%, transparent), color-mix(in srgb, var(--accent) 45%, transparent)); }
        /* Igual que en el catalogo: el minimo no es una tarjeta. Va con el
           marco delante (tres clases) porque ".cards-solid .glass-card" de
           index.css tambien pinta fondo y con dos empatarian. */
        .shape-preview.hp-minimal .hp-hero { background: transparent; border: none; box-shadow: none; border-radius: 0; border-bottom: 1px solid var(--border); padding: 8px 0; }
        .hp-minimal .hp-line { width: 58%; }
        .hp-minimal .hp-line.short { width: 34%; }
        .shape-name { font-size: 0.8rem; font-weight: 600; color: var(--text-primary); }
        .shape-hint { font-size: 0.68rem; line-height: 1.3; color: var(--text-muted); }
        .settings-actions { display: flex; justify-content: flex-end; }
        .settings-actions .btn-primary { display: inline-flex; align-items: center; gap: 0.5rem; }
        .spinner { animation: spin 0.8s linear infinite; }
        .helper-text { font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; }
        .helper-text code { background: rgba(var(--overlay-mix),0.05); padding: 1px 4px; border-radius: 4px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 640px) { .settings-grid { grid-template-columns: 1fr; } .hero-picker { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
      `}</style>
    </form>
  );
}
