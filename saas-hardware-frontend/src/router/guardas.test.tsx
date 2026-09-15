import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it } from 'vitest';
import PrivateRoute from './PrivateRoute';
import SoloAdmin from './SoloAdmin';
import { useAuthStore } from '../stores/authStore';
import { PLATFORM_TOKEN_KEY } from '../api/platform';
import type { User } from '../types';

const usuario = (role: User['role']): User => ({ id: 'u-1', name: 'Alguien', email: 'a@b.test', role, is_active: true });

const entrarEn = (ruta: string) =>
  render(
    <MemoryRouter initialEntries={[ruta]}>
      <Routes>
        <Route path="/login" element={<p>Login de tiendas</p>} />
        <Route path="/platform/tenants" element={<p>Panel de plataforma</p>} />
        <Route path="/dashboard" element={<PrivateRoute><p>Resumen</p></PrivateRoute>} />
        <Route
          path="/dashboard/settings"
          element={<PrivateRoute><SoloAdmin><p>Configuración</p></SoloAdmin></PrivateRoute>}
        />
      </Routes>
    </MemoryRouter>,
  );

describe('PrivateRoute', () => {
  beforeEach(() => {
    useAuthStore.setState({ token: null, user: null, isAuthenticated: false, soporte: false });
  });

  it('sin sesión manda al login de tiendas', () => {
    entrarEn('/dashboard');

    expect(screen.getByText('Login de tiendas')).toBeInTheDocument();
    expect(screen.queryByText('Resumen')).not.toBeInTheDocument();
  });

  it('sin sesión de tienda pero con la de plataforma viva, devuelve al operador a su panel (INF-2)', () => {
    sessionStorage.setItem(PLATFORM_TOKEN_KEY, 'tok-plataforma');
    entrarEn('/dashboard');

    expect(screen.getByText('Panel de plataforma')).toBeInTheDocument();
  });

  it('con sesión deja pasar', () => {
    useAuthStore.setState({ token: 'tok', isAuthenticated: true, user: usuario('admin') });
    entrarEn('/dashboard');

    expect(screen.getByText('Resumen')).toBeInTheDocument();
  });
});

describe('SoloAdmin (FUN-4)', () => {
  it('un admin ve la pantalla', () => {
    useAuthStore.setState({ token: 'tok', isAuthenticated: true, user: usuario('admin') });
    entrarEn('/dashboard/settings');

    expect(screen.getByText('Configuración')).toBeInTheDocument();
  });

  it('un colaborador va al resumen', () => {
    useAuthStore.setState({ token: 'tok', isAuthenticated: true, user: usuario('staff') });
    entrarEn('/dashboard/settings');

    expect(screen.getByText('Resumen')).toBeInTheDocument();
    expect(screen.queryByText('Configuración')).not.toBeInTheDocument();
  });

  it('mientras no se sabe el rol no pinta nada ni redirige (al recargar, antes de getMe)', () => {
    useAuthStore.setState({ token: 'tok', isAuthenticated: true, user: null });
    entrarEn('/dashboard/settings');

    expect(screen.queryByText('Configuración')).not.toBeInTheDocument();
    expect(screen.queryByText('Resumen')).not.toBeInTheDocument();
  });
});
