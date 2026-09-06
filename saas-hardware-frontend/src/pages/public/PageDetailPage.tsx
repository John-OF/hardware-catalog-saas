import './PageDetailPage.css';

import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  ArrowLeft,
  Loader2,
  MessageCircle,
  FileText
} from 'lucide-react';
import { getPublicTenant, resolveTenantDomain } from '../../api/public';
import { getPublicPageDetail, getPublicPages } from '../../api/pages';
import AnnouncementBar from '../../components/public/AnnouncementBar';
import StoreFooter from '../../components/public/StoreFooter';
import StoreHeader from '../../components/public/StoreHeader';
import type { Tenant, Page } from '../../types';
import { useTenantBranding } from '../../hooks/useTenantBranding';
import { useTenantTheme } from '../../hooks/useTenantTheme';
import { sanitizeHtml } from '../../utils/sanitizeHtml';

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

  // Las demás páginas informativas, para la fila de enlaces del pie: desde una
  // se llega a las otras sin volver al catálogo.
  const { data: publicPages = [] } = useQuery<Page[]>({
    queryKey: ['publicPages', resolvedSlug],
    queryFn: () => getPublicPages(resolvedSlug!),
    enabled: !!resolvedSlug,
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
      <div className="loading-fullscreen page-info">
        <Loader2 className="spinner" size={48} />
        <p>Cargando página informativa...</p>
      </div>
    );
  }

  if (isError || !page || !tenant) {
    return (
      <div className="error-fullscreen page-info">
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
    <div className="public-catalog-container font-family-custom page-info">
      <AnnouncementBar theme={tenant.theme} />

      {/* Top Header */}
      <StoreHeader tenant={tenant}>
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
      </StoreHeader>

      {/* Main Content Area */}
      <main className="page-detail-main glass-card animate-fade-in" style={{ padding: '3rem', marginTop: '1.5rem', borderRadius: 'var(--radius-lg)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', marginBottom: '2rem', borderBottom: '1px solid var(--border)', paddingBottom: '1.5rem' }}>
          <FileText size={32} className="text-primary" />
          <h1 style={{ fontSize: '2.2rem', fontWeight: 800, margin: 0 }}>{page.title}</h1>
        </div>

        {page.content ? (
          <div
            className="page-html-content"
            dangerouslySetInnerHTML={{ __html: sanitizeHtml(page.content) }}
            style={{
              fontSize: '1.05rem',
              lineHeight: '1.8',
              color: 'var(--text-secondary)'
            }}
          />
        ) : (
          <div
            className="page-html-content"
            style={{
              fontSize: '1.05rem',
              lineHeight: '1.8',
              color: 'var(--text-secondary)'
            }}
          >
            <p className="text-muted">Sin contenido disponible.</p>
          </div>
        )}
      </main>

      {/* Mismo pie que el catálogo (10.4). Aquí es donde más se usa: quien
          entra a "Sobre nosotros" o "Garantía" viene buscando justamente la
          dirección, el horario y con quién está tratando. */}
      <StoreFooter tenant={tenant} pages={publicPages} buildPath={getPublicPath} />

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