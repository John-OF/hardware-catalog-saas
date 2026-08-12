import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import ErrorScreen from './ErrorScreen';

/**
 * Red de seguridad para errores fuera del router (PUB-5).
 *
 * Envuelve al `RouterProvider`, así que cubre lo que reviente en los providers
 * o en el propio arranque. Los errores lanzados DENTRO de una ruta no llegan
 * aquí: React Router los intercepta y los resuelve con el `errorElement` del
 * router (`RouteErrorFallback`). Hacen falta las dos capas.
 *
 * Tiene que ser un componente de clase: React no ofrece equivalente en hooks
 * para `componentDidCatch`.
 */
interface Props {
  children: ReactNode;
}

interface State {
  error: Error | null;
}

export default class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Punto único donde enganchar Sentry o equivalente cuando se cablee (TEC-5).
    console.error('Error no controlado en la interfaz:', error, info.componentStack);
  }

  render() {
    if (!this.state.error) return this.props.children;

    return <ErrorScreen detail={import.meta.env.DEV ? this.state.error.message : null} />;
  }
}
