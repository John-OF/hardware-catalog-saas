/* eslint-disable react-refresh/only-export-components --
 * Este fichero es la CONFIGURACION del router, no un modulo de componentes: lo
 * unico que exporta es `router`. La regla salta porque los `lazy()` se declaran
 * con nombre en mayuscula y los toma por componentes sueltos.
 *
 * No se pierde nada desactivandola aqui: Fast Refresh ya estaba descartado en
 * este fichero antes de trocear, porque exportar algo que no es un componente
 * (`router`) ya lo desactiva. Y mover las 18 declaraciones a otro fichero solo
 * para callar la regla dejaria la lista de rutas partida en dos sitios.
 */
import { lazy, Suspense } from 'react';
import { createBrowserRouter, Outlet } from 'react-router-dom';
import RouteErrorFallback from '../components/ui/RouteErrorFallback';
import RouteFallback from '../components/ui/RouteFallback';
import PrivateRoute from './PrivateRoute';
import CatalogPage from '../pages/public/CatalogPage';

// AUD-18: hasta aquí no había ni un solo import dinámico, así que quien entraba
// por WhatsApp a ver un catálogo desde el móvil se descargaba también el panel
// entero, el CRUD de productos, la importación CSV y la administración de
// plataforma. Con las páginas del panel siendo la mitad del código de páginas
// del proyecto, eso es la mayor parte del bundle para gente que no va a entrar
// nunca al panel.
//
// EL CATÁLOGO SE QUEDA ESTÁTICO A PROPÓSITO. Es la ruta de entrada de casi todo
// el tráfico: si fuera `lazy`, el navegador tendría que pedir primero el bundle
// de entrada y DESPUÉS el chunk del catálogo, dos viajes en serie justo en la
// pantalla que más importa. Trocear no es gratis, y el objetivo no es tener
// muchos chunks sino que el visitante no pague por lo que no usa.
//
// El resto va bajo demanda. Las de dentro del panel no las pide nadie sin haber
// pasado por el login, y `PrivateRoute` sigue siendo estático a propósito: así
// quien no ha iniciado sesión ni siquiera dispara la descarga del panel.
const ProductDetailPage = lazy(() => import('../pages/public/ProductDetailPage'));
const PcBuilderPage = lazy(() => import('../pages/public/PcBuilderPage'));
const PageDetailPage = lazy(() => import('../pages/public/PageDetailPage'));

const LoginPage = lazy(() => import('../pages/auth/LoginPage'));
const RegisterStorePage = lazy(() => import('../pages/auth/RegisterStorePage'));
const ForgotPasswordPage = lazy(() => import('../pages/auth/ForgotPasswordPage'));
const ResetPasswordPage = lazy(() => import('../pages/auth/ResetPasswordPage'));

const PlatformLoginPage = lazy(() => import('../pages/platform/PlatformLoginPage'));
const PlatformPage = lazy(() => import('../pages/platform/PlatformPage'));

const DashboardPage = lazy(() => import('../pages/dashboard/DashboardPage'));
const OverviewPage = lazy(() => import('../pages/dashboard/OverviewPage'));
const ProductsPage = lazy(() => import('../pages/dashboard/ProductsPage'));
const CategoriesPage = lazy(() => import('../pages/dashboard/CategoriesPage'));
const SettingsPage = lazy(() => import('../pages/dashboard/SettingsPage'));
const OrdersPage = lazy(() => import('../pages/dashboard/OrdersPage'));
const PagesPage = lazy(() => import('../pages/dashboard/PagesPage'));
const ReviewsPage = lazy(() => import('../pages/dashboard/ReviewsPage'));

const NotFoundPage = lazy(() => import('../pages/NotFoundPage'));

export const router = createBrowserRouter([
  {
    // Ruta contenedora sin path: solo existe para colgarle un errorElement que
    // cubra TODAS las rutas hijas. React Router intercepta los errores de una
    // ruta antes de que lleguen a un boundary de React, asi que sin esto el
    // usuario veria la pantalla de desarrollo del router con el stack trace.
    //
    // El Suspense va aqui por el mismo motivo: uno solo, envolviendo el Outlet,
    // cubre todas las rutas perezosas sin repetirlo en cada una. Si un chunk no
    // llega a descargarse (un despliegue nuevo mientras alguien navega, por
    // ejemplo), React lanza el error y lo recoge el errorElement de al lado.
    element: (
      <Suspense fallback={<RouteFallback />}>
        <Outlet />
      </Suspense>
    ),
    errorElement: <RouteErrorFallback />,
    children: [
  { path: '/login', element: <LoginPage /> },
  // Alta de tienda self-service. Va antes que '/:slug' y además 'register' es
  // un slug reservado en el backend, así que no puede chocar con un catálogo.
  { path: '/register', element: <RegisterStorePage /> },
  // Recuperación de contraseña del panel. Como '/register', ambos son slugs
  // reservados en el backend, así que ningún catálogo puede ocupar la ruta.
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/reset-password', element: <ResetPasswordPage /> },
  // Panel del operador del SaaS. 'platform' es slug reservado en el backend,
  // asi que ninguna tienda puede ocupar estas rutas.
  { path: '/platform/login', element: <PlatformLoginPage /> },
  { path: '/platform', element: <PlatformPage /> },
  // Rutas públicas (con prefijo de slug para SaaS)
  { path: '/:slug', element: <CatalogPage /> },
  { path: '/:slug/product/:id', element: <ProductDetailPage /> },
  { path: '/:slug/builder', element: <PcBuilderPage /> },
  { path: '/:slug/p/:pageSlug', element: <PageDetailPage /> },

  // Rutas públicas (en dominios personalizados sin prefijo de slug)
  { path: '/product/:id', element: <ProductDetailPage /> },
  { path: '/builder', element: <PcBuilderPage /> },
  { path: '/p/:pageSlug', element: <PageDetailPage /> },

  // Rutas privadas (dashboard)
  {
    path: '/dashboard',
    element: <PrivateRoute><DashboardPage /></PrivateRoute>,
    children: [
      { index: true,        element: <OverviewPage /> },
      { path: 'products',   element: <ProductsPage /> },
      { path: 'categories', element: <CategoriesPage /> },
      { path: 'settings',   element: <SettingsPage /> },
      { path: 'orders',     element: <OrdersPage /> },
      { path: 'pages',      element: <PagesPage /> },
      { path: 'reviews',    element: <ReviewsPage /> },
    ],
  },

  // Redirigir raíz al catálogo (resolución dinámica de dominios)
  { path: '/', element: <CatalogPage /> },

  // Cualquier otra cosa: 404 explícito en vez de pantalla en blanco (PUB-5).
  // Va al final a propósito, después de las rutas con prefijo de slug.
  { path: '*', element: <NotFoundPage /> },
    ],
  },
]);
