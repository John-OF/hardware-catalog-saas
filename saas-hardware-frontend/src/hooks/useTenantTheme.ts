import { useEffect } from 'react';
import type { Tenant } from '../types';

/**
 * Aplica los estilos y colores dinámicos del tenant al documento.
 * Maneja la inyección de variables CSS y la clase del modo claro/oscuro.
 */
export function useTenantTheme(tenant?: Tenant | null) {
  useEffect(() => {
    if (!tenant) return;

    if (tenant.primary_color) {
      document.documentElement.style.setProperty('--primary', tenant.primary_color);
      // Generar un glow rgb a partir de hexadecimal
      const hex = tenant.primary_color.replace('#', '');
      const r = parseInt(hex.substring(0, 2), 16);
      const g = parseInt(hex.substring(2, 4), 16);
      const b = parseInt(hex.substring(4, 6), 16);
      document.documentElement.style.setProperty('--primary-glow', `rgba(${r}, ${g}, ${b}, 0.15)`);
    }

    if (tenant.theme?.accent_color) {
      document.documentElement.style.setProperty('--accent', tenant.theme.accent_color);
    }

    // Modo claro/oscuro: se aplica al body para abarcar todo el viewport
    const isLight = tenant.theme?.color_mode === 'light';
    document.body.classList.toggle('light-mode', isLight);

    return () => {
      document.body.classList.remove('light-mode');
    };
  }, [tenant]);
}
