import { useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { Save, Store, Palette, Sparkles, Loader2, Sun, Moon, Globe } from 'lucide-react';
import { useTenantStore } from '../../stores/tenantStore';
import { updateTenant } from '../../api/tenant';
import type { UpdateTenantPayload } from '../../api/tenant';
import ImageSourceField from '../../components/ui/ImageSourceField';

export default function SettingsPage() {
  const tenant = useTenantStore((s) => s.tenant);
  const setTenant = useTenantStore((s) => s.setTenant);

  const [name, setName] = useState('');
  const [whatsapp, setWhatsapp] = useState('');
  const [logoUrl, setLogoUrl] = useState('');
  const [primaryColor, setPrimaryColor] = useState('#2563eb');
  const [accentColor, setAccentColor] = useState('#06b6d4');
  const [colorMode, setColorMode] = useState<'dark' | 'light'>('dark');
  const [heroTitle, setHeroTitle] = useState('');
  const [heroSubtitle, setHeroSubtitle] = useState('');
  const [bannerUrl, setBannerUrl] = useState('');
  const [pageTitle, setPageTitle] = useState('');
  const [faviconUrl, setFaviconUrl] = useState('');
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
    const th = tenant.theme ?? {};
    setAccentColor(th.accent_color ?? '#06b6d4');
    setColorMode(th.color_mode ?? 'dark');
    setHeroTitle(th.hero_title ?? '');
    setHeroSubtitle(th.hero_subtitle ?? '');
    setBannerUrl(th.banner_url ?? '');
    setPageTitle(th.page_title ?? '');
    setFaviconUrl(th.favicon_url ?? '');
  }, [tenant]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);

    const payload: UpdateTenantPayload = {
      name,
      whatsapp_number: whatsapp,
      primary_color: primaryColor,
      logo_url: logoUrl || null,
      theme: {
        hero_title: heroTitle || null,
        hero_subtitle: heroSubtitle || null,
        banner_url: bannerUrl || null,
        accent_color: accentColor || null,
        color_mode: colorMode,
        page_title: pageTitle || null,
        favicon_url: faviconUrl || null,
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
      toast.success('Personalización guardada con éxito');
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
        </div>
      </section>

      {/* Portada / Hero */}
      <section className="settings-card glass-card">
        <div className="settings-card-head">
          <Sparkles size={18} />
          <div>
            <h3>Portada del catálogo</h3>
            <p>Textos e imagen principal de tu vitrina pública.</p>
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
              hint="Recomendado: imagen cuadrada pequeña (.png, .ico o .svg). Máx 512 KB."
              url={faviconUrl}
              onUrlChange={setFaviconUrl}
              file={faviconFile}
              onFileChange={setFaviconFile}
              accept="image/png,image/x-icon,image/svg+xml,image/jpeg,image/webp"
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
        .settings-actions { display: flex; justify-content: flex-end; }
        .settings-actions .btn-primary { display: inline-flex; align-items: center; gap: 0.5rem; }
        .spinner { animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 640px) { .settings-grid { grid-template-columns: 1fr; } }
      `}</style>
    </form>
  );
}
