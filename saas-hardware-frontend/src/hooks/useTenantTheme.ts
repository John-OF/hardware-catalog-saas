import { useLayoutEffect } from 'react';
import type { Tenant } from '../types';
import { applyTheme, clearTheme, rememberTheme } from '../utils/theme';

/**
 * Aplica los estilos y colores dinámicos del tenant al documento.
 * Maneja la inyección de variables CSS, la tipografía y el modo claro/oscuro.
 *
 * El cómo vive en `utils/theme`, junto a la semilla que se aplica antes
 * de montar React (UI-2): son la misma operación y separarlas fue justo lo que
 * dejó a la página informativa sin la mitad de su tema en su día.
 *
 * `useLayoutEffect` y no `useEffect`: al navegar entre pantallas de la misma
 * tienda el tenant ya está en la caché de react-query, así que el componente
 * monta con los datos y con `useEffect` se colaba un frame con el tema
 * anterior. No arregla la carga en frío —ahí lo que se espera es la red, y de
 * eso se encarga la semilla—, pero sí el salto al navegar.
 */
export function useTenantTheme(tenant?: Tenant | null) {
  useLayoutEffect(() => {
    if (!tenant) return;

    applyTheme({
      primary_color: tenant.primary_color,
      accent_color: tenant.theme?.accent_color,
      color_mode: tenant.theme?.color_mode,
      neutral: tenant.theme?.neutral,
      radius: tenant.theme?.radius,
      card_style: tenant.theme?.card_style,
      density: tenant.theme?.density,
      font: tenant.theme?.font,
      font_heading: tenant.theme?.font_heading,
      font_body: tenant.theme?.font_body,
    });

    // Para la próxima visita a esta tienda: con esto la semilla ya tiene de
    // dónde tirar y el salto de color desaparece.
    rememberTheme(tenant);

    // Se devuelven las variables a los valores de index.css: si no, al pasar
    // del catálogo público al panel el dashboard heredaría la fuente y el
    // color de la última tienda visitada.
    return clearTheme;
  }, [tenant]);
}
