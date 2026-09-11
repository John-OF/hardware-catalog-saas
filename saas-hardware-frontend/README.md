# saas-hardware-frontend

Panel de administración y catálogo público de **Hardware Catalog SaaS**, en una sola SPA.
React 19 · Vite · TypeScript · React Router 7 · TanStack Query · Zustand.

**La documentación vive en el [README de la raíz](../README.md)**, que es el único sitio donde se
mantiene: rutas, estructura, claves de diseño (incluidas las reglas de CSS por página y componente)
y convenciones.

Lo mínimo para arrancar:

```bash
npm install
npm run dev       # http://localhost:5173
```

| Variable | Uso | Por defecto |
|---|---|---|
| `VITE_API_URL` | URL base de la API | `http://localhost:8000/api` |
| `VITE_TURNSTILE_SITEKEY` | Site key de Turnstile | sin ella, el formulario de reseñas queda deshabilitado |

```bash
npm run build     # tsc -b + build de producción en dist/
npm run preview   # Sirve el build
npm run lint      # ESLint
```
