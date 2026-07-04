import { createBrowserRouter } from 'react-router-dom';
import PrivateRoute from './PrivateRoute';
import LoginPage from '../pages/auth/LoginPage';
import DashboardPage from '../pages/dashboard/DashboardPage';
import ProductsPage from '../pages/dashboard/ProductsPage';
import CategoriesPage from '../pages/dashboard/CategoriesPage';
import SettingsPage from '../pages/dashboard/SettingsPage';
import OrdersPage from '../pages/dashboard/OrdersPage';
import PagesPage from '../pages/dashboard/PagesPage';
import CatalogPage from '../pages/public/CatalogPage';
import ProductDetailPage from '../pages/public/ProductDetailPage';
import PcBuilderPage from '../pages/public/PcBuilderPage';
import PageDetailPage from '../pages/public/PageDetailPage';
import OverviewPage from '../pages/dashboard/OverviewPage';

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
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
    ],
  },

  // Redirigir raíz al catálogo (resolución dinámica de dominios)
  { path: '/', element: <CatalogPage /> },
]);
