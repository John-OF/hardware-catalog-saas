import { Navigate } from 'react-router-dom';
import { rutaDeSalidaDelPanel, useAuthStore } from '../stores/authStore';

interface Props {
  children: React.ReactNode;
}

export default function PrivateRoute({ children }: Props) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  if (!isAuthenticated) {
    // Y no `/login` a secas (INF-2): al salir del modo soporte se borra el token
    // y este componente, aún montado, se repinta antes de que llegue la
    // navegación a `/platform/tenants` — y la pisaba con `/login`.
    return <Navigate to={rutaDeSalidaDelPanel()} replace />;
  }

  return <>{children}</>;
}
