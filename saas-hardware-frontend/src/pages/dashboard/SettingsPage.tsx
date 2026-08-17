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
  Check
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import ImageSourceField from '../../components/ui/ImageSourceField';
import { CURRENCIES, DEFAULT_CURRENCY, formatMoney } from '../../utils/money';
import { DEFAULT_NEUTRAL, NEUTRALS, neutralClass } from '../../utils/neutrals';
import { fontOf } from '../../utils/fonts';
import { THEME_PRESETS, matchingPreset } from '../../utils/themePresets';
import type { ThemePreset } from '../../utils/themePresets';
import type { TenantColorMode, TenantFont, TenantLayout, TenantNeutral } from '../../types';

/** Nombre corto de cada plantilla para la ficha de preset. El selector de abajo
 *  usa textos largos ("Boutique (Tarjetas Grandes)"), que aquí no caben. */
const LAYOUT_LABELS: Record<TenantLayout, string> = {
  grid: 'Tarjetas',
  compact: 'Compacta',
  list: 'Lista',
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
  const [heroTitle, setHeroTitle] = useState('');
  const [heroSubtitle, setHeroSubtitle] = useState('');
  const [bannerUrl, setBannerUrl] = useState('');
  const [pageTitle, setPageTitle] = useState('');
  const [faviconUrl, setFaviconUrl] = useState('');
  
  // Fase 4: Nuevos Estados
  const [customDomain, setCustomDomain] = useState('');
  const [layout, setLayout] = useState<TenantLayout>('grid');
  const [font, setFont] = useState<TenantFont>('sans');
  const [sections, setSections] = useState<{ id: string; type: string; title: string; enabled: boolean }[]>([]);

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
    setHeroTitle(th.hero_title ?? '');
    setHeroSubtitle(th.hero_subtitle ?? '');
    setBannerUrl(th.banner_url ?? '');
    setPageTitle(th.page_title ?? '');
    setFaviconUrl(th.favicon_url ?? '');
    
    setLayout(th.layout ?? 'grid');
    setFont(th.font ?? 'sans');

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
    font,
    layout,
  });

  /**
   * Vuelca el preset en el formulario. NO guarda: el dueño puede seguir
   * ajustando y pulsa "Guardar cambios" cuando le convence.
   *
   * Solo toca las seis perillas de estilo. El nombre, el WhatsApp, el hero, las
   * páginas y las secciones de portada son contenido de la tienda, no aspecto:
   * un preset que los pisara borraría trabajo del dueño.
   */
  const applyPreset = (preset: ThemePreset) => {
    setPrimaryColor(preset.primary_color);
    setAccentColor(preset.accent_color);
    setNeutral(preset.neutral);
    setColorMode(preset.color_mode);
    setFont(preset.font);
    setLayout(preset.layout);
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
        hero_title: heroTitle || null,
        hero_subtitle: heroSubtitle || null,
        banner_url: bannerUrl || null,
        accent_color: accentColor || null,
        color_mode: colorMode,
        neutral,
        page_title: pageTitle || null,
        favicon_url: faviconUrl || null,
        layout,
        font,
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
                    fontFamily: fontOf(preset.font).heading,
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
                  {' · '}{fontOf(preset.font).label}
                  {' · '}{LAYOUT_LABELS[preset.layout]}
                </span>
              </button>
            );
          })}
        </div>

        <span className="helper-text preset-foot">
          {activePreset
            ? `Estás usando «${activePreset.name}». Cualquier cambio que hagas abajo lo deja como tema propio.`
            : 'Tu tienda usa una combinación propia. Aplicar un tema reemplaza colores, tono, modo, fuente y plantilla.'}
        </span>
      </section>

      {/* Estructura y Estilos */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Layout size={18} />
          <div>
            <h3>Estructura y Tipografía</h3>
            <p>Elige el tipo de grilla de productos y la fuente tipográfica de la tienda.</p>
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
          
          <div className="form-group">
            <label>Fuente Tipográfica</label>
            <select 
              value={font} 
              onChange={(e) => setFont(e.target.value as TenantFont)}
              className="premium-input"
              style={{ height: '42px', border: '1px solid var(--border)', padding: '0 0.5rem', borderRadius: 'var(--radius-md)' }}
            >
              <option value="sans">Interfaz Moderna (Inter / Sans)</option>
              <option value="serif">Editorial / Elegante (Merriweather / Serif)</option>
              <option value="mono">Código / Tecnológico (Fira Code / Mono)</option>
              <option value="heading">Marca de Impacto (Outfit / Montserrat)</option>
            </select>
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
        .settings-actions { display: flex; justify-content: flex-end; }
        .settings-actions .btn-primary { display: inline-flex; align-items: center; gap: 0.5rem; }
        .spinner { animation: spin 0.8s linear infinite; }
        .helper-text { font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; }
        .helper-text code { background: rgba(var(--overlay-mix),0.05); padding: 1px 4px; border-radius: 4px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 640px) { .settings-grid { grid-template-columns: 1fr; } }
      `}</style>
    </form>
  );
}
