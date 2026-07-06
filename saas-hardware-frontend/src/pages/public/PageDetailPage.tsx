import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  ArrowLeft,
  Loader2,
  Store,
  MessageCircle,
  FileText
} from 'lucide-react';
import { getPublicTenant, resolveTenantDomain } from '../../api/public';
import { getPublicPageDetail } from '../../api/pages';
import type { Tenant, Page } from '../../types';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';

export default function PageDetailPage() {
  const { slug, pageSlug } = useParams<{ slug: string; pageSlug: string }>();
  const isCustomDomain = !slug;
  const currentDomain = window.location.hostname;

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

  // Fetch Page Detail
  const { data: page, isLoading: isLoadingPage, isError } = useQuery<Page>({
    queryKey: ['publicPageDetail', resolvedSlug, pageSlug],
    queryFn: () => getPublicPageDetail(resolvedSlug!, pageSlug!),
    enabled: !!resolvedSlug && !!pageSlug,
  });

  const getPublicPath = (path: string) => {
    if (isCustomDomain) {
      return path;
    }
    return `/${resolvedSlug}${path}`;
  };

  // Apply fonts/colors and SEO meta-tags
  useTenantBranding(tenant || null, page?.title, page?.content);
  useTenantTheme(tenant || null);

  const isLoading = isLoadingTenant || isLoadingPage;

  if (isLoading) {
    return (
      <div className="loading-fullscreen">
        <Loader2 className="spinner" size={48} />
        <p>Cargando página informativa...</p>
      </div>
    );
  }

  if (isError || !page || !tenant) {
    return (
      <div className="error-fullscreen">
        <AlertTriangleIcon size={48} className="text-danger" />
        <h3>Página no encontrada</h3>
        <p>La página que buscas no existe o está configurada como borrador.</p>
        <Link to={getPublicPath('/')} className="btn-primary" style={{ marginTop: '1.5rem', textDecoration: 'none' }}>
          Volver al Catálogo
        </Link>
      </div>
    );
  }

  return (
    <div className="public-catalog-container font-family-custom">
      {/* Top Header */}
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
          <Link to={getPublicPath('/')} className="btn-secondary" style={{ textDecoration: 'none', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
            <ArrowLeft size={16} /> Volver al Catálogo
          </Link>
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

      {/* Main Content Area */}
      <main className="page-detail-main glass-card animate-fade-in" style={{ padding: '3rem', marginTop: '1.5rem', borderRadius: 'var(--radius-lg)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', marginBottom: '2rem', borderBottom: '1px solid var(--border)', paddingBottom: '1.5rem' }}>
          <FileText size={32} className="text-primary" />
          <h1 style={{ fontSize: '2.2rem', fontWeight: 800, margin: 0 }}>{page.title}</h1>
        </div>

        <div 
          className="page-html-content"
          dangerouslySetInnerHTML={{ __html: page.content || '<p class="text-muted">Sin contenido disponible.</p>' }}
          style={{
            fontSize: '1.05rem',
            lineHeight: '1.8',
            color: 'var(--text-secondary)'
          }}
        />
      </main>

      <footer className="catalog-footer text-muted" style={{ textAlign: 'center', padding: '2rem 0', fontSize: '0.85rem' }}>
        <p>&copy; {new Date().getFullYear()} {tenant.name}. Todos los derechos reservados.</p>
      </footer>

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
        .catalog-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 1rem 2rem;
          border-radius: var(--radius-lg);
        }
      `}</style>
    </div>
  );
}

// Inline fallback for icon compilation
function AlertTriangleIcon({ size, className }: { size: number; className?: string }) {
  return (
    <svg 
      xmlns="http://www.w3.org/2000/svg" 
      width={size} 
      height={size} 
      viewBox="0 0 24 24" 
      fill="none" 
      stroke="currentColor" 
      strokeWidth="2" 
      strokeLinecap="round" 
      strokeLinejoin="round" 
      className={className}
    >
      <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
      <line x1="12" y1="9" x2="12" y2="13"/>
      <line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>
  );
}