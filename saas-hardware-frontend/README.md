# saas-hardware-frontend

SPA en **React 19 + Vite + TypeScript** del proyecto [Hardware Catalog SaaS](../README.md). Contiene dos experiencias en una sola aplicación:

- **Catálogo público por tienda** — la vitrina que ven los clientes de cada tenant, con branding y tema propios.
- **Panel de administración** (`/dashboard`) — donde cada tienda gestiona productos, categorías, pedidos, reseñas, páginas y su configuración.

## Stack

- React 19 · Vite · TypeScript
- [React Router 7](https://reactrouter.com) — enrutado (slug de tienda en la URL o dominio personalizado)
- [TanStack Query](https://tanstack.com/query) — datos de servidor y caché
- [Zustand](https://zustand-demo.pmnd.rs) — estado global (sesiones, carrito, tenant)
- Axios con interceptores (token Bearer + header `X-Tenant`)
- lucide-react (iconos) · react-hot-toast (notificaciones)

## Puesta en marcha

```bash
npm install
npm run dev      # http://localhost:5173
```

Requiere la [API](../saas-hardware-api/README.md) corriendo (por defecto en `http://localhost:8000`).

### Variables de entorno (`.env`)

| Variable | Uso | Por defecto |
|---|---|---|
| `VITE_API_URL` | URL base de la API | `http://localhost:8000/api` |
| `VITE_TURNSTILE_SITEKEY` | Site key de Cloudflare Turnstile para el widget anti-bot de reseñas | clave de prueba de Turnstile |

### Scripts

```bash
npm run dev       # Servidor de desarrollo con HMR
npm run build     # tsc -b + build de producción en dist/
npm run preview   # Sirve el build de producción
npm run lint      # ESLint
```

## Rutas

**Públicas** (con prefijo de slug, o sin él cuando la tienda usa dominio personalizado):

| Ruta | Página |
|---|---|
| `/{slug}` | Catálogo: búsqueda, filtros por categoría/disponibilidad/especificaciones, comparador de productos, carrito |
| `/{slug}/product/:id` | Ficha de producto: galería, especificaciones, relacionados, reseñas con Turnstile |
| `/{slug}/builder` | Armador de PC con verificación de compatibilidad |
| `/{slug}/p/:pageSlug` | Página informativa de la tienda |
| `/` | Catálogo por resolución dinámica de dominio personalizado |

**Privadas** (`/dashboard`, requieren sesión — `PrivateRoute`):

| Ruta | Página |
|---|---|
| `/login` | Acceso de administradores |
| `/dashboard` | Resumen con métricas de la tienda |
| `/dashboard/products` | Productos: CRUD, galería, ofertas, importación CSV, acciones masivas, drag & drop |
| `/dashboard/categories` | Categorías con reordenamiento drag & drop |
| `/dashboard/orders` | Pedidos: estados, stock y notificaciones por WhatsApp |
| `/dashboard/reviews` | Moderación de reseñas |
| `/dashboard/pages` | Páginas informativas |
| `/dashboard/settings` | Branding, tema, textos del hero, dominio, favicon |

## Estructura

```
src/
├── api/            # Cliente Axios (interceptores) y funciones por recurso:
│                   # auth, tenant, products, categories, orders, reviews,
│                   # pages, dashboard, public
├── router/         # Definición de rutas y PrivateRoute
├── pages/
│   ├── auth/       # LoginPage
│   ├── dashboard/  # Overview, Products, Categories, Orders, Reviews,
│   │               # Pages, Settings (layout en DashboardPage)
│   └── public/     # Catalog, ProductDetail, PcBuilder, PageDetail
├── components/
│   ├── public/     # CartDrawer (carrito), CustomerAccountModal (cuenta cliente)
│   └── ui/         # CategoryIcon, ImageSourceField (URL o archivo)
├── stores/         # Zustand: authStore (admin), customerAuthStore (cliente),
│                   # cartStore (carrito por tienda), tenantStore
├── hooks/          # useTenantBranding (título, favicon, meta/OG dinámicos),
│                   # useTenantTheme (colores y modo claro/oscuro)
└── types/          # Tipos compartidos de la API
```

## Notas

- El **branding por tenant** (colores, tema claro/oscuro, título y favicon de la pestaña, etiquetas meta/Open Graph) se aplica en tiempo de ejecución con los hooks `useTenantBranding` y `useTenantTheme` a partir de la configuración que devuelve la API.
- El **carrito** vive en `cartStore` (Zustand) y al confirmar genera un pedido en la API y abre WhatsApp con el resumen.
- Los clientes finales pueden crear cuenta por tienda (favoritos e historial de pedidos) vía `CustomerAccountModal` y `customerAuthStore`, con sesión independiente de la del administrador.
