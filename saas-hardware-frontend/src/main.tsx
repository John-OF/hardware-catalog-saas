// El CSS base va PRIMERO, antes de cualquier módulo que arrastre hojas de
// página (TEC-6). Los imports se evalúan en orden, así que si el router entrara
// antes, los .css de las páginas se cargarían delante de index.css y perderían
// todos los empates de especificidad contra el sistema de diseño —justo los que
// ganaban cuando su CSS era un <style> al final del body—. Con este orden, una
// regla de página sigue pisando a la base al empatar, como hasta ahora.
import './index.css';

import { isAxiosError } from 'axios';
import React from 'react';
import ReactDOM from 'react-dom/client';
import { RouterProvider } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import { router } from './router';
import ErrorBoundary from './components/ui/ErrorBoundary';
import { seedStoredTheme } from './utils/theme';

// UI-2: la paleta de la tienda se aplicaba en un efecto, o sea despues de que
// resolviera la peticion del tenant, asi que el primer frame salia con los
// valores por defecto de :root -los oscuros- y saltaba al tema real al llegar
// los datos. Aqui se aplica el de la ultima visita a esa tienda antes de montar
// React, cuando lo hay. Va antes del render a proposito: en cuanto React pinta,
// ya es tarde.
seedStoredTheme();

// AUD-2: con el rate limit del catálogo público ya en el servidor, los
// reintentos por defecto de react-query (3, con backoff) se vuelven un tiro en
// el pie: un 429 dispararía 3 peticiones más por consulta, y una carga del
// catálogo son ~6 consultas. Justo el visitante al que ya se le ha dicho
// "espera" es el que más tráfico generaría.
//
// Ningún 4xx merece reintento: son respuestas del servidor diciendo que la
// petición no va a mejorar repitiéndola. Los 5xx y los fallos de red sí, que es
// para lo que sirve el reintento.
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (failureCount, error) => {
        if (isAxiosError(error)) {
          const status = error.response?.status;
          if (status !== undefined && status >= 400 && status < 500) return false;
        }

        return failureCount < 3;
      },
    },
  },
});

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
