import { createBrowserRouter } from 'react-router-dom';
import PrivateRoute from './PrivateRoute';
import LoginPage from '../pages/auth/LoginPage';
import DashboardPage from '../pages/dashboard/DashboardPage';
import ProductsPage from '../pages/dashboard/ProductsPage';
import CategoriesPage from '../pages/dashboard/CategoriesPage';
import SettingsPage from '../pages/dashboard/SettingsPage';
import OrdersPage from '../pages/dashboard/OrdersPage';
import CatalogPage from '../pages/public/CatalogPage';
import ProductDetailPage from '../pages/public/ProductDetailPage';
import PcBuilderPage from '../pages/public/PcBuilderPage';

export const router = createBrowserRouter([
  // Rutas públicas
  { path: '/login', element: <LoginPage /> },
  { path: '/:slug', element: <CatalogPage /> },
  { path: '/:slug/product/:id', element: <ProductDetailPage /> },
  { path: '/:slug/builder', element: <PcBuilderPage /> },

  // Rutas privadas (dashboard)
  {
    path: '/dashboard',
    element: <PrivateRoute><DashboardPage /></PrivateRoute>,
    children: [
      { path: 'products',   element: <ProductsPage /> },
      { path: 'categories', element: <CategoriesPage /> },
      { path: 'settings',   element: <SettingsPage /> },
      { path: 'orders',     element: <OrdersPage /> },
    ],
  },

  // Redirigir raíz al login
  { path: '/', element: <LoginPage /> },
]);
