// El CSS base va PRIMERO, antes de cualquier módulo que arrastre hojas de
// página (TEC-6). Los imports se evalúan en orden, así que si el router entrara
// antes, los .css de las páginas se cargarían delante de index.css y perderían
// todos los empates de especificidad contra el sistema de diseño —justo los que
// ganaban cuando su CSS era un <style> al final del body—. Con este orden, una
// regla de página sigue pisando a la base al empatar, como hasta ahora.
import './index.css';

import React from 'react';
import ReactDOM from 'react-dom/client';
import { RouterProvider } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import { router } from './router';
import ErrorBoundary from './components/ui/ErrorBoundary';

const queryClient = new QueryClient();

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
        <Toaster position="top-right" />
      </QueryClientProvider>
    </ErrorBoundary>
  </React.StrictMode>
);
